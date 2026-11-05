<?php

namespace Database\Seeders;

use App\Models\Asset;
use App\Services\PriceStore;
use App\Support\Decimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 24 hours of history: one tick per asset every 2 minutes (720 per asset), ending NOW,
 * so the market summary has a real 24h change. The last price also goes to Redis.
 */
class MarketTickSeeder extends Seeder
{
    public function run(PriceStore $prices): void
    {
        $now = CarbonImmutable::now();
        $latest = [];

        foreach (AssetSeeder::ASSETS as $symbol => [, $precision, , $start]) {
            $asset = Asset::query()->where('symbol', $symbol)->firstOrFail();
            $price = Decimal::of($start);
            $rows = [];

            for ($minutesAgo = 24 * 60; $minutesAgo >= 0; $minutesAgo -= 2) {
                $move = Decimal::of(1_000_000 + random_int(-3000, 3000))->dividedBy(Decimal::of(1_000_000), scale: 8);
                $price = $price->times($move)->toScale($precision, RoundingMode::HalfUp)->toScale(Decimal::SCALE);
                $at = $now->subMinutes($minutesAgo);
                $rows[] = ['asset_id' => $asset->id, 'price' => $price->toStorage(), 'recorded_at' => $at->format('Y-m-d H:i:s.u')];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('market_ticks')->insert($chunk);
            }

            $latest[$asset->id] = ['price' => $price, 'at' => $now];
        }

        $prices->putMany($latest);
    }
}
