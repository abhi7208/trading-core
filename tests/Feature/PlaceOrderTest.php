<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Jobs\ProcessOrderJob;
use App\Models\IdempotencyKey;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlaceOrderTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_buy_limit_order_is_accepted_and_reserves_cash_immediately(): void
    {
        Queue::fake();
        $asset = $this->asset(['symbol' => 'BTC-USD']);
        $user = $this->trader('10000');

        $response = $this->placeOrder($user, [
            'asset' => 'BTC-USD', 'side' => 'BUY', 'type' => 'LIMIT', 'quantity' => '2.5', 'limit_price' => '1000',
        ]);

        $response->assertStatus(202)
            ->assertHeader('Idempotent-Replayed', 'false')
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.asset', 'BTC-USD')
            ->assertJsonPath('data.reserved_amount', '2500.00000000');

        $wallet = $this->walletOf($user);
        $this->assertDecimal('7500', $wallet->available_balance);
        $this->assertDecimal('2500', $wallet->reserved_balance);
        $this->assertLedgerReconciles();

        // The job is queued on the dedicated "orders" queue.
        Queue::assertPushedOn('orders', ProcessOrderJob::class);
        $this->assertNotNull($asset->id);
    }

    #[Test]
    public function a_sell_order_reserves_the_holding_not_cash(): void
    {
        Queue::fake();
        $asset = $this->asset(['symbol' => 'AAPL']);
        $user = $this->trader('0', [$asset->id => '10']);

        $this->placeOrder($user, [
            'asset' => 'AAPL', 'side' => 'SELL', 'type' => 'LIMIT', 'quantity' => '4', 'limit_price' => '200',
        ])->assertStatus(202);

        $position = $this->positionOf($user, $asset);
        $this->assertDecimal('6', $position?->quantity);
        $this->assertDecimal('4', $position?->reserved_quantity);
    }

    #[Test]
    public function a_market_buy_reserves_latest_price_plus_the_collar(): void
    {
        Queue::fake();
        $asset = $this->asset(['symbol' => 'ETH-USD']);
        $this->setPrice($asset, '100');
        $user = $this->trader('1000');

        $this->placeOrder($user, ['asset' => 'ETH-USD', 'side' => 'BUY', 'type' => 'MARKET', 'quantity' => '2'])
            ->assertStatus(202)
            ->assertJsonPath('data.reserved_amount', '210.00000000'); // 2 x 100 x 1.05

        $this->assertDecimal('790', $this->walletOf($user)->available_balance);
    }

    #[Test]
    public function a_market_buy_without_a_fresh_price_is_refused(): void
    {
        $asset = $this->asset(['symbol' => 'ETH-USD']);
        $this->setPrice($asset, '100', now()->toImmutable()->subMinute());
        $user = $this->trader('1000');

        $this->placeOrder($user, ['asset' => 'ETH-USD', 'side' => 'BUY', 'type' => 'MARKET', 'quantity' => '1'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'NO_FRESH_PRICE');

        $this->assertDatabaseCount('orders', 0);
    }

    #[Test]
    public function insufficient_funds_rejects_the_order_and_reserves_nothing(): void
    {
        $this->asset(['symbol' => 'BTC-USD']);
        $user = $this->trader('100');

        $this->placeOrder($user, ['asset' => 'BTC-USD', 'side' => 'BUY', 'type' => 'LIMIT', 'quantity' => '1', 'limit_price' => '100.01'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INSUFFICIENT_FUNDS')
            ->assertJsonPath('error.details.required', '100.01000000');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDecimal('100', $this->walletOf($user)->available_balance);
    }

    #[Test]
    public function selling_more_than_you_hold_is_rejected(): void
    {
        $asset = $this->asset(['symbol' => 'AAPL']);
        $user = $this->trader('0', [$asset->id => '1']);

        $this->placeOrder($user, ['asset' => 'AAPL', 'side' => 'SELL', 'type' => 'LIMIT', 'quantity' => '2', 'limit_price' => '1'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INSUFFICIENT_POSITION');
    }

    // ---- Idempotency -------------------------------------------------------------

    #[Test]
    public function repeating_a_request_with_the_same_key_returns_the_original_response_and_reserves_once(): void
    {
        Queue::fake();
        $this->asset(['symbol' => 'BTC-USD']);
        $user = $this->trader('10000');
        $payload = ['asset' => 'BTC-USD', 'side' => 'BUY', 'type' => 'LIMIT', 'quantity' => '1', 'limit_price' => '1000'];

        $first = $this->placeOrder($user, $payload, 'key-123')->assertStatus(202);
        // Mobile client retries. Note "1.0": same value, different spelling, still the same request.
        $second = $this->placeOrder($user, [...$payload, 'quantity' => '1.0'], 'key-123')->assertStatus(202);

        $second->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->json(), $second->json());
        $this->assertDatabaseCount('orders', 1);
        $this->assertDecimal('9000', $this->walletOf($user)->available_balance); // reserved only once
        $this->assertLedgerReconciles();
    }

    #[Test]
    public function reusing_a_key_with_a_different_payload_is_a_conflict(): void
    {
        Queue::fake();
        $this->asset(['symbol' => 'BTC-USD']);
        $user = $this->trader('10000');

        $this->placeOrder($user, ['asset' => 'BTC-USD', 'side' => 'BUY', 'type' => 'LIMIT', 'quantity' => '1', 'limit_price' => '1000'], 'key-9')
            ->assertStatus(202);

        $this->placeOrder($user, ['asset' => 'BTC-USD', 'side' => 'BUY', 'type' => 'LIMIT', 'quantity' => '2', 'limit_price' => '1000'], 'key-9')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');

        $this->assertDatabaseCount('orders', 1);
    }

    #[Test]
    public function the_same_key_from_two_different_users_creates_two_orders(): void
    {
        Queue::fake();
        $this->asset(['symbol' => 'BTC-USD']);
        $payload = ['asset' => 'BTC-USD', 'side' => 'BUY', 'type' => 'LIMIT', 'quantity' => '1', 'limit_price' => '10'];

        $this->placeOrder($this->trader('100'), $payload, 'shared')->assertStatus(202);
        $this->placeOrder($this->trader('100'), $payload, 'shared')->assertStatus(202);

        $this->assertDatabaseCount('orders', 2);
        $this->assertSame(2, IdempotencyKey::query()->where('key', 'shared')->count());
    }

    // ---- Validation ----------------------------------------------------------------

    #[Test]
    public function the_idempotency_key_header_is_required(): void
    {
        $this->asset(['symbol' => 'BTC-USD']);
        Sanctum::actingAs($this->trader());

        $this->postJson('/api/v1/orders', ['asset' => 'BTC-USD', 'side' => 'BUY', 'type' => 'LIMIT', 'quantity' => '1', 'limit_price' => '1'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['idempotency_key']]]]);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidPayloads(): array
    {
        $base = ['asset' => 'BTC-USD', 'side' => 'BUY', 'type' => 'LIMIT', 'quantity' => '1', 'limit_price' => '100'];

        return [
            'unknown asset' => [[...$base, 'asset' => 'NOPE'], 'asset'],
            'untradable asset' => [[...$base, 'asset' => 'OLD-USD'], 'asset'],
            'bad side' => [[...$base, 'side' => 'HOLD'], 'side'],
            'bad type' => [[...$base, 'type' => 'STOP'], 'type'],
            'quantity too precise' => [[...$base, 'quantity' => '0.123'], 'quantity'],       // asset allows 2
            'price too precise' => [[...$base, 'limit_price' => '100.001'], 'limit_price'],   // asset allows 2
            'zero quantity' => [[...$base, 'quantity' => '0'], 'quantity'],
            'negative quantity' => [[...$base, 'quantity' => '-1'], 'quantity'],
            'float instead of string' => [[...$base, 'quantity' => 1.5], 'quantity'],
            'limit without price' => [array_diff_key($base, ['limit_price' => 1]), 'limit_price'],
            'market with a price' => [[...$base, 'type' => 'MARKET'], 'limit_price'],
            'expiry in the past' => [[...$base, 'expires_at' => '2000-01-01T00:00:00Z'], 'expires_at'],
        ];
    }

    #[Test]
    #[DataProvider('invalidPayloads')]
    public function invalid_orders_are_rejected_with_field_errors(array $payload, string $field): void
    {
        $this->asset(['symbol' => 'BTC-USD', 'price_precision' => 2, 'quantity_precision' => 2]);
        $this->asset(['symbol' => 'OLD-USD', 'is_tradable' => false]);

        $this->placeOrder($this->trader(), $payload)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['fields' => [$field]]]]);

        $this->assertDatabaseCount('orders', 0);
    }

    #[Test]
    public function a_limit_order_gets_a_default_expiry(): void
    {
        Queue::fake();
        $this->asset(['symbol' => 'BTC-USD']);

        $this->placeOrder($this->trader(), ['asset' => 'btc-usd', 'side' => 'BUY', 'type' => 'LIMIT', 'quantity' => '1', 'limit_price' => '1'])
            ->assertStatus(202);

        $order = Order::query()->firstOrFail();
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertTrue($order->expires_at?->between(now()->addHours(23), now()->addHours(25)));
    }

    #[Test]
    public function order_placement_is_rate_limited(): void
    {
        Queue::fake();
        config(['trading.order_rate_limit_per_minute' => 2]);
        $this->asset(['symbol' => 'BTC-USD']);
        $user = $this->trader();
        $payload = ['asset' => 'BTC-USD', 'side' => 'BUY', 'type' => 'LIMIT', 'quantity' => '1', 'limit_price' => '1'];

        $this->placeOrder($user, $payload)->assertStatus(202);
        $this->placeOrder($user, $payload)->assertStatus(202);
        $this->placeOrder($user, $payload)
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'TOO_MANY_REQUESTS')
            ->assertHeader('Retry-After');
    }
}
