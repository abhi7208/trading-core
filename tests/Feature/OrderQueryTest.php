<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrderQueryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function traders_only_see_their_own_orders(): void
    {
        $me = $this->trader();
        $someoneElse = $this->trader();
        $mine = Order::factory()->create(['user_id' => $me->id]);
        Order::factory()->create(['user_id' => $someoneElse->id]);

        Sanctum::actingAs($me);
        $this->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->uuid);
    }

    #[Test]
    public function orders_can_be_filtered_by_status_and_asset(): void
    {
        $me = $this->trader();
        $btc = $this->asset(['symbol' => 'BTC-USD']);
        $eth = $this->asset(['symbol' => 'ETH-USD']);
        Order::factory()->create(['user_id' => $me->id, 'asset_id' => $btc->id, 'status' => OrderStatus::Filled]);
        Order::factory()->create(['user_id' => $me->id, 'asset_id' => $btc->id, 'status' => OrderStatus::Cancelled]);
        Order::factory()->create(['user_id' => $me->id, 'asset_id' => $eth->id, 'status' => OrderStatus::Filled]);

        Sanctum::actingAs($me);
        $this->getJson('/api/v1/orders?status=FILLED')->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/orders?asset=BTC-USD')->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/orders?asset=BTC-USD&status=FILLED')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/orders?asset=UNKNOWN')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/orders?status=NOPE')->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    #[Test]
    public function the_list_uses_cursor_pagination_newest_first(): void
    {
        $me = $this->trader();
        $orders = Order::factory()->count(5)->create(['user_id' => $me->id]);

        Sanctum::actingAs($me);
        $page1 = $this->getJson('/api/v1/orders?per_page=2')->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame($orders[4]->uuid, $page1->json('data.0.id'));

        $cursor = $page1->json('meta.next_cursor');
        $this->assertNotNull($cursor);

        $page2 = $this->getJson("/api/v1/orders?per_page=2&cursor={$cursor}")->assertJsonCount(2, 'data');
        $this->assertSame($orders[2]->uuid, $page2->json('data.0.id'));
    }

    #[Test]
    public function an_order_can_be_viewed_by_its_owner_with_its_executions(): void
    {
        $me = $this->trader();
        $order = Order::factory()->create(['user_id' => $me->id]);
        $order->executions()->create([
            'asset_id' => $order->asset_id, 'price' => '99.5', 'quantity' => '1', 'notional' => '99.5', 'fee' => '0', 'executed_at' => now(),
        ]);

        Sanctum::actingAs($me);
        $this->getJson("/api/v1/orders/{$order->uuid}")
            ->assertOk()
            ->assertJsonPath('data.id', $order->uuid)
            ->assertJsonPath('data.executions.0.price', '99.50000000');
    }

    #[Test]
    public function someone_elses_order_is_forbidden_and_a_missing_one_is_not_found(): void
    {
        $order = Order::factory()->create(['user_id' => $this->trader()->id]);

        Sanctum::actingAs($this->trader());
        $this->getJson("/api/v1/orders/{$order->uuid}")->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
        $this->getJson('/api/v1/orders/00000000-0000-0000-0000-000000000000')->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');
    }

    #[Test]
    public function the_wallet_shows_balances_and_positions(): void
    {
        $asset = $this->asset(['symbol' => 'AAPL']);
        $me = $this->trader('1234.5', [$asset->id => '3']);

        Sanctum::actingAs($me);
        $this->getJson('/api/v1/wallet')
            ->assertOk()
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.available_balance', '1234.50000000')
            ->assertJsonPath('data.reserved_balance', '0.00000000')
            ->assertJsonPath('data.positions.0.asset', 'AAPL')
            ->assertJsonPath('data.positions.0.available_quantity', '3.00000000');
    }
}
