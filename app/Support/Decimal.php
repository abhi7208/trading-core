<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * An exact decimal number for money, prices and quantities.
 *
 * WHY THIS EXISTS
 * PHP floats are binary approximations: 0.1 + 0.2 === 0.30000000000000004.
 * For money that is a bug. This class wraps brick/math's BigDecimal, which does
 * exact base-10 arithmetic on strings, so 0.1 + 0.2 is exactly 0.3.
 *
 * RULES
 *  - It is immutable: every operation returns a NEW Decimal.
 *  - It can never be built from a float (the constructor only accepts strings/ints).
 *  - Everything stored in the database uses SCALE (8) decimal places.
 *  - Any multiplication/division must state how it rounds.
 */
final class Decimal implements JsonSerializable, Stringable
{
    /** Number of decimal places stored in every DECIMAL(20,8) column. */
    public const SCALE = 8;

    private function __construct(private readonly BigDecimal $value) {}

    /**
     * Build from a string like "123.45" or an int. Floats are refused on purpose.
     */
    public static function of(string|int|self $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (is_string($value) && ! preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            throw new InvalidArgumentException("Not a valid decimal: {$value}");
        }

        return new self(BigDecimal::of($value));
    }

    public static function zero(): self
    {
        return new self(BigDecimal::zero());
    }

    public function plus(self $other): self
    {
        return new self($this->value->plus($other->value));
    }

    public function minus(self $other): self
    {
        return new self($this->value->minus($other->value));
    }

    /**
     * Multiply and round to SCALE places.
     *
     * Rounding direction matters for money:
     *  - When the customer PAYS (buy cost, reservation) we round UP, so we never reserve
     *    or charge less than the true amount.
     *  - When the customer RECEIVES (sell proceeds) we round DOWN, so we never pay out more.
     */
    public function times(self $other, RoundingMode $rounding = RoundingMode::HalfUp): self
    {
        return new self($this->value->multipliedBy($other->value)->toScale(self::SCALE, $rounding));
    }

    public function dividedBy(self $other, RoundingMode $rounding = RoundingMode::HalfUp, int $scale = self::SCALE): self
    {
        return new self($this->value->dividedBy($other->value, $scale, $rounding));
    }

    /** Round to a given number of decimal places (e.g. an asset's price precision). */
    public function toScale(int $scale, RoundingMode $rounding = RoundingMode::HalfUp): self
    {
        return new self($this->value->toScale($scale, $rounding));
    }

    public function negated(): self
    {
        return new self($this->value->negated());
    }

    public function compareTo(self $other): int
    {
        return $this->value->compareTo($other->value);
    }

    public function isEqualTo(self $other): bool
    {
        return $this->value->isEqualTo($other->value);
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->value->isGreaterThan($other->value);
    }

    public function isGreaterThanOrEqualTo(self $other): bool
    {
        return $this->value->isGreaterThanOrEqualTo($other->value);
    }

    public function isLessThan(self $other): bool
    {
        return $this->value->isLessThan($other->value);
    }

    public function isLessThanOrEqualTo(self $other): bool
    {
        return $this->value->isLessThanOrEqualTo($other->value);
    }

    public function isZero(): bool
    {
        return $this->value->isZero();
    }

    public function isNegative(): bool
    {
        return $this->value->isNegative();
    }

    public function isPositive(): bool
    {
        return $this->value->isPositive();
    }

    /** Number of digits after the decimal point, ignoring trailing zeros ("1.500" -> 1). */
    public function significantScale(): int
    {
        return $this->value->strippedOfTrailingZeros()->getScale();
    }

    /** Canonical form used for hashing request payloads: "1.50" and "1.5" are the same. */
    public function normalized(): string
    {
        return (string) $this->value->strippedOfTrailingZeros();
    }

    /** Fixed SCALE string, exactly what goes into a DECIMAL(20,8) column. */
    public function toStorage(): string
    {
        return (string) $this->value->toScale(self::SCALE, RoundingMode::Unnecessary);
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }

    public function jsonSerialize(): string
    {
        return (string) $this->value;
    }
}
