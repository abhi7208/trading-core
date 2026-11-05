<?php

namespace App\Enums;

/**
 * Machine-readable reasons stored in orders.failure_reason.
 */
enum RejectionReason: string
{
    case NoFreshPrice = 'NO_FRESH_PRICE';
    case PriceMovedBeyondCollar = 'PRICE_MOVED_BEYOND_COLLAR';
    case Expired = 'EXPIRED';
    case CancelledByUser = 'CANCELLED_BY_USER';
    case JobFailed = 'JOB_FAILED';
}
