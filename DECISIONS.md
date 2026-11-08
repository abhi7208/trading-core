# Design decisions

## 1. Locking strategy

**Pessimistic row locks (`SELECT … FOR UPDATE`) inside short MySQL transactions, for every money
operation.** Money operations are read-check-write ("is there enough? then subtract"), and two
requests must never both pass the check. A row lock makes the second request wait until the first
commits, then it re-reads the new balance. Optimistic locking (version columns + retry) would also
be correct, but under exactly the contention the brief describes (several devices, retries, many
workers on one wallet) it degrades into retry storms; waiting a few milliseconds on a lock is
cheaper. Redis locks were rejected for money: they live outside the database transaction, so a
crash between "Redis lock" and "commit" can leave them inconsistent.

| Operation | Rows locked (in this order) | Why it is safe |
|---|---|---|
| Place BUY | wallet | Balance checked and reserved while locked. |
| Place SELL | position | Quantity checked and reserved while locked. |
| Cancel / expire / reject / fail (`CloseOrder`) | order → wallet *or* position | Proceeds only if the locked order is still `PENDING`. |
| Settle (`SettleOrder`) | order → wallet → position | Same `PENDING` re-check under the order lock. |

**Global lock order: order → wallet → position** (enforced in `RowLocker`). Every path takes locks
in this order, so a cycle of waits, which is what a deadlock is, cannot form. As extra protection,
every transaction uses `DB::transaction(..., attempts: 3)`, which retries on a MySQL deadlock.

**Cancel vs fill race.** Both start by locking the same order row. The first one changes the status;
the second then sees a final status and stops (cancel returns `409 ORDER_NOT_CANCELLABLE`; the fill
returns `skipped`). The reservation is therefore released or spent exactly once. This is proven by
a real parallel-process test (12 rounds, both sides win some rounds, zero double settlements).

