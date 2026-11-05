<?php

return [
    // Every wallet in this system is in one currency.
    'currency' => env('TRADING_CURRENCY', 'USD'),

    // MARKET BUY orders have no limit price, so we reserve latest_price x (1 + collar).
    // If the fill price ends up above that, the order is REJECTED instead of overspending.
    'market_buy_collar' => env('TRADING_MARKET_BUY_COLLAR', '0.05'),

    // A MARKET order needs a price newer than this many seconds.
    'max_price_age_seconds' => 30,

    // LIMIT orders without an explicit expires_at expire after this many hours.
    'default_limit_ttl_hours' => (int) env('TRADING_LIMIT_TTL_HOURS', 24),

    // POST /orders requests per minute per user.
    'order_rate_limit_per_minute' => (int) env('TRADING_ORDER_RATE_LIMIT', 60),

    // Market summary: rebuild at most this often after fills (debounce), cache at most 60s.
    'summary_min_rebuild_seconds' => (int) env('TRADING_SUMMARY_MIN_REBUILD', 2),
    'summary_ttl_seconds' => 60,
];
