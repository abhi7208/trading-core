<?php

namespace App\Providers;

use App\Events\OrderCancelled;
use App\Events\OrderFailed;
use App\Events\OrderFilled;
use App\Events\OrderRejected;
use App\Listeners\MarkMarketSummaryDirty;
use App\Listeners\WriteAuditLog;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Catch common Eloquent mistakes early (lazy loading, unknown attributes) outside production.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Event -> listener wiring, written out explicitly so it is easy to read.
        Event::listen(
            [OrderFilled::class, OrderRejected::class, OrderCancelled::class, OrderFailed::class],
            WriteAuditLog::class,
        );
        Event::listen(OrderFilled::class, MarkMarketSummaryDirty::class);

        // Rate limit for POST /orders: N per minute per trader (429 when exceeded).
        RateLimiter::for('orders', function (Request $request) {
            return Limit::perMinute((int) config('trading.order_rate_limit_per_minute'))
                ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip()));
        });
    }
}
