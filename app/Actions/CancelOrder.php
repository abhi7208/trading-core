<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Enums\RejectionReason;
use App\Models\Order;

/**
 * A trader cancels their own PENDING order (ownership is checked by OrderPolicy in the controller).
 */
class CancelOrder
{
    public function __construct(private readonly CloseOrder $close) {}

    public function handle(Order $order, int $actorId, ?string $ipAddress): Order
    {
        /** @var Order $closed (throwIfNotPending=true means null is never returned) */
        $closed = $this->close->handle(
            orderId: $order->id,
            finalStatus: OrderStatus::Cancelled,
            reason: RejectionReason::CancelledByUser->value,
            actorId: $actorId,
            ipAddress: $ipAddress,
            throwIfNotPending: true,
        );

        return $closed;
    }
}
