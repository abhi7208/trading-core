<?php

namespace Database\Seeders;

use App\Actions\PlaceOrder;
use App\Data\PlaceOrderData;
use App\Enums\OrderSide;
use App\Enums\OrderType;
use App\Models\Asset;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * A few real trades so the market summary has filled orders and volume.
 * They go through the real PlaceOrder action and are settled immediately
 * (sync queue just for seeding), so every balance and ledger row is genuine.
 */
class DemoTradeSeeder extends Seeder
{
    public function run(PlaceOrder $place): void
    {
        $previousQueue = config('queue.default');
        config(['queue.default' => 'sync']);

        $trades = [
            ['alice@example.com', 'ETH-USD', OrderSide::Buy, '2'],
            ['alice@example.com', 'AAPL', OrderSide::Buy, '25'],
            ['bob@example.com', 'TSLA', OrderSide::Buy, '20'],
            ['bob@example.com', 'AAPL', OrderSide::Sell, '10'],
            ['carol@example.com', 'ETH-USD', OrderSide::Sell, '1'],
            ['carol@example.com', 'XAU-USD', OrderSide::Buy, '1'],
        ];

        foreach ($trades as [$email, $symbol, $side, $quantity]) {
            $user = User::query()->where('email', $email)->firstOrFail();
            $asset = Asset::query()->where('symbol', $symbol)->firstOrFail();
            $key = (string) Str::uuid();

            $place->handle(new PlaceOrderData(
                userId: $user->id,
                assetId: $asset->id,
                side: $side,
                type: OrderType::Market,
                quantity: Decimal::of($quantity),
                limitPrice: null,
                expiresAt: null,
                idempotencyKey: $key,
                requestHash: hash('sha256', $key),
                ipAddress: '127.0.0.1',
            ));
        }

        config(['queue.default' => $previousQueue]);
    }
}
