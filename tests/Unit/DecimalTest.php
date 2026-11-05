<?php

namespace Tests\Unit;

use App\Support\Decimal;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Math\RoundingMode;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class DecimalTest extends TestCase
{
    #[Test]
    public function addition_is_exact_unlike_floats(): void
    {
        $this->assertNotSame(0.3, 0.1 + 0.2); // the float bug we are avoiding
        $this->assertSame('0.3', (string) Decimal::of('0.1')->plus(Decimal::of('0.2')));
    }

    #[Test]
    public function multiplication_rounds_in_the_requested_direction(): void
    {
        // 0.333333 x 0.00000003 = 0.00000000999999 (14 decimals) -> must round to 8
        $a = Decimal::of('0.333333');
        $b = Decimal::of('0.00000003');

        $this->assertSame('0.00000001', (string) $a->times($b, RoundingMode::Up));   // customer pays: up
        $this->assertSame('0.00000000', (string) $a->times($b, RoundingMode::Down)); // customer receives: down
    }

    #[Test]
    public function it_refuses_garbage_input(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Decimal::of('1e5');
    }

    #[Test]
    public function it_refuses_floats(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Decimal::of(1.5);
    }

    #[Test]
    public function normalized_form_ignores_trailing_zeros(): void
    {
        $this->assertSame(Decimal::of('1.5')->normalized(), Decimal::of('1.50000')->normalized());
        $this->assertSame(1, Decimal::of('1.500')->significantScale());
    }

    #[Test]
    public function storage_form_has_exactly_eight_decimals(): void
    {
        $this->assertSame('12.50000000', Decimal::of('12.5')->toStorage());
    }

    #[Test]
    public function storage_refuses_to_silently_drop_precision(): void
    {
        $this->expectException(RoundingNecessaryException::class);
        Decimal::of('0.123456789')->toStorage(); // 9 decimals cannot fit DECIMAL(20,8)
    }

    #[Test]
    public function comparisons_work(): void
    {
        $this->assertTrue(Decimal::of('10')->isGreaterThan(Decimal::of('9.99999999')));
        $this->assertTrue(Decimal::of('5.0')->isEqualTo(Decimal::of('5')));
        $this->assertTrue(Decimal::of('1')->minus(Decimal::of('2'))->isNegative());
    }
}
