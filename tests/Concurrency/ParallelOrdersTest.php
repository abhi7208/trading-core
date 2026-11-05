<?php

namespace Tests\Concurrency;

use App\Actions\PlaceOrder;
use App\Data\PlaceOrderData;
use App\Enums\LedgerBucket;
use App\Enums\LedgerEntryType;
use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * REAL parallel processes against REAL MySQL.
 *
 * These tests cannot use RefreshDatabase: that wraps each test in a transaction which is
 * never committed, so the child processes (separate DB connections) would not see the
 * test's data. DatabaseTruncation commits for real and empties the tables between tests.
 *
 * Run:  php artisan test --testsuite=Concurrency
 */
class ParallelOrdersTest extends TestCase
{
    use DatabaseTruncation;

    /**
     * Start one OS process per command, all released at the same instant.
     *
     * @param  list<array{0: string, 1: array<string, mixed>}>  $commands  [action, args]
     * @return list<array<string, mixed>>
     */
    private function runInParallel(array $commands): array
    {
        $startAt = microtime(true) + 3.0; // time for every process to boot Laravel first
        $env = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => (string) config('database.connections.mysql.host'),
            'DB_PORT' => (string) config('database.connections.mysql.port'),
            'DB_DATABASE' => (string) config('database.connections.mysql.database'),
            'DB_USERNAME' => (string) config('database.connections.mysql.username'),
            'DB_PASSWORD' => (string) config('database.connections.mysql.password'),
            'REDIS_HOST' => (string) config('database.redis.default.host'),
            'REDIS_DB' => (string) config('database.redis.default.database'),
            'REDIS_CACHE_DB' => (string) config('database.redis.cache.database'),
            'CACHE_STORE' => 'redis',
            'QUEUE_CONNECTION' => 'null', // the workers only do the one thing they are told
        ];

        $results = Process::pool(function (Pool $pool) use ($commands, $startAt, $env) {
            foreach ($commands as $i => [$action, $args]) {
                $pool->as((string) $i)
                    ->path(base_path())
                    ->env($env)
                    ->timeout(60)
                    ->command([PHP_BINARY, 'tests/Concurrency/worker.php', $action, json_encode([...$args, 'start_at' => $startAt])]);
            }
        })->start()->wait();

        $decoded = [];
        foreach ($results as $result) {
            $this->assertTrue($result->successful(), $result->errorOutput().$result->output());
            $decoded[] = json_decode(trim($result->output()), true, flags: JSON_THROW_ON_ERROR);
        }

