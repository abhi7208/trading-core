<?php

namespace Tests\Feature;

use App\Actions\SettleOrder;
use App\Enums\OrderStatus;
use App\Events\OrderFilled;
use App\Jobs\ProcessOrderJob;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Execution;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class SettlementTest extends TestCase
{
    use RefreshDatabase;

    private Asset $btc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->btc = $this->asset(['symbol' => 'BTC-USD', 'price_precision' => 2, 'quantity_precision' => 4]);
    }

    /** Place an order through the API without running its job yet. */
    private function place(User $user, array $payload): Order
    {
        Queue::fake([ProcessOrderJob::class]);
        $this->placeOrder($user, ['asset' => 'BTC-USD', ...$payload])->assertStatus(202);

        return Order::query()->latest('id')->firstOrFail();
    }

    #[Test]
    public function a_buy_limit_fills_at_the_market_price_and_releases_the_unused_reservation(): void
    {
        $user = $this->trader('1000');
        $order = $this->place($user, ['side' => 'BUY', 'type' => 'LIMIT', 'quantity' => '2', 'limit_price' => '100']);
        $this->setPrice($this->btc, '95.50');

        $this->assertSame('filled', app(SettleOrder::class)->handle($order->id));

        $order->refresh();
        $this->assertSame(OrderStatus::Filled, $order->status);
        $this->assertNotNull($order->filled_at);

        $execution = Execution::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertDecimal('95.50', $execution->price);      // market price, not the limit
        $this->assertDecimal('191', $execution->notional);

        $wallet = $this->walletOf($user);
        $this->assertDecimal('809', $wallet->available_balance); // 1000 - 191 (the extra 9 came back)
        $this->assertDecimal('0', $wallet->reserved_balance);

        $position = $this->positionOf($user, $this->btc);
        $this->assertDecimal('2', $position?->quantity);
        $this->assertDecimal('95.50', $position?->average_cost);
        $this->assertLedgerReconciles();
    }

    #[Test]
    public function a_sell_limit_fills_when_the_price_is_at_or_above_the_limit(): void
    {
        $user = $this->trader('0', [$this->btc->id => '3']);
        $order = $this->place($user, ['side' => 'SELL', 'type' => 'LIMIT', 'quantity' => '2', 'limit_price' => '100']);

        $this->setPrice($this->btc, '99.99');
        $this->assertSame('waiting', app(SettleOrder::class)->handle($order->id));

        $this->setPrice($this->btc, '100.00');
        $this->assertSame('filled', app(SettleOrder::class)->handle($order->id));

        $this->assertDecimal('200', $this->walletOf($user)->available_balance);
        $this->assertDecimal('1', $this->positionOf($user, $this->btc)?->quantity);
        $this->assertDecimal('0', $this->positionOf($user, $this->btc)?->reserved_quantity);
        $this->assertLedgerReconciles();
    }

    #[Test]
    public function average_cost_is_weighted_across_buys(): void
    {
        $user = $this->trader('10000');
        $first = $this->place($user, ['side' => 'BUY', 'type' => 'LIMIT', 'quantity' => '1', 'limit_price' => '100']);
        $this->setPrice($this->btc, '100');
        app(SettleOrder::class)->handle($first->id);

        $second = $this->place($user, ['side' => 'BUY', 'type' => 'LIMIT', 'quantity' => '3', 'limit_price' => '200']);
        $this->setPrice($this->btc, '200');
        app(SettleOrder::class)->handle($second->id);

        // (1 x 100 + 3 x 200) / 4 = 175
        $this->assertDecimal('175', $this->positionOf($user, $this->btc)?->average_cost);
    }

    #[Test]
    public function a_market_order_with_a_stale_price_is_rejected_and_released(): void
    {
        $user = $this->trader('0', [$this->btc->id => '1']);
        $order = $this->place($user, ['side' => 'SELL', 'type' => 'MARKET', 'quantity' => '1']);
        $this->setPrice($this->btc, '100', now()->toImmutable()->subSeconds(31));

        $this->assertSame('rejected', app(SettleOrder::class)->handle($order->id));

        $this->assertSame(OrderStatus::Rejected, $order->refresh()->status);
        $this->assertSame('NO_FRESH_PRICE', $order->failure_reason);
        $this->assertDecimal('1', $this->positionOf($user, $this->btc)?->quantity);
        $this->assertSame(1, AuditLog::query()->where('action', 'order.rejected')->count());
    }

    #[Test]
    public function a_market_buy_is_rejected_if_the_price_jumped_beyond_the_collar(): void
    {
        $user = $this->trader('1000');
        $this->setPrice($this->btc, '100');
        $order = $this->place($user, ['side' => 'BUY', 'type' => 'MARKET', 'quantity' => '1']); // reserves 105
        $this->setPrice($this->btc, '106');

        $this->assertSame('rejected', app(SettleOrder::class)->handle($order->id));

        $this->assertSame('PRICE_MOVED_BEYOND_COLLAR', $order->refresh()->failure_reason);
        $this->assertDecimal('1000', $this->walletOf($user)->available_balance);
        $this->assertNull($this->positionOf($user, $this->btc)); // no side effects at all
        $this->assertLedgerReconciles();
    }

    #[Test]
    public function an_expired_order_is_never_filled(): void
    {
        $user = $this->trader('1000');
        $order = $this->place($user, ['side' => 'BUY', 'type' => 'LIMIT', 'quantity' => '1', 'limit_price' => '100']);
        $this->travel(25)->hours();
        $this->setPrice($this->btc, '50');

        $this->assertSame('expired', app(SettleOrder::class)->handle($order->id));

        $this->assertSame(OrderStatus::Cancelled, $order->refresh()->status);
        $this->assertSame('EXPIRED', $order->failure_reason);
        $this->assertDecimal('1000', $this->walletOf($user)->available_balance);
    }

    // ---- Required: job idempotency --------------------------------------------------

    #[Test]
    public function running_process_order_job_twice_settles_only_once(): void
    {
        $user = $this->trader('1000');
        $order = $this->place($user, ['side' => 'BUY', 'type' => 'LIMIT', 'quantity' => '2', 'limit_price' => '100']);
        $this->setPrice($this->btc, '90');

        $job = new ProcessOrderJob($order->id);
        $job->handle(app(SettleOrder::class));
        $job->handle(app(SettleOrder::class));   // the "same job runs twice" case

        $this->assertSame(1, Execution::query()->where('order_id', $order->id)->count());
        $this->assertSame(1, LedgerEntry::query()->where('order_id', $order->id)->where('type', 'TRADE_BUY')->count());
        $this->assertDecimal('820', $this->walletOf($user)->available_balance); // 1000 - 180, once
        $this->assertDecimal('2', $this->positionOf($user, $this->btc)?->quantity);
        $this->assertSame(1, AuditLog::query()->where('action', 'order.filled')->count());
        $this->assertLedgerReconciles();
    }

    #[Test]
    public function the_job_has_retries_and_backoff_for_transient_errors(): void
    {
        $job = new ProcessOrderJob(1);

        $this->assertSame(5, $job->tries);
        $this->assertSame([1, 5, 15, 30], $job->backoff());
        $this->assertSame('orders', $job->queue);
    }

    // ---- Required: failure path -----------------------------------------------------

    #[Test]
    public function a_permanent_job_failure_marks_the_order_failed_and_releases_the_reservation(): void
    {
        $user = $this->trader('1000');
        // Real (sync) queue here, not a fake. No price exists yet, so the job that
        // placement queues just leaves the order PENDING.
        $this->placeOrder($user, ['asset' => 'BTC-USD', 'side' => 'BUY', 'type' => 'LIMIT', 'quantity' => '2', 'limit_price' => '100'])
            ->assertStatus(202);
        $order = Order::query()->latest('id')->firstOrFail();
        $this->assertDecimal('200', $this->walletOf($user)->reserved_balance);

        // Make settlement blow up every time (a bug, the database gone, ...).
        $this->mock(SettleOrder::class, fn ($mock) => $mock->shouldReceive('handle')->andThrow(new RuntimeException('Simulated crash')));

        // Push the job onto the real sync queue. When the job throws, Laravel's queue marks
        // the job as failed and calls ProcessOrderJob::failed() (a worker does the same once
        // $tries is used up), then rethrows.
        $thrown = null;
        try {
            ProcessOrderJob::dispatch($order->id)->onConnection('sync');
        } catch (RuntimeException $e) {
            $thrown = $e;
        }
        $this->assertSame('Simulated crash', $thrown?->getMessage());

        $order->refresh();
        $this->assertSame(OrderStatus::Failed, $order->status);
        $this->assertSame('JOB_FAILED', $order->failure_reason);
        $wallet = $this->walletOf($user);
        $this->assertDecimal('1000', $wallet->available_balance);
        $this->assertDecimal('0', $wallet->reserved_balance);
        $this->assertSame(1, AuditLog::query()->where('action', 'order.failed')->count());
        $this->assertLedgerReconciles();
    }

    #[Test]
    public function failed_on_an_already_filled_order_changes_nothing(): void
    {
        $user = $this->trader('1000');
        $order = $this->place($user, ['side' => 'BUY', 'type' => 'LIMIT', 'quantity' => '1', 'limit_price' => '100']);
        $this->setPrice($this->btc, '100');
        app(SettleOrder::class)->handle($order->id);

        (new ProcessOrderJob($order->id))->failed(new RuntimeException('late failure'));

        $this->assertSame(OrderStatus::Filled, $order->refresh()->status);
        $this->assertDecimal('900', $this->walletOf($user)->available_balance); // no extra "release"
        $this->assertLedgerReconciles();
    }

    // ---- Atomicity: a crash halfway rolls everything back, no events, no audit ---------

    #[Test]
    public function a_settlement_that_crashes_halfway_leaves_no_trace(): void
    {
        $user = $this->trader('1000');
        $order = $this->place($user, ['side' => 'BUY', 'type' => 'LIMIT', 'quantity' => '1', 'limit_price' => '100']);
        $this->setPrice($this->btc, '90');

        // Sabotage: a stray execution row makes the INSERT inside settlement fail AFTER the
        // wallet, ledger and position were already changed in the same transaction.
        DB::table('executions')->insert([
            'order_id' => $order->id, 'asset_id' => $this->btc->id, 'price' => 1, 'quantity' => 1,
            'notional' => 1, 'fee' => 0, 'executed_at' => now(),
        ]);
        $ledgerRowsBefore = LedgerEntry::query()->count();

        try {
            app(SettleOrder::class)->handle($order->id);
            $this->fail('Expected settlement to fail.');
        } catch (UniqueConstraintViolationException) {
            // expected
        }

        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
        $this->assertDecimal('900', $this->walletOf($user)->available_balance);
        $this->assertDecimal('100', $this->walletOf($user)->reserved_balance);
        $this->assertSame($ledgerRowsBefore, LedgerEntry::query()->count());
        $this->assertNull($this->positionOf($user, $this->btc));
        $this->assertSame(0, AuditLog::query()->where('action', 'order.filled')->count());
    }

    #[Test]
    public function events_are_held_until_commit_and_dropped_on_rollback(): void
    {
        $user = $this->trader('1000');
        $order = $this->place($user, ['side' => 'BUY', 'type' => 'LIMIT', 'quantity' => '1', 'limit_price' => '100']);
        $this->setPrice($this->btc, '90');

        // Settlement succeeds, but an OUTER transaction then rolls back.
        try {
            DB::transaction(function () use ($order) {
                app(SettleOrder::class)->handle($order->id);
                throw new RuntimeException('outer rollback');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(OrderStatus::Pending, $order->refresh()->status);
        $this->assertSame(0, AuditLog::query()->where('action', 'order.filled')->count());
        $this->assertSame(0, Execution::query()->count());
    }

    #[Test]
    public function order_filled_is_dispatched_after_a_successful_settlement(): void
    {
        $user = $this->trader('1000');
        $order = $this->place($user, ['side' => 'BUY', 'type' => 'LIMIT', 'quantity' => '1', 'limit_price' => '100']);
        $this->setPrice($this->btc, '90');
        Event::fake([OrderFilled::class]);

        app(SettleOrder::class)->handle($order->id);

        Event::assertDispatched(OrderFilled::class, fn (OrderFilled $e) => $e->orderId === $order->id
            && $e->before['status'] === 'PENDING' && $e->after['status'] === 'FILLED');
    }
}
