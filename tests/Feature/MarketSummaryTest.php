<?php

namespace Tests\Feature;

use App\Actions\SettleOrder;
use App\Jobs\ProcessOrderJob;
use App\Models\Asset;
use App\Models\Order;
use App\Services\MarketSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MarketSummaryTest extends TestCase
{
    use RefreshDatabase;

    private function fill(string $symbol, string $quantity, string $price): void
    {
        Queue::fake([ProcessOrderJob::class]);
        $asset = Asset::query()->where('symbol', $symbol)->firstOrFail();
        $user = $this->trader('1000000');
        $this->placeOrder($user, ['asset' => $symbol, 'side' => 'BUY', 'type' => 'LIMIT', 'quantity' => $quantity, 'limit_price' => $price])
            ->assertStatus(202);
        $this->setPrice($asset, $price);
        app(SettleOrder::class)->handle(Order::query()->latest('id')->value('id'));
    }

    private function tick(int $assetId, string $price, \DateTimeInterface $at): void
    {
        DB::table('market_ticks')->insert(['asset_id' => $assetId, 'price' => $price, 'recorded_at' => $at]);
    }

    #[Test]
    public function it_reports_fills_top_volume_and_24h_change(): void
    {
        $btc = $this->asset(['symbol' => 'BTC-USD']);
        $eth = $this->asset(['symbol' => 'ETH-USD']);
        $this->tick($btc->id, '100', now()->subHours(23));
        $this->tick($eth->id, '50', now()->subHours(20));

        $this->fill('BTC-USD', '2', '110');  // notional 220
        $this->fill('ETH-USD', '10', '40');  // notional 400
        $this->fill('BTC-USD', '1', '110');  // notional 110 -> BTC total 330

        Sanctum::actingAs($this->trader());
        $response = $this->getJson('/api/v1/market/summary')->assertOk();

        $response->assertJsonPath('data.filled_orders_24h', 3)
            ->assertJsonPath('data.top_assets_by_volume.0.asset', 'ETH-USD')
            ->assertJsonPath('data.top_assets_by_volume.0.notional_volume', '400.00000000')
            ->assertJsonPath('data.top_assets_by_volume.1.asset', 'BTC-USD')
            ->assertJsonPath('data.top_assets_by_volume.1.notional_volume', '330.00000000');

        $assets = collect($response->json('data.assets'))->keyBy('asset');
        $this->assertSame('10.00', $assets['BTC-USD']['change_24h_percent']);   // 100 -> 110
        $this->assertSame('-20.00', $assets['ETH-USD']['change_24h_percent']);  // 50 -> 40
    }

    #[Test]
    public function the_summary_is_served_from_cache_without_hitting_the_database(): void
    {
        $this->asset(['symbol' => 'BTC-USD']);
        $summary = app(MarketSummary::class);
        $summary->get(); // warm

        DB::enableQueryLog();
        $summary->get();
        $summary->get();

        $this->assertCount(0, DB::getQueryLog());
    }

    #[Test]
    public function only_one_process_rebuilds_while_others_get_the_previous_copy(): void
    {
        $this->asset(['symbol' => 'BTC-USD']);
        $summary = app(MarketSummary::class);
        $first = $summary->get();
        $summary->markDirty();
        $this->travel(5)->seconds();

        // Pretend another process is currently rebuilding (it holds the lock).
        $lock = Cache::lock('market:summary:rebuild', 10);
        $this->assertTrue($lock->get());

        DB::enableQueryLog();
        $this->assertEquals($first, $summary->get()); // stale copy, no stampede
        $this->assertCount(0, DB::getQueryLog());
        $lock->release();
    }

    #[Test]
    public function fills_refresh_the_summary_with_a_short_debounce_not_on_every_fill(): void
    {
        $this->asset(['symbol' => 'BTC-USD']);
        $summary = app(MarketSummary::class);
        $this->assertSame(0, $summary->get()['filled_orders_24h']);

        $this->fill('BTC-USD', '1', '10');
        // Within the debounce window the cached copy is still served (no thrash)...
        $this->assertSame(0, $summary->get()['filled_orders_24h']);

        // ...and right after it, the next read rebuilds and includes the fill.
        $this->travel(3)->seconds();
        $this->assertSame(1, $summary->get()['filled_orders_24h']);
    }
}
