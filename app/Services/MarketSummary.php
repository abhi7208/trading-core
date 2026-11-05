<?php

namespace App\Services;

use App\Support\Decimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/market/summary, cached.
 *
 * CACHE STRATEGY ("stale-while-revalidate" + "dirty timestamp" debounce)
 *
 *  - The summary is cached for at most 60 seconds.
 *  - When an order fills we do NOT delete the cache. We only write "dirty_at = now" (one
 *    Redis SET). At 1,000 fills/second deleting would mean rebuilding 1,000 times/second.
 *  - On read, the cached copy is "fresh" if it was built after the last fill, OR if it is
 *    younger than 2 seconds. So after fills the summary is rebuilt at most every 2 seconds,
 *    and it always catches up with the most recent fill.
 *  - Only ONE process rebuilds at a time (a Redis lock). Everyone else is served the
 *    previous copy meanwhile. That is the cache-stampede protection: 500 requests on an
 *    expired cache cause 1 rebuild, not 500.
 */
class MarketSummary
{
    private const CACHE_KEY = 'market:summary';

    private const DIRTY_KEY = 'market:summary:dirty_at';

    private const LOCK_KEY = 'market:summary:rebuild';

    public function __construct(private readonly PriceStore $prices) {}

    /** @return array<string, mixed> */
    public function get(): array
    {
        /** @var array{built_at: float, data: array<string, mixed>}|null $entry */
        $entry = Cache::get(self::CACHE_KEY);

        if ($entry !== null && $this->isFresh($entry)) {
            return $entry['data'];
        }

        $lock = Cache::lock(self::LOCK_KEY, 10);

        if ($lock->get()) {                       // we won the right to rebuild
            try {
                return $this->rebuild();
            } finally {
                $lock->release();
            }
        }

        if ($entry !== null) {
            return $entry['data'];                // someone else is rebuilding: serve the old copy
        }

        // Very first request ever (nothing cached yet): wait briefly for the builder.
        return $lock->block(5, function () {
            /** @var array{built_at: float, data: array<string, mixed>}|null $entry */
            $entry = Cache::get(self::CACHE_KEY);

            return $entry['data'] ?? $this->rebuild();
        });
    }

    /** Called (via a listener) whenever an order fills. One cheap write, no cache delete. */
    public function markDirty(): void
    {
        Cache::put(self::DIRTY_KEY, $this->clock(), 3600);
    }

    /** Seconds since epoch with microseconds, from Laravel's clock (so tests can time-travel). */
    private function clock(): float
    {
        return (float) CarbonImmutable::now()->format('U.u');
    }

    /** @param array{built_at: float, data: array<string, mixed>} $entry */
    private function isFresh(array $entry): bool
    {
        $dirtyAt = (float) Cache::get(self::DIRTY_KEY, 0);
        $age = $this->clock() - $entry['built_at'];

        return $entry['built_at'] >= $dirtyAt
            || $age < (int) config('trading.summary_min_rebuild_seconds');
    }

    /** @return array<string, mixed> */
    private function rebuild(): array
    {
        $builtAt = $this->clock();
        $data = $this->build();

        Cache::put(self::CACHE_KEY, ['built_at' => $builtAt, 'data' => $data], (int) config('trading.summary_ttl_seconds'));

        return $data;
    }

    /** @return array<string, mixed> */
    public function build(): array
    {
        $now = CarbonImmutable::now();
        $since = $now->subDay();

        // 1) Filled orders in the last 24h. We do not do partial fills, so every filled order
        //    has exactly one execution: counting executions uses the covering index
        //    executions(executed_at, asset_id, notional) as a pure index range scan.
        $filledCount = DB::table('executions')
            ->where('executed_at', '>=', $since)
            ->count();

        // 2) Top 5 assets by notional volume. Same covering index, no table rows read.
        $top = DB::table('executions')
            ->select('asset_id')
            ->selectRaw('SUM(notional) AS volume')
            ->where('executed_at', '>=', $since)
            ->groupBy('asset_id')
            ->orderByDesc('volume')
            ->limit(5)
            ->get();

        // 3) The first price inside the 24h window, per asset. One UNION ALL of tiny
        //    per-asset lookups; each is an index-only read of market_ticks(asset_id, recorded_at, price).
        //    (A correlated subquery made MySQL read and sort ~40k rows per asset instead.)
        $assets = DB::table('assets')->select('id', 'symbol')->orderBy('symbol')->get();
        $openPrices = $this->firstPricesSince($assets->pluck('id')->all(), $since);

        $symbols = $assets->pluck('symbol', 'id');
        $live = $this->prices->all();

        return [
            'window' => ['from' => $since->toIso8601String(), 'to' => $now->toIso8601String()],
            'filled_orders_24h' => $filledCount,
            'top_assets_by_volume' => $top->map(fn ($row) => [
                'asset' => $symbols[$row->asset_id] ?? null,
                'notional_volume' => (string) Decimal::of((string) $row->volume),
            ])->values()->all(),
            'assets' => $assets->map(function ($row) use ($live, $openPrices) {
                // Current price comes from Redis (the live feed); the ticks table only as fallback.
                $latest = $live[$row->id]->price ?? $this->lastTickPrice((int) $row->id);
                $open = $openPrices[$row->id] ?? null;

                return [
                    'asset' => $row->symbol,
                    'latest_price' => $latest?->__toString(),
                    'change_24h_percent' => $this->percentChange($open, $latest),
                ];
            })->values()->all(),
        ];
    }

    /**
     * @param  list<int>  $assetIds
     * @return array<int, Decimal> asset id => first price at or after $since
     */
    private function firstPricesSince(array $assetIds, CarbonImmutable $since): array
    {
        if ($assetIds === []) {
            return [];
        }

        $parts = array_map(fn (int $id) => DB::table('market_ticks')
            ->select('asset_id', 'price')
            ->where('asset_id', $id)
            ->where('recorded_at', '>=', $since)
            ->orderBy('recorded_at')
            ->limit(1), $assetIds);

        $query = array_shift($parts);
        foreach ($parts as $part) {
            $query->unionAll($part);
        }

        return $query->get()
            ->mapWithKeys(fn ($row) => [(int) $row->asset_id => Decimal::of((string) $row->price)])
            ->all();
    }

    private function lastTickPrice(int $assetId): ?Decimal
    {
        $price = DB::table('market_ticks')
            ->where('asset_id', $assetId)
            ->orderByDesc('recorded_at')
            ->value('price');

        return $price === null ? null : Decimal::of((string) $price);
    }

    private function percentChange(?Decimal $open, ?Decimal $latest): ?string
    {
        if ($open === null || $latest === null || $open->isZero()) {
            return null;
        }

        return (string) $latest->minus($open)
            ->dividedBy($open, RoundingMode::HalfUp, 10)
            ->times(Decimal::of(100))
            ->toScale(2, RoundingMode::HalfUp);
    }
}
