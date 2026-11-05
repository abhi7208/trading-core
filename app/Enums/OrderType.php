<?php

namespace App\Enums;

/**
 * LIMIT  = "only trade at this price or better".
 * MARKET = "trade now at whatever the latest price is".
 */
enum OrderType: string
{
    case Limit = 'LIMIT';
    case Market = 'MARKET';
}
