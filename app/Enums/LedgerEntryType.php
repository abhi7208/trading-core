<?php

namespace App\Enums;

/**
 * Why money moved.
 */
enum LedgerEntryType: string
{
    case Deposit = 'DEPOSIT';       // cash put into the wallet (seeding / funding)
    case Reserve = 'RESERVE';       // available -> reserved when a BUY order is accepted
    case Release = 'RELEASE';       // reserved -> available (cancel, reject, fail, unused part of a fill)
    case TradeBuy = 'TRADE_BUY';    // reserved cash paid out for a filled BUY
    case TradeSell = 'TRADE_SELL';  // cash received for a filled SELL
}
