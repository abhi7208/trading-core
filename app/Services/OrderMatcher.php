<?php

namespace App\Services;

use App\Data\MatchDecision;
use App\Data\PriceQuote;
use App\Enums\OrderSide;
use App\Enums\OrderType;
use App\Enums\RejectionReason;
use App\Support\Decimal;
use Carbon\CarbonImmutable;

/**
 * The matching rules from the brief, as a pure function: no database, no Redis.
 * Pure functions are easy to unit test exhaustively (see tests/Unit/OrderMatcherTest).
 *
 *  - BUY  LIMIT fills when latest price <= limit price, at the latest price.
 *  - SELL LIMIT fills when latest price >= limit price, at the latest price.
 *  - MARKET fills at the latest price; if no price is newer than 30s it is REJECTED.
 *  - A LIMIT order with no fresh price just WAITS (we will not fill at a stale price).
 */
class OrderMatcher
{
    public const MAX_PRICE_AGE_SECONDS = 30;

    public function decide(
        OrderSide $side,
        OrderType $type,
        ?Decimal $limitPrice,
        ?PriceQuote $quote,
        CarbonImmutable $now,
    ): MatchDecision {
        $fresh = $quote !== null && $quote->isFresh($now, self::MAX_PRICE_AGE_SECONDS);

        if ($type === OrderType::Market) {
            return $fresh
                ? MatchDecision::fill($quote->price)
                : MatchDecision::reject(RejectionReason::NoFreshPrice);
        }

        if (! $fresh || $limitPrice === null) {
            return MatchDecision::wait();
        }

        $canFill = $side === OrderSide::Buy
            ? $quote->price->isLessThanOrEqualTo($limitPrice)
            : $quote->price->isGreaterThanOrEqualTo($limitPrice);

        return $canFill ? MatchDecision::fill($quote->price) : MatchDecision::wait();
    }
}