        return $decoded;
    }

    /** @param list<array<string, mixed>> $results */
    private function overlapping(array $results): int
    {
        // How many processes were running at the same time as the first one to finish.
        $firstFinish = min(array_column($results, 'finished_at'));

        return count(array_filter($results, fn ($r) => $r['started_at'] <= $firstFinish));
    }

    #[Test]
    public function twenty_simultaneous_buys_against_a_wallet_that_can_afford_five(): void
    {
        $asset = $this->asset(['symbol' => 'BTC-USD']);
        $user = $this->trader('500');   // each order costs 1 x 100 = 100, so exactly 5 fit

        $commands = [];
        for ($i = 0; $i < 20; $i++) {
            $commands[] = ['place', [
                'user_id' => $user->id, 'asset_id' => $asset->id, 'side' => 'BUY',
                'quantity' => '1', 'limit_price' => '100', 'key' => "k-{$i}",
            ]];
        }

        $results = $this->runInParallel($commands);

        $accepted = array_filter($results, fn ($r) => $r['ok']);
        $refused = array_filter($results, fn ($r) => ! $r['ok']);
        fwrite(STDERR, sprintf("\n  20 parallel BUYs: %d accepted, %d refused, %d processes overlapped\n",
            count($accepted), count($refused), $this->overlapping($results)));

        $this->assertCount(5, $accepted);
        $this->assertCount(15, $refused);
        $this->assertSame(['INSUFFICIENT_FUNDS'], array_values(array_unique(array_column($refused, 'code'))));
        $this->assertGreaterThan(1, $this->overlapping($results), 'processes must actually run at the same time');

        $wallet = $this->walletOf($user);
        $this->assertDecimal('0', $wallet->available_balance);
        $this->assertDecimal('500', $wallet->reserved_balance);
        $this->assertSame(5, Order::query()->count());

        // "Never went negative": every intermediate balance in the ledger is >= 0.
        $this->assertFalse(LedgerEntry::query()->where('balance_after', '<', 0)->exists());
        $this->assertLedgerReconciles();
    }

    #[Test]
    public function the_same_idempotency_key_sent_in_parallel_creates_exactly_one_order(): void
    {
        $asset = $this->asset(['symbol' => 'BTC-USD']);
        $user = $this->trader('1000');

        $commands = array_fill(0, 10, ['place', [
            'user_id' => $user->id, 'asset_id' => $asset->id, 'side' => 'BUY',
            'quantity' => '1', 'limit_price' => '100', 'key' => 'retry-storm',
        ]]);

        $results = $this->runInParallel($commands);

        $this->assertCount(10, array_filter($results, fn ($r) => $r['ok']), json_encode($results) ?: '');
        $this->assertCount(1, array_unique(array_column($results, 'order')));  // all got the SAME order
        $this->assertCount(9, array_filter($results, fn ($r) => $r['replayed']));
        $this->assertSame(1, Order::query()->count());
        $this->assertDecimal('900', $this->walletOf($user)->available_balance);
        $this->assertLedgerReconciles();
    }

    #[Test]
    public function cancel_racing_a_fill_has_exactly_one_winner_every_time(): void
    {
        $asset = $this->asset(['symbol' => 'BTC-USD']);
        $user = $this->trader('100000');
        $rounds = 12;
        // Placement must not settle the order itself; only the two racing processes may.
        config(['queue.default' => 'null']);
        $winners = ['filled' => 0, 'cancelled' => 0];

        for ($round = 0; $round < $rounds; $round++) {
            $order = $this->restingBuy($user, $asset->id);   // reserves 100, stays PENDING
            $this->setPrice($asset, '90');                    // now it can fill

            // Alternate a few milliseconds of head start so BOTH outcomes get exercised.
            $cancelDelay = [0, 5, 15, 30][$round % 4];
            [$cancel, $settle] = $this->runInParallel([
                ['cancel', ['order_id' => $order->id, 'user_id' => $user->id, 'delay_ms' => $cancelDelay]],
                ['settle', ['order_id' => $order->id]],
            ]);
            $order->refresh();

            if ($order->status === OrderStatus::Filled) {
                $winners['filled']++;
                $this->assertSame(['ok' => false, 'code' => 'ORDER_NOT_CANCELLABLE'], array_intersect_key($cancel, ['ok' => 1, 'code' => 1]));
                $this->assertSame('filled', $settle['outcome']);
            } else {
                $winners['cancelled']++;
                $this->assertSame(OrderStatus::Cancelled, $order->status);
                $this->assertTrue($cancel['ok']);
                $this->assertSame('skipped', $settle['outcome']);
            }

            // The reservation was released exactly once (or spent exactly once).
            $releasedFromReserved = LedgerEntry::query()
                ->where('order_id', $order->id)
                ->where('bucket', LedgerBucket::Reserved->value)
                ->where('amount', '<', 0)
                ->get()
                ->reduce(fn (Decimal $sum, LedgerEntry $e) => $sum->plus($e->amount->negated()), Decimal::zero());
            $this->assertDecimal('100', $releasedFromReserved, "round {$round}");
            $this->assertSame($order->status === OrderStatus::Filled ? 1 : 0,
                LedgerEntry::query()->where('order_id', $order->id)->where('type', LedgerEntryType::TradeBuy->value)->count());
        }

        fwrite(STDERR, sprintf("\n  cancel vs fill, %d rounds: fill won %d, cancel won %d, double-settlements 0\n",
            $rounds, $winners['filled'], $winners['cancelled']));

        $this->assertGreaterThan(0, $winners['filled'], 'the fill side should win some rounds');
        $this->assertGreaterThan(0, $winners['cancelled'], 'the cancel side should win some rounds');
        $this->assertDecimal('0', $this->walletOf($user)->reserved_balance);
        $this->assertLedgerReconciles();
    }

    private function restingBuy(User $user, int $assetId): Order
    {
        $key = (string) Str::uuid();
        $result = app(PlaceOrder::class)->handle(new PlaceOrderData(
            userId: $user->id, assetId: $assetId, side: OrderSide::Buy, type: OrderType::Limit,
            quantity: Decimal::of('1'), limitPrice: Decimal::of('100'), expiresAt: null,
            idempotencyKey: $key, requestHash: hash('sha256', $key), ipAddress: null,
        ));

        return Order::query()->where('uuid', $result->body['data']['id'])->firstOrFail();
    }
}
