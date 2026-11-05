<?php

namespace App\Data;

use App\Support\Decimal;
use Carbon\CarbonImmutable;

/**
 * "The latest price for asset X was P at time T", as read from Redis.
 */
final readonly class PriceQuote
{
    public function __construct(
        public int $assetId,
        public Decimal $price,
        public CarbonImmutable $recordedAt,
    ) {}

    public function isFresh(CarbonImmutable $now, int $maxAgeSeconds): bool
    {
        return $this->recordedAt->greaterThanOrEqualTo($now->subSeconds($maxAgeSeconds));
    }
}
