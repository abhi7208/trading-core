<?php

namespace App\Jobs;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Order;
use App\Services\PriceStore;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;

/**
 * "The price of asset X changed: which PENDING limit orders can fill now?"
 *
 * Strategy (instead of one job per pending order per tick):
 *  - The simulator dispatches at most ONE sweep per asset per batch of ticks.
 *  - ShouldBeUniqueUntilProcessing: while a sweep for asset X is waiting in the queue,
 *    more dispatches for X are dropped. 50 ticks/second become a handful of sweeps.
 *  - The sweep reads the latest price from Redis and asks MySQL ONLY for orders on the
 *    fillable side of that price, using the (asset_id, status, side, limit_price) index:
 *        BUY  orders with limit_price >= price
 *        SELL orders with limit_price <= price
 *  - Only those candidates get a ProcessOrderJob.
 */
class SweepAssetOrdersJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 30;

    public function __construct(public readonly int $assetId)
    {
        $this->onQueue('orders');
    }

    public function uniqueId(): string
    {
        return (string) $this->assetId;
    }

    public function handle(PriceStore $prices): void
    {
        $quote = $prices->latest($this->assetId);
        if ($quote === null) {
            return;
        }

        $price = $quote->price->toStorage();

        $this->dispatchCandidates(OrderSide::Buy, '>=', $price);
        $this->dispatchCandidates(OrderSide::Sell, '<=', $price);
    }

    private function dispatchCandidates(OrderSide $side, string $operator, string $price): void
    {
        $this->candidates($side, $operator, $price)
            ->select('id')
            ->chunkById(500, function ($orders) {
                foreach ($orders as $order) {
                    ProcessOrderJob::dispatch($order->id);
                }
            });
    }

    /** @return Builder<Order> */
    public function candidates(OrderSide $side, string $operator, string $price): Builder
    {
        return Order::query()
            ->where('asset_id', $this->assetId)
            ->where('status', OrderStatus::Pending->value)
            ->where('side', $side->value)
            ->where('type', OrderType::Limit->value)
            ->where('limit_price', $operator, $price);
    }
}
