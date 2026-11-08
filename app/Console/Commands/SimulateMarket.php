<?php

namespace App\Console\Commands;

use App\Jobs\SweepAssetOrdersJob;
use App\Models\Asset;
use App\Services\PriceStore;
use App\Support\Decimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fake price feed: random-walk prices for every tradable asset.
 *
 *   php artisan market:simulate                 # 50 ticks/second in total, forever
 *   php artisan market:simulate --rate=200 --duration=60
 *
 * Every --flush milliseconds it:
 *   1. INSERTs all buffered ticks in ONE query (batching, not one INSERT per tick),
 *   2. updates the latest price per asset in Redis (one round trip),
 *   3. queues ONE sweep per asset that moved (deduplicated, see SweepAssetOrdersJob).
 */
class SimulateMarket extends Command
{
    protected $signature = 'market:simulate
        {--rate=50 : Total ticks per second across all assets}
        {--duration=0 : Stop after this many seconds (0 = run forever)}
        {--flush=250 : Write a batch every N milliseconds}';

    protected $description = 'Generate random-walk price ticks for all tradable assets';

    /** @var array<int, Decimal> asset id => current simulated price */
    private array $prices = [];

    public function handle(PriceStore $store): int
    {
        $assets = Asset::query()->where('is_tradable', true)->get();
        if ($assets->isEmpty()) {
            $this->error('No tradable assets. Run the seeders first.');

            return self::FAILURE;
        }

        $this->prices = $this->startingPrices($assets->pluck('id')->all(), $store);

        $rate = max(1, (int) $this->option('rate'));
        $flushEverySeconds = max(10, (int) $this->option('flush')) / 1000;
        $duration = (int) $this->option('duration');
        $tickInterval = 1 / $rate;

        $this->info("Simulating {$rate} ticks/s for {$assets->count()} assets. Ctrl+C to stop.");

        $started = microtime(true);
        $nextTick = $started;
        $nextFlush = $started + $flushEverySeconds;
        $buffer = [];
        $total = 0;
        $i = 0;

        while ($duration === 0 || microtime(true) - $started < $duration) {
            $now = microtime(true);

            // Emit every tick that is due (catches up if we fell behind).
            while ($nextTick <= $now) {
                $asset = $assets[$i++ % $assets->count()];
                $buffer[] = [
                    'asset_id' => $asset->id,
                    'price' => $this->step($asset->id, $asset->price_precision),
                    'at' => CarbonImmutable::createFromTimestamp($nextTick),
                ];
                $nextTick += $tickInterval;
            }

            if ($now >= $nextFlush && $buffer !== []) {
                $total += $this->flush($buffer, $store);
                $buffer = [];
                $nextFlush = $now + $flushEverySeconds;
            }

            usleep(2000);
        }

        $total += $this->flush($buffer, $store);
        $this->info("Done. Wrote {$total} ticks.");

        return self::SUCCESS;
    }

    /**
     * @param  list<array{asset_id: int, price: Decimal, at: CarbonImmutable}>  $buffer
     */
    private function flush(array $buffer, PriceStore $store): int
    {
        if ($buffer === []) {
            return 0;
        }

        $rows = [];
        $latest = [];
        foreach ($buffer as $tick) {
            $rows[] = [
                'asset_id' => $tick['asset_id'],
                'price' => $tick['price']->toStorage(),
                'recorded_at' => $tick['at']->format('Y-m-d H:i:s.u'),
            ];
            $latest[$tick['asset_id']] = ['price' => $tick['price'], 'at' => $tick['at']];
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('market_ticks')->insert($chunk);   // one INSERT for up to 1,000 ticks
        }

        $store->putMany($latest);

        foreach (array_keys($latest) as $assetId) {
            SweepAssetOrdersJob::dispatch($assetId);      // deduplicated per asset
        }

        return count($rows);
    }

    /** One random-walk step: move the price by up to +/- 0.05%. */
    private function step(int $assetId, int $precision): Decimal
    {
        $basisPointsTimes100 = random_int(-500, 500); // -0.05% .. +0.05%
        $factor = Decimal::of(1_000_000 + $basisPointsTimes100)->dividedBy(Decimal::of(1_000_000), scale: 8);
        $next = $this->prices[$assetId]->times($factor)->toScale($precision, RoundingMode::HalfUp);

        $floor = Decimal::of(1)->dividedBy(Decimal::of(10 ** $precision), scale: $precision);
        if ($next->isLessThan($floor)) {
            $next = $floor;
        }

        return $this->prices[$assetId] = $next->toScale(Decimal::SCALE);
    }

    /**
     * Start from the last known price (Redis, then the ticks table), or 100.
     *
     * @param  list<int>  $assetIds
     * @return array<int, Decimal>
     */
    private function startingPrices(array $assetIds, PriceStore $store): array
    {
        $prices = [];
        foreach ($assetIds as $id) {
            $quote = $store->latest($id);
            $last = $quote->price ?? DB::table('market_ticks')->where('asset_id', $id)->orderByDesc('recorded_at')->value('price');
            $prices[$id] = Decimal::of($last === null ? '100' : (string) $last);
        }

        return $prices;
    }
}
