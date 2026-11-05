<?php

namespace App\Enums;

/**
 * Lifecycle of an order.
 *
 * PENDING -> FILLED | CANCELLED | REJECTED | FAILED
 *
 * PROCESSING exists because the brief requires it, but settlement happens inside a
 * single database transaction while the order row is locked, so no other process can
 * ever observe an in-between state. See DECISIONS.md.
 */
enum OrderStatus: string
{
    case Pending = 'PENDING';
    case Processing = 'PROCESSING';
    case Filled = 'FILLED';
    case Cancelled = 'CANCELLED';
    case Rejected = 'REJECTED';
    case Failed = 'FAILED';

    /** A final status can never change again. */
    public function isFinal(): bool
    {
        return in_array($this, [self::Filled, self::Cancelled, self::Rejected, self::Failed], true);
    }
}
