<?php

namespace App\Enums;

/**
 * Which direction the trader is trading.
 * A "backed enum" stores a string value ('BUY'/'SELL') in the database.
 */
enum OrderSide: string
{
    case Buy = 'BUY';
    case Sell = 'SELL';
}
