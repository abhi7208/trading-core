<?php

namespace App\Console\Commands;

use App\Actions\CloseOrder;
use App\Enums\OrderStatus;
use App\Enums\RejectionReason;
use App\Models\Order;
use Illuminate\Console\Command;

/**
 * Cancels PENDING orders whose expires_at has passed and releases their reservations.
 * Scheduled every minute (routes/console.php). SettleOrder ALSO refuses to fill an
 * expired order, so an order can never fill in the gap between expiry and this command.
 */
class ExpireOrders extends Command
{
    protected $signature = 'orders:expire';

    protected $description = 'Cancel PENDING orders past their expires_at and release reservations';

    public function handle(CloseOrder $close): int
    {
        $count = 0;

        Order::query()
            ->select('id')
            ->where('status', OrderStatus::Pending->value)   // uses index (status, expires_at)
            ->where('expires_at', '<=', now())
            ->chunkById(500, function ($orders) use ($close, &$count) {
                foreach ($orders as $order) {
                    // CloseOrder re-checks PENDING under a row lock, so a fill racing with
                    // this command is safe: whichever locks the order first wins.
                    if ($close->handle($order->id, OrderStatus::Cancelled, RejectionReason::Expired->value) !== null) {
                        $count++;
                    }
                }
            });

        $this->info("Expired {$count} orders.");

        return self::SUCCESS;
    }
}
