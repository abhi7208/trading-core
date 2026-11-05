<?php

namespace Tests\Feature;

use App\Actions\SettleOrder;
use App\Jobs\ProcessOrderJob;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CancelOrderTest extends TestCase
{
    use RefreshDatabase;

    private function pendingBuy(User $user): Order
    {
        $this->asset(['symbol' => 'BTC-USD']);
        $this->placeOrder($user, ['asset' => 'BTC-USD', 'side' => 'BUY', 'type' => 'LIMIT', 'quantity' => '2', 'limit_price' => '100'])
            ->assertStatus(202);

        return Order::query()->latest('id')->firstOrFail();
    }

    #[Test]
    public function cancelling_a_pending_buy_releases_the_cash_and_is_audited(): void
    {
        Queue::fake([ProcessOrderJob::class]);
        $user = $this->trader('1000');
        $order = $this->pendingBuy($user);
        $this->assertDecimal('800', $this->walletOf($user)->available_balance);

        Sanctum::actingAs($user);
        $this->deleteJson("/api/v1/orders/{$order->uuid}")
            ->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED')
            ->assertJsonPath('data.failure_reason', 'CANCELLED_BY_USER');

        $wallet = $this->walletOf($user);
        $this->assertDecimal('1000', $wallet->available_balance);
        $this->assertDecimal('0', $wallet->reserved_balance);
        $this->assertLedgerReconciles();

        $audit = AuditLog::query()->where('action', 'order.cancelled')->firstOrFail();
        $this->assertSame($user->id, $audit->actor_id);
        $this->assertSame('PENDING', $audit->context['before']['status']);
        $this->assertSame('CANCELLED', $audit->context['after']['status']);
        $this->assertSame('127.0.0.1', $audit->ip_address);
    }

    #[Test]
    public function cancelling_a_sell_returns_the_quantity(): void
    {
        Queue::fake([ProcessOrderJob::class]);
        $asset = $this->asset(['symbol' => 'AAPL']);
        $user = $this->trader('0', [$asset->id => '5']);
        $this->placeOrder($user, ['asset' => 'AAPL', 'side' => 'SELL', 'type' => 'LIMIT', 'quantity' => '5', 'limit_price' => '1'])->assertStatus(202);
        $order = Order::query()->firstOrFail();

        Sanctum::actingAs($user);
        $this->deleteJson("/api/v1/orders/{$order->uuid}")->assertOk();

        $this->assertDecimal('5', $this->positionOf($user, $asset)?->quantity);
        $this->assertDecimal('0', $this->positionOf($user, $asset)?->reserved_quantity);
    }

    #[Test]
    public function cancelling_twice_releases_only_once(): void
    {
        Queue::fake([ProcessOrderJob::class]);
        $user = $this->trader('1000');
        $order = $this->pendingBuy($user);

        Sanctum::actingAs($user);
        $this->deleteJson("/api/v1/orders/{$order->uuid}")->assertOk();
        $this->deleteJson("/api/v1/orders/{$order->uuid}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ORDER_NOT_CANCELLABLE');

        $this->assertDecimal('1000', $this->walletOf($user)->available_balance); // not 1200
        $this->assertLedgerReconciles();
    }

    #[Test]
    public function a_filled_order_cannot_be_cancelled(): void
    {
        Queue::fake([ProcessOrderJob::class]);
        $user = $this->trader('1000');
        $order = $this->pendingBuy($user);
        $this->setPrice($order->asset, '90');
        app(SettleOrder::class)->handle($order->id);

        Sanctum::actingAs($user);
        $this->deleteJson("/api/v1/orders/{$order->uuid}")
            ->assertStatus(409)
            ->assertJsonPath('error.details.status', 'FILLED');
    }

    #[Test]
    public function only_the_owner_can_cancel(): void
    {
        Queue::fake([ProcessOrderJob::class]);
        $owner = $this->trader('1000');
        $order = $this->pendingBuy($owner);

        Sanctum::actingAs($this->trader());
        $this->deleteJson("/api/v1/orders/{$order->uuid}")->assertStatus(403);

        $this->assertTrue($order->fresh()?->isPending());
    }
}