**Idempotency, three layers.** (1) `idempotency_keys` stores the request hash and the exact response
body; a replay returns it byte-for-byte (stored as TEXT because MySQL's JSON type reorders keys).
(2) `UNIQUE(user_id, idempotency_key)` on orders: two identical requests at the same instant serialize
on the wallet lock, the second hits the unique index, its transaction rolls back (nothing reserved),
and it replays the winner's response. (3) Settlement: status re-check under lock, plus
`UNIQUE(executions.order_id)` as a database backstop. Requests rejected by validation or for
insufficient funds do not consume the key, so a client can fix the problem and retry.

**No Repository layer.** Actions call Eloquent directly. A repository would add an interface per model without buying anything here: there is one database, and the tests already run against real MySQL, so there is nothing to swap or mock. The one data-access rule that matters, how rows are locked, lives in `RowLocker`.

**Database as the last line of defence.** CHECK constraints forbid negative balances and
quantities; MySQL triggers refuse any `UPDATE`/`DELETE` on `ledger_entries`.

## 2. Money precision and rounding

- All money, prices and quantities are `DECIMAL(20,8)`. In PHP they are `App\Support\Decimal`, an
  immutable wrapper around `brick/math` `BigDecimal`. It cannot be built from a float, and the
  Eloquent cast refuses floats. The API accepts and returns decimals **as strings**.
- Asset precision is validated on input (`price_precision`, `quantity_precision`).
- Every multiplication states its rounding: **what the trader pays rounds up** (reservations, buy
  cost), **what the trader receives rounds down** (sell proceeds). The firm never pays out a
  fraction it did not receive. Average cost uses half-up.
- MARKET BUY reservation = latest price × (1 + 5% collar), rounded up. If the fill price exceeds what
  was reserved, the order is `REJECTED (PRICE_MOVED_BEYOND_COLLAR)` rather than overspending. A MARKET
  BUY with no fresh price is refused at placement (`422 NO_FRESH_PRICE`) because it cannot be sized.
- **Ledger model:** each entry has a `bucket` (`AVAILABLE` or `RESERVED`), a signed amount and the
  bucket's `balance_after`. A reservation is two entries (−X available, +X reserved). So **both**
  balances can be rebuilt from the ledger alone; `php artisan ledger:verify` does exactly that.
  Only `LedgerWriter` changes balances, and it always writes the entry in the same transaction.

## 3. Indexes

EXPLAIN output for each is in the appendix (MySQL 8.0.46, 50k orders, 25k executions, 204k ticks).

| Index | Query it serves | Plan |
|---|---|---|
| `wallets (user_id, currency)` unique | lock "my USD wallet" | const |
| `positions (user_id, asset_id)` unique | lock a position; INSERT IGNORE on first buy | const |
| `orders (user_id, idempotency_key)` unique | idempotency guarantee | — |
| `orders (user_id, status)` | `GET /orders?status=` + cursor `ORDER BY id DESC` (InnoDB appends `id`) | ref, backward index scan, no filesort |
| `orders (user_id, asset_id)` | `GET /orders?asset=` | ref, backward index scan |
| `orders (asset_id, status, side, limit_price)` | tick sweep: fillable PENDING orders | range on all 4 columns (key_len 87) |
| `orders (status, expires_at)` | `orders:expire` | range, index only |
| `executions (order_id)` unique | one fill per order (double-settlement backstop) | — |
| `executions (executed_at, asset_id, notional)` | 24h filled count and top-5 volume | range, **covering** (index only) |
| `market_ticks (asset_id, recorded_at, price)` | first price in the 24h window per asset | range, **covering** |
| `ledger_entries (wallet_id)`, `(order_id)` | wallet history, "money moved for order X" | — |
| `audit_logs (subject_type, subject_id)`, `(actor_id, created_at)` | audit trail by order / by user | — |

Two indexes changed after looking at real EXPLAIN plans:
- The first draft used a correlated subquery per asset for the 24h open price. `EXPLAIN ANALYZE`
  showed MySQL reading and sorting ~40,000 rows per asset (~300 ms). Rewritten as a `UNION ALL` of
  per-asset `ORDER BY recorded_at LIMIT 1` lookups, and `price` added to the index to make it
  covering: **~1.5 ms**, index only.
- An `orders (status, filled_at)` index was ignored by the optimizer (it used only `status`, ~25k
  rows). With no partial fills, "filled orders" equals "executions", so the count now uses the
  executions covering index and that orders index was dropped (one fewer index to maintain on every
  order write).

## 4. Cache freshness (market summary)

- Cached in Redis for at most 60 s.
- **Stampede protection:** only the process holding a Redis lock rebuilds; everyone else is served the
  previous copy meanwhile (stale-while-revalidate). On a completely cold cache, others wait up to 5 s
  for that one rebuild.
- **Freshness without thrash:** an `OrderFilled` listener does not delete the cache; it writes one
  timestamp, `dirty_at`. A cached copy built after `dirty_at`, or younger than 2 s, counts as fresh.
  Under a fill storm the summary is rebuilt **at most once every 2 s**, and it always catches up after
  the last fill. Deleting on every fill would rebuild 1,000 times per second at 1,000 fills per second.
- Trade-off: the summary can be up to ~2 s behind fills (and the 24h window edge up to 60 s old).
  For a dashboard that is acceptable; anything that needs exact numbers reads the database.
- Current prices come from Redis (always live); only the 24h-ago price and aggregates come from MySQL.

## 5. Price feed and order re-checking

- `market:simulate` buffers ticks and writes them every 250 ms in multi-row `INSERT`s (up to 1,000
  rows each), then updates all latest prices in Redis with one `HMSET`.
- Matching never reads `market_ticks`; it reads Redis.
- After each batch it dispatches **one** `SweepAssetOrdersJob` per asset that moved. That job is
  `ShouldBeUniqueUntilProcessing`, so while one sweep per asset is queued, more are dropped: 50 ticks/s
  become a handful of sweeps. The sweep queries only orders on the fillable side of the new price
  (`BUY limit_price >= P`, `SELL limit_price <= P`) via the matching index, and queues
  `ProcessOrderJob` only for those. Duplicate jobs are harmless because settlement is idempotent.
- **Growing `market_ticks`:** partition by `RANGE` on `recorded_at` (one partition per day; the primary
  key becomes `(id, recorded_at)`, which is why the table has no foreign key), drop partitions older
  than N days with an instant `ALTER TABLE … DROP PARTITION` instead of slow `DELETE`s, and roll old
  ticks up into 1-minute OHLC candles before dropping them.

## 6. Known gaps and shortcuts

- **Larastan was not run locally**: in the environment used to build this, PHPStan's download kept
  failing, so level 6 is configured and enforced in CI only. Expect to fix a few findings on first CI run.
- No partial fills and no fees (`fee` is always 0). Both would need `executions.order_id` to stop being
  unique and a `filled_quantity` column on orders.
- `PROCESSING` exists in the enum but is unused: settlement is a single transaction under a row lock,
  so no other process can observe an intermediate state. It would be needed if settlement called an
  external venue.
- Positions have no ledger of their own; holdings are reconstructable from executions plus the seeded
  opening balances. With another day I would add a `position_movements` ledger mirroring the cash one.
- The 24h "open" price is the first tick inside the window, not an exact price at T−24h.
- Idempotency keys never expire; they should be pruned after e.g. 24–72 h.
- Expiry runs every minute; settlement also refuses expired orders, so nothing fills late, but a
  reservation can stay locked up to a minute after expiry.
- The Docker setup was written for this submission but could not be exercised where it was built (no
  Docker daemon); the same commands were run directly against MySQL 8 and Redis.

With another day: partial fills and fees, a position ledger, Horizon with separate supervisors,
an OpenAPI spec, and load tests that run `ledger:verify` at the end.

## 7. At 100× the load (5,000 ticks/s, 1M orders/day)

1M orders/day is only ~12 per second on average (peaks maybe 500/s); the harder part is ticks.
- **Ticks:** move history out of MySQL into a time-series store (TimescaleDB or ClickHouse) fed from
  a stream (Redis Streams / Kafka). Keep only latest prices and candles hot.
- **Matching:** replace SQL sweeps with an in-memory order book per asset (Redis sorted sets keyed by
  limit price, or a small matching service), so a tick finds crossing orders in O(log n). Shard
  workers by asset so one asset's orders are processed by one consumer, in order.
- **Contention:** a single hot wallet serializes on its row lock; that is correct, and per-user
  serialization is fine at this scale. Shard the database by user if a single primary becomes the limit.
- **Reads:** serve order history and the summary from read replicas; pre-aggregate volume into
  per-minute counters updated on fill instead of scanning executions.
- **Ops:** Horizon with auto-scaling per queue, partitioned `orders` / `ledger_entries` by month,
  and continuous reconciliation (`ledger:verify`) as an alert.

---

## Appendix: EXPLAIN output

MySQL 8.0.46. Data: 50,006 orders, 25,006 executions, 203,605 market ticks, 5 assets; `ANALYZE TABLE`
run first. Queries are the exact SQL the application issues (captured from the query log).

**Market summary — filled orders in 24h**
```sql
select count(*) as `aggregate` from `executions` where `executed_at` >= '2026-11-04 16:04:56'
```
```
type: range | key: executions_executed_at_asset_id_notional_index | key_len: 8 | rows: 12525 | Extra: Using where; Using index
```

**Market summary — top 5 assets by notional volume**
```sql
select `asset_id`, SUM(notional) AS volume from `executions` where `executed_at` >= '2026-11-04 16:04:56'
group by `asset_id` order by `volume` desc limit 5
```
```
type: range | key: executions_executed_at_asset_id_notional_index | key_len: 8 | rows: 12525 | Extra: Using where; Using index; Using temporary; Using filesort
```
The temporary table and sort are over the grouped result (one row per asset, 5 rows), not over executions.

**Market summary — first price in the 24h window, all assets**
```sql
(select `asset_id`, `price` from `market_ticks` where `asset_id` = 3 and `recorded_at` >= '2026-11-04 16:04:56'
 order by `recorded_at` asc limit 1) union all ( ...same for assets 1, 2, 4, 5... )
```
```
PRIMARY | market_ticks | range | key: market_ticks_asset_time_price_index | key_len: 16 | Extra: Using where; Using index
UNION   | market_ticks | range | key: market_ticks_asset_time_price_index | key_len: 16 | Extra: Using where; Using index   (x4)
EXPLAIN ANALYZE: actual time 0.26..1.5 ms for all five
```

**Tick sweep — BUY candidates that can fill at price 900**
```sql
select `id` from `orders` where `asset_id` = 2 and `status` = 'PENDING' and `side` = 'BUY'
and `type` = 'LIMIT' and `limit_price` >= '900.00000000'
```
```
type: range | key: orders_asset_id_status_side_limit_price_index | key_len: 87 | rows: 1 | Extra: Using index condition; Using where
```

**GET /orders?status=PENDING (cursor page)**
```sql
select * from `orders` where `user_id` = 1 and `status` = 'PENDING' order by `id` desc limit 21
```
```
type: ref | key: orders_user_id_status_index | key_len: 58 | rows: 1666 | Extra: Backward index scan
```

**GET /orders?asset=AAPL**
```sql
select * from `orders` where `user_id` = 1 and `asset_id` = 3 order by `id` desc limit 21
```
```
type: ref | key: orders_user_id_asset_id_index | key_len: 16 | rows: 3334 | Extra: Backward index scan
```
(`rows` is the optimizer's estimate for the whole range; with `LIMIT 21` and the backward scan it stops after 21.)

**orders:expire**
```sql
select `id` from `orders` where `status` = 'PENDING' and `expires_at` <= '2026-11-05 16:04:56' order by `id` asc limit 500
```
```
type: range | key: orders_status_expires_at_index | key_len: 55 | rows: 1 | Extra: Using where; Using index; Using filesort
```
The filesort is over the expired rows only (bounded by `LIMIT 500` per chunk).
