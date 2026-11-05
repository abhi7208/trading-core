<?php

namespace Tests\Unit;

use App\Data\PriceQuote;
use App\Enums\OrderSide;
use App\Enums\OrderType;
use App\Enums\RejectionReason;
use App\Services\OrderMatcher;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class OrderMatcherTest extends TestCase
{
    private function quote(string $price, int $ageSeconds = 0): PriceQuote
    {
        return new PriceQuote(1, Decimal::of($price), CarbonImmutable::parse('2026-01-01 12:00:00')->subSeconds($ageSeconds));
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-01-01 12:00:00');
    }

    /** @return array<string, array{OrderSide, string, string, string}> */
    public static function limitCases(): array
    {
        return [
            'buy: price below limit fills' => [OrderSide::Buy, '100', '99', 'fill'],
            'buy: price equal to limit fills' => [OrderSide::Buy, '100', '100', 'fill'],
            'buy: price above limit waits' => [OrderSide::Buy, '100', '100.01', 'wait'],
            'sell: price above limit fills' => [OrderSide::Sell, '100', '101', 'fill'],
            'sell: price equal to limit fills' => [OrderSide::Sell, '100', '100', 'fill'],
            'sell: price below limit waits' => [OrderSide::Sell, '100', '99.99', 'wait'],
        ];
    }

    #[Test]
    #[DataProvider('limitCases')]
    public function limit_orders_follow_the_price_rules(OrderSide $side, string $limit, string $price, string $expected): void
    {
        $decision = (new OrderMatcher)->decide($side, OrderType::Limit, Decimal::of($limit), $this->quote($price), $this->now());

        $this->assertSame($expected, $decision->outcome);
        if ($expected === 'fill') {
            // Fills at the MARKET price, not at the limit price.
            $this->assertSame($price, (string) $decision->price);
        }
    }

    #[Test]
    public function market_order_fills_at_a_fresh_price(): void
    {
        $decision = (new OrderMatcher)->decide(OrderSide::Buy, OrderType::Market, null, $this->quote('250', 29), $this->now());

        $this->assertTrue($decision->isFill());
        $this->assertSame('250', (string) $decision->price);
    }

    #[Test]
    public function market_order_with_price_older_than_30s_is_rejected(): void
    {
        $decision = (new OrderMatcher)->decide(OrderSide::Sell, OrderType::Market, null, $this->quote('250', 31), $this->now());

        $this->assertTrue($decision->isReject());
        $this->assertSame(RejectionReason::NoFreshPrice, $decision->reason);
    }

    #[Test]
    public function market_order_with_no_price_at_all_is_rejected(): void
    {
        $decision = (new OrderMatcher)->decide(OrderSide::Buy, OrderType::Market, null, null, $this->now());

        $this->assertTrue($decision->isReject());
    }

    #[Test]
    public function limit_order_never_fills_on_a_stale_price(): void
    {
        $decision = (new OrderMatcher)->decide(OrderSide::Buy, OrderType::Limit, Decimal::of('100'), $this->quote('50', 120), $this->now());

        $this->assertTrue($decision->isWait());
    }
}
