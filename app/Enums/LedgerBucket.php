<?php

namespace App\Enums;

/**
 * A wallet has two "buckets" of cash. Every ledger entry says which bucket it changed,
 * so BOTH balances can be rebuilt from the ledger alone.
 */
enum LedgerBucket: string
{
    case Available = 'AVAILABLE';
    case Reserved = 'RESERVED';
}
