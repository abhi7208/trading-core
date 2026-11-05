<?php

namespace App\Services;

use App\Data\PriceQuote;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Redis;

/**
 * The CURRENT price of each asset, kept in Redis.
 *
 * Matching reads prices from here, never from the market_ticks table. Redis answers in
 * well under a millisecond and does not add load to MySQL at 50+ ticks per second.
 *
 * Storage: one Redis hash, "prices:latest", field = asset id, value = "price|unix_microseconds".
 */
class PriceStore
{
    private const KEY = 'prices:latest';

    /**
     * @param  array<int, array{price: Decimal, at: CarbonImmutable}>  $prices  asset id => latest price
     */
    public function putMany(array $prices): void
    {
        if ($prices === []) {
            return;
        }

        $fields = [];
        foreach ($prices as $assetId => $quote) {
            $fields[(string) $assetId] = $quote['price']->toStorage().'|'.$quote['at']->format('U.u');
        }

        Redis::connection()->hmset(self::KEY, $fields);
    }

    public function put(int $assetId, Decimal $price, CarbonImmutable $at): void
    {
        $this->putMany([$assetId => ['price' => $price, 'at' => $at]]);
    }

    public function latest(int $assetId): ?PriceQuote
    {
        $raw = Redis::connection()->hget(self::KEY, (string) $assetId);

        if (! is_string($raw) || ! str_contains($raw, '|')) {
            return null;
        }

        [$price, $timestamp] = explode('|', $raw, 2);

        return new PriceQuote(
            assetId: $assetId,
            price: Decimal::of($price),
            recordedAt: CarbonImmutable::createFromFormat('U.u', $timestamp) ?: CarbonImmutable::now(),
        );
    }

    /** @return array<int, PriceQuote> asset id => quote */
    public function all(): array
    {
        /** @var array<string, string> $rows */
        $rows = Redis::connection()->hgetall(self::KEY) ?: [];
        $quotes = [];
        foreach (array_keys($rows) as $assetId) {
            $quote = $this->latest((int) $assetId);
            if ($quote !== null) {
                $quotes[(int) $assetId] = $quote;
            }
        }

        return $quotes;
    }

    public function flush(): void
    {
        Redis::connection()->del(self::KEY);
    }
}
