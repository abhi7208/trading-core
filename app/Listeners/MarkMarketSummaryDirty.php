<?php

namespace App\Listeners;

use App\Events\OrderFilled;
use App\Services\MarketSummary;

/**
 * When an order fills, the market summary is out of date. This listener does NOT delete
 * the cache (that would cause "cache thrash" at high fill rates). It just records
 * "something changed at time T" with one cheap Redis write. See MarketSummary.
 */
class MarkMarketSummaryDirty
{
    public function __construct(private readonly MarketSummary $summary) {}

    public function handle(OrderFilled $event): void
    {
        $this->summary->markDirty();
    }
}
