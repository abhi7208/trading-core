# Trading Core — order management API

Order-management core for a proprietary trading firm: traders place LIMIT and MARKET orders over a
REST API, a simulated price feed streams ticks, and queue workers fill orders when prices allow.
The focus is **correctness of money under concurrency**: no double-spend, no negative balance,
no double settlement, every balance auditable from an append-only ledger.

Laravel 13 · PHP 8.3 · MySQL 8 · Redis 7 · Sanctum · brick/math · PHPUnit · Pint · Larastan (level 6)

The design and its trade-offs are in **[DECISIONS.md](DECISIONS.md)**.

---

## 1. Setup (one command)

Requires Docker.

```bash
make setup
```

This builds the image, starts MySQL, Redis, the API, 3 queue workers and the scheduler, then runs
the migrations and seeders. The API is on **http://localhost:8000**.

Without `make` (the same steps):

```bash
docker compose build
docker compose up -d mysql redis
docker compose run --rm --no-deps app composer install --no-interaction
docker compose run --rm --no-deps app sh -c '[ -f .env ] || cp .env.example .env'
docker compose run --rm --no-deps app php artisan key:generate
docker compose run --rm app php artisan migrate:fresh --seed --force
docker compose up -d
```

### Seeded data

5 assets (`BTC-USD`, `ETH-USD`, `AAPL`, `TSLA`, `XAU-USD`), 24 hours of price history, a few
filled demo trades, and 3 traders:

| Trader | API token (Bearer) | USD funded | Opening holding |
|---|---|---|---|
| alice@example.com | `alice-demo-token-a1b2c3d4e5f6` | 100,000 | 1.5 BTC-USD |
| bob@example.com | `bob-demo-token-f6e5d4c3b2a1` | 50,000 | 100 AAPL |
| carol@example.com | `carol-demo-token-0a9b8c7d6e5f` | 10,000 | 10 ETH-USD |

All three passwords are `password`.

## 2. Running the moving parts

| What | Command (inside Docker: prefix with `docker compose exec app`) | In docker-compose |
|---|---|---|
| API | `php artisan serve` | `app` service |
| Queue workers | `php artisan queue:work redis --queue=orders,default` | `worker` service, 3 replicas |
| Scheduler (order expiry) | `php artisan schedule:work` | `scheduler` service |
| Price simulator | `php artisan market:simulate --rate=50` | `make feed` |
| Ledger audit | `php artisan ledger:verify` | — |

`market:simulate` options: `--rate` (total ticks per second, default 50), `--duration` (seconds,
0 = forever), `--flush` (batch interval in ms, default 250).

The `orders` queue is listed first so settlement always runs before audit writes on `default`.

## 3. Tests

The suite runs against **MySQL** (database `trading_test`) and Redis (DBs 14 and 15).

```bash
make test               # unit + feature tests
make test-concurrency   # real parallel processes
```

or directly:

```bash
php artisan test --testsuite=Unit,Feature
php artisan test --testsuite=Concurrency
vendor/bin/pint --test
vendor/bin/phpstan analyse
```

### The concurrency tests

`tests/Concurrency/ParallelOrdersTest.php` starts **separate OS processes** with Laravel's
`Process::pool`. Each runs `tests/Concurrency/worker.php`, which boots its own copy of the app
(own DB connection), waits for a shared start time, then acts. The tests prove:

1. **20 simultaneous BUYs against a wallet that affords 5** → exactly 5 accepted, 15 refused with
   `INSUFFICIENT_FUNDS`, balance never below zero, ledger reconciles. The test also checks the
   processes really overlapped in time.
2. **10 simultaneous requests with the same Idempotency-Key** → exactly one order; the other 9 get
   the identical replayed response.
3. **Cancel racing a fill, 12 rounds** → every round has exactly one winner and the reservation is
   released or spent exactly once. Alternating head starts make both sides win some rounds.

These tests use `DatabaseTruncation` instead of `RefreshDatabase`, because child processes cannot
see data inside the test's uncommitted transaction.

Sample output:

```
20 parallel BUYs: 5 accepted, 15 refused, 20 processes overlapped
cancel vs fill, 12 rounds: fill won 8, cancel won 4, double-settlements 0
```

