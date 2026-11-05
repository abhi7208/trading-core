<?php

namespace Database\Factories;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Asset;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Raw order rows WITHOUT reservations (for listing/authorization tests only).
 * Anything that involves money goes through the PlaceOrder action instead.
 *
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid7(),
            'user_id' => User::factory(),
            'asset_id' => Asset::factory(),
            'side' => OrderSide::Buy,
            'type' => OrderType::Limit,
            'quantity' => '1',
            'limit_price' => '100',
            'reserved_amount' => '0',
            'status' => OrderStatus::Filled,
            'idempotency_key' => (string) Str::uuid(),
        ];
    }
}
