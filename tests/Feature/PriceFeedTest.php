<?php

namespace Tests\Feature;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Jobs\ProcessOrderJob;
use App\Jobs\SweepAssetOrdersJob;
use App\Models\Order;
use App\Services\PriceStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PriceFeedTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_simulator_writes_ticks_in_batches_and_updates_redis(): void
    {
        Queue::fake();
        $assets = collect(range(1, 5))->map(fn () => $this->asset());

        DB::enableQueryLog();
        $this->artisan('market:simulate', ['--rate' => 100, '--duration' => 1, '--flush' => 250])->assertSuccessful();
        $inserts = collect(DB::getQueryLog())->filter(fn ($q) => str_starts_with($q['query'], 'insert into `market_ticks`'))->count();

        $ticks = DB::table('market_ticks')->count();
        $this->assertGreaterThanOrEqual(80, $ticks);
        $this->assertLessThanOrEqual(10, $inserts, "{$ticks} ticks should take only a few INSERTs");

        foreach ($assets as $asset) {
            $this->assertNotNull(app(PriceStore::class)->latest($asset->id), 'latest price must be in Redis');
        }

        // One sweep per asset per flush at most, never one job per tick.
        Queue::assertPushed(SweepAssetOrdersJob::class);
        $this->assertLessThan($ticks, Queue::pushed(SweepAssetOrdersJob::class)->count());
    }

    #[Test]
    public function a_sweep_only_queues_orders_that_can_fill_at_the_new_price(): void
    {
        Queue::fake([ProcessOrderJob::class]);
        $asset = $this->asset(['symbol' => 'BTC-USD']);
        $buyer = $this->trader('100000');
        $seller = $this->trader('0', [$asset->id => '10']);

        $place = fn ($user, $side, $limit) => $this->placeOrder($user, [
            'asset' => 'BTC-USD', 'side' => $side, 'type' => 'LIMIT', 'quantity' => '1', 'limit_price' => $limit,
        ])->assertStatus(202)->json('data.id');

        $buyHigh = $place($buyer, 'BUY', '105');   // can fill at 100
        $place($buyer, 'BUY', '95');                // cannot
        $sellLow = $place($seller, 'SELL', '99');  // can fill at 100
        $place($seller, 'SELL', '101');             // cannot

        Queue::fake([ProcessOrderJob::class]);      // forget the jobs queued at placement
        $this->setPrice($asset, '100');
        (new SweepAssetOrdersJob($asset->id))->handle(app(PriceStore::class));

        $queued = Queue::pushed(ProcessOrderJob::class)->map(fn ($job) => Order::query()->findOrFail($job->orderId)->uuid)->sort()->values()->all();
        $this->assertSame(collect([$buyHigh, $sellLow])->sort()->values()->all(), $queued);
    }

    #[Test]
    public function new_ticks_fill_resting_limit_orders_end_to_end(): void
    {
        $asset = $this->asset(['symbol' => 'BTC-USD']);
        $buyer = $this->trader('1000');
        // Sync queue: placement job runs, no price yet, so the order rests as PENDING.
        $this->placeOrder($buyer, ['asset' => 'BTC-USD', 'side' => 'BUY', 'type' => 'LIMIT', 'quantity' => '1', 'limit_price' => '100'])
            ->assertJsonPath('data.status', 'PENDING');

        $this->setPrice($asset, '99');
        SweepAssetOrdersJob::dispatch($asset->id); // what the simulator does after a batch

        $this->assertSame(OrderStatus::Filled, Order::query()->firstOrFail()->status);
        $this->assertLedgerReconciles();
    }

    #[Test]
    public function the_sweep_query_uses_the_matching_index(): void
    {
        $asset = $this->asset();
        $sql = (new SweepAssetOrdersJob($asset->id))->candidates(OrderSide::Buy, '>=', '100')->select('id')->toRawSql();

        // On a near-empty test table MySQL's optimizer may pick any index, so we assert the
        // index is usable here. DECISIONS.md shows EXPLAIN on 50,000 orders, where it is chosen.
        $plan = DB::select('EXPLAIN '.$sql)[0];
        $this->assertStringContainsString('orders_asset_id_status_side_limit_price_index', (string) $plan->possible_keys);
    }

    #[Test]
    public function expired_orders_are_cancelled_by_the_scheduler_command(): void
    {
        Queue::fake([ProcessOrderJob::class]);
        $this->asset(['symbol' => 'BTC-USD']);
        $user = $this->trader('1000');
        $this->placeOrder($user, [
            'asset' => 'BTC-USD', 'side' => 'BUY', 'type' => 'LIMIT', 'quantity' => '1', 'limit_price' => '100',
            'expires_at' => now()->addMinutes(5)->toIso8601String(),
        ])->assertStatus(202);

        $this->artisan('orders:expire')->expectsOutput('Expired 0 orders.');
        $this->travel(6)->minutes();
        $this->artisan('orders:expire')->expectsOutput('Expired 1 orders.');

        $order = Order::query()->firstOrFail();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame('EXPIRED', $order->failure_reason);
        $this->assertDecimal('1000', $this->walletOf($user)->available_balance);
    }
}
