<?php

namespace App\Data;

use App\Enums\RejectionReason;
use App\Support\Decimal;

/**
 * The answer the matching rules give for one order at one moment:
 * FILL at a price, WAIT for a better price, or REJECT with a reason.
 */
final readonly class MatchDecision
{
    private function __construct(
        public string $outcome,
        public ?Decimal $price = null,
        public ?RejectionReason $reason = null,
    ) {}

    public static function fill(Decimal $price): self
    {
        return new self('fill', price: $price);
    }

    public static function wait(): self
    {
        return new self('wait');
    }

    public static function reject(RejectionReason $reason): self
    {
        return new self('reject', reason: $reason);
    }

    public function isFill(): bool
    {
        return $this->outcome === 'fill';
    }

    public function isWait(): bool
    {
        return $this->outcome === 'wait';
    }

    public function isReject(): bool
    {
        return $this->outcome === 'reject';
    }
}