CI (`.github/workflows/ci.yml`) runs Pint, Larastan and both suites on real MySQL 8 and Redis services.

## 4. API

All endpoints need `Authorization: Bearer <token>` and `Accept: application/json`.
Decimals are **sent and returned as strings** (`"0.5"`), never JSON numbers, so no float ever
touches money. Errors always look like:

```json
{ "error": { "code": "INSUFFICIENT_FUNDS", "message": "Not enough available balance for this order.",
             "details": { "required": "250.00000000", "available": "100.00000000" } } }
```

Every response carries an `X-Correlation-ID` header (send your own to trace a request into its
jobs and audit entries). A ready-made collection is in [`requests.http`](requests.http).

```bash
TOKEN=alice-demo-token-a1b2c3d4e5f6
API=http://localhost:8000/api/v1
```

**Place an order** → `202 Accepted`. The `Idempotency-Key` header is required.

```bash
curl -X POST $API/orders -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  -H "Content-Type: application/json" -H "Idempotency-Key: 7d3c1d2e-order-1" \
  -d '{"asset":"BTC-USD","side":"BUY","type":"LIMIT","quantity":"0.01","limit_price":"60000.00"}'

# MARKET order: no limit_price. Optional for LIMIT: "expires_at":"2026-12-01T00:00:00Z"
curl -X POST $API/orders -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  -H "Content-Type: application/json" -H "Idempotency-Key: 7d3c1d2e-order-2" \
  -d '{"asset":"BTC-USD","side":"SELL","type":"MARKET","quantity":"0.1"}'
```

Sending the same key and body again returns the original response with `Idempotent-Replayed: true`.
The same key with a different body returns `409 IDEMPOTENCY_KEY_REUSED`.

**List my orders** (cursor pagination, newest first; filters `status`, `asset`, `per_page` ≤ 100):

```bash
curl "$API/orders?status=PENDING&asset=BTC-USD&per_page=20" -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
# next page: add &cursor=<meta.next_cursor>
```

**Show one order with its executions:**

```bash
curl $API/orders/{order-uuid} -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
```

**Cancel a PENDING order** (releases its reservation; `409` if it already filled or was cancelled):

```bash
curl -X DELETE $API/orders/{order-uuid} -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
```

**Wallet** (available and reserved cash, and positions):

```bash
curl $API/wallet -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
```

**Market summary** (last 24h: filled orders, top 5 assets by notional volume, latest price and
24h change per asset; cached up to 60s):

```bash
curl $API/market/summary -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
```

## 5. Project map

```
app/
  Actions/          PlaceOrder, CancelOrder, CloseOrder, SettleOrder    <- business rules
  Services/         LedgerWriter, RowLocker, OrderMatcher, PriceStore, MarketSummary
  Support/Decimal   exact decimal value object (wraps brick/math)
  Data/             typed DTOs passed between layers
  Enums/            OrderSide, OrderType, OrderStatus, LedgerBucket, LedgerEntryType, ...
  Jobs/             ProcessOrderJob (orders queue), SweepAssetOrdersJob
  Events/ Listeners/  OrderFilled|Rejected|Cancelled|Failed -> audit log, summary freshness
  Http/             thin controllers, Form Requests, API Resources, correlation-id middleware
  Console/Commands  market:simulate, orders:expire, ledger:verify
database/migrations  schema with CHECK constraints and append-only ledger triggers
tests/Unit           money arithmetic, matching rules
tests/Feature        every endpoint, settlement, failure paths, price feed, summary
tests/Concurrency    real parallel-process tests
```

Stretch goal implemented: **structured logging with a correlation ID** that follows a request
into its jobs and listeners (`AssignCorrelationId` + Laravel `Context`).

## 6. How AI tools were used

This project was built with Claude (Anthropic) as an AI coding assistant. It generated the
code, tests and documentation from the brief.

The full test suite, including the parallel-process concurrency tests, was run against MySQL 8
and Redis, both directly and inside the Docker setup, and the EXPLAIN plans in DECISIONS.md come
from a real MySQL 8 database. Running the project on my own machine found two issues that were
then fixed: Larastan level 6 findings, and a MySQL server whose default storage engine was
MyISAM (the app now always creates InnoDB tables).
