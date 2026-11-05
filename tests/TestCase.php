<?php

namespace Tests;

use App\Models\Asset;
use App\Models\Position;
use App\Models\User;
use App\Models\Wallet;
use App\Services\LedgerWriter;
use App\Services\PriceStore;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Each test starts with empty Redis (prices, cache, locks). Tests use Redis DBs 14/15.
        Redis::connection()->flushdb();
        Redis::connection('cache')->flushdb();
    }

    /**
     * A trader with a funded wallet (funded THROUGH the ledger) and optional holdings.
     *
     * @param  array<int, string>  $holdings  asset id => quantity
     */
    protected function trader(string $usd = '10000', array $holdings = []): User
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create(['user_id' => $user->id]);

        if (Decimal::of($usd)->isPositive()) {
            DB::transaction(function () use ($wallet, $usd) {
                $locked = Wallet::query()->whereKey($wallet->id)->lockForUpdate()->firstOrFail();
                app(LedgerWriter::class)->deposit($locked, Decimal::of($usd));
            });
        }

        foreach ($holdings as $assetId => $quantity) {
            Position::create([
                'user_id' => $user->id, 'asset_id' => $assetId,
                'quantity' => $quantity, 'reserved_quantity' => '0', 'average_cost' => '0',
            ]);
        }

        return $user;
    }

    /** @param array<string, mixed> $attributes */
    protected function asset(array $attributes = []): Asset
    {
        return Asset::factory()->create($attributes);
    }

    protected function setPrice(Asset $asset, string $price, ?CarbonImmutable $at = null): void
    {
        app(PriceStore::class)->put($asset->id, Decimal::of($price), $at ?? CarbonImmutable::now());
    }

    /**
     * POST /api/v1/orders as $user.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function placeOrder(User $user, array $payload, ?string $key = null): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->postJson('/api/v1/orders', $payload, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    protected function walletOf(User $user): Wallet
    {
        return Wallet::query()->where('user_id', $user->id)->firstOrFail();
    }

    protected function positionOf(User $user, Asset $asset): ?Position
    {
        return Position::query()->where('user_id', $user->id)->where('asset_id', $asset->id)->first();
    }

    /** Balances must equal the sum of their ledger entries. */
    protected function assertLedgerReconciles(): void
    {
        $this->assertSame(0, Artisan::call('ledger:verify'), Artisan::output());
    }

    protected function assertDecimal(string $expected, Decimal|string|null $actual, string $message = ''): void
    {
        $this->assertNotNull($actual, $message);
        $this->assertTrue(
            Decimal::of($expected)->isEqualTo(Decimal::of((string) $actual)),
            ($message ? "$message: " : '')."expected {$expected}, got {$actual}",
        );
    }
}
