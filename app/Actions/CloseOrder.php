<?php

namespace App\Actions;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Events\OrderCancelled;
use App\Events\OrderFailed;
use App\Events\OrderRejected;
use App\Exceptions\OrderNotCancellable;
use App\Models\Order;
use App\Services\LedgerWriter;
use App\Services\RowLocker;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * End a PENDING order WITHOUT filling it, and give back what it reserved.
 * Used for: user cancel, automatic expiry, business rejection, permanent job failure.
 *
 * "Release exactly once" comes from this pattern:
 *   1. lock the order row  (SELECT ... FOR UPDATE)
 *   2. if it is no longer PENDING, someone else already finished it -> do nothing
 *   3. otherwise release + change status, in the same transaction
 *
 * A worker filling the same order takes the SAME order-row lock first (see SettleOrder),
 * so the two can never both pass step 2. Whoever locks first wins; the other sees a
 * final status and backs off.
 */
class CloseOrder
{
    public function __construct(
        private readonly RowLocker $locks,
        private readonly LedgerWriter $ledger,
    ) {}

    /**
     * @param  bool  $throwIfNotPending  true for user cancels (they get a 409), false for system paths
     * @return Order|null the closed order, or null if it was already final
     */
    public function handle(
        int $orderId,
        OrderStatus $finalStatus,
        string $reason,
        ?int $actorId = null,
        ?string $ipAddress = null,
        bool $throwIfNotPending = false,
    ): ?Order {
        if (! in_array($finalStatus, [OrderStatus::Cancelled, OrderStatus::Rejected, OrderStatus::Failed], true)) {
            throw new LogicException("CloseOrder cannot move an order to {$finalStatus->value}.");
        }

        return DB::transaction(function () use ($orderId, $finalStatus, $reason, $actorId, $ipAddress, $throwIfNotPending) {
            $order = $this->locks->order($orderId);                       // lock 1: order

            if ($order === null || ! $order->isPending()) {
                if ($throwIfNotPending && $order !== null) {
                    throw new OrderNotCancellable($order->status);
                }

                return null;                                             // already final: no double release
            }

            $before = $order->snapshot();

            if ($order->side === OrderSide::Buy) {
                $wallet = $this->locks->wallet($order->user_id);          // lock 2: wallet
                $this->ledger->release($wallet, $order->reserved_amount, $order->id);
            } else {
                $position = $this->locks->position($order->user_id, $order->asset_id)
                    ?? throw new LogicException("Position missing for SELL order {$order->id}.");
                $position->reserved_quantity = $position->reserved_quantity->minus($order->quantity);
                $position->quantity = $position->quantity->plus($order->quantity);
                $position->save();
            }

            $order->status = $finalStatus;
            $order->failure_reason = $reason;
            if ($finalStatus === OrderStatus::Cancelled) {
                $order->cancelled_at = now();
            }
            $order->save();

            $eventClass = match ($finalStatus) {
                OrderStatus::Cancelled => OrderCancelled::class,
                OrderStatus::Rejected => OrderRejected::class,
                default => OrderFailed::class,
            };
            // Held until COMMIT (ShouldDispatchAfterCommit); discarded on rollback.
            event(new $eventClass($order->id, $actorId, $before, $order->snapshot(), $ipAddress));

            return $order;
        }, attempts: 3);
    }
}
