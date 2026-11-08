<?php

namespace App\Actions;

use App\Enums\LedgerBucket;
use App\Enums\LedgerEntryType;
use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\RejectionReason;
use App\Events\OrderFilled;
use App\Models\Execution;
use App\Models\Order;
use App\Services\LedgerWriter;
use App\Services\OrderMatcher;
use App\Services\PriceStore;
use App\Services\RowLocker;
use App\Support\Decimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Try to fill one order at the latest price. Safe to call any number of times.
 *
 * Returns what happened: filled | waiting | rejected | expired | skipped.
 *
 * IDEMPOTENCY: the order row is locked and its status re-checked INSIDE the transaction.
 * A second run (job retried, duplicate job, sweep + placement job racing) finds the order
 * no longer PENDING and does nothing. As a second safety net, executions.order_id is UNIQUE.
 *
 * ATOMICITY: reserved cash, position, execution row, ledger entries and order status all
 * change in ONE transaction. A crash halfway rolls ALL of it back.
 */
class SettleOrder
{
    public function __construct(
        private readonly RowLocker $locks,
        private readonly LedgerWriter $ledger,
        private readonly PriceStore $prices,
        private readonly OrderMatcher $matcher,
        private readonly CloseOrder $close,
    ) {}

    public function handle(int $orderId): string
    {
        // Cheap unlocked read first: most duplicate runs stop here without taking locks.
        $order = Order::query()->find($orderId);
        if ($order === null || ! $order->isPending()) {
            return 'skipped';
        }

        $now = CarbonImmutable::now();

        if ($order->expires_at !== null && $order->expires_at->lessThanOrEqualTo($now)) {
            $this->close->handle($order->id, OrderStatus::Cancelled, RejectionReason::Expired->value);

            return 'expired';
        }

        $decision = $this->matcher->decide(
            $order->side, $order->type, $order->limit_price, $this->prices->latest($order->asset_id), $now,
        );

        if ($decision->isWait()) {
            return 'waiting';                       // stays PENDING; the next price sweep re-checks it
        }

        if ($decision->isReject()) {
            $this->close->handle($order->id, OrderStatus::Rejected, $decision->reason->value ?? 'REJECTED');

            return 'rejected';
        }

        $price = $decision->price ?? throw new LogicException('Fill decision without a price.');

        $outcome = DB::transaction(fn () => $this->fill($orderId, $price, $now), attempts: 3);

        if ($outcome === 'collar') {
            // MARKET BUY whose price jumped above what we reserved: reject, never overspend.
            $this->close->handle($orderId, OrderStatus::Rejected, RejectionReason::PriceMovedBeyondCollar->value);

            return 'rejected';
        }

        return $outcome;
    }

    private function fill(int $orderId, Decimal $price, CarbonImmutable $now): string
    {
        $order = $this->locks->order($orderId);                                   // lock 1: order
        if ($order === null || ! $order->isPending()) {
            return 'skipped';                                                     // someone else finished it
        }

        // MARKET BUY whose price jumped above what we reserved: stop before touching anything.
        if ($order->side === OrderSide::Buy
            && $order->quantity->times($price, RoundingMode::Up)->isGreaterThan($order->reserved_amount)) {
            return 'collar';
        }

        $wallet = $this->locks->wallet($order->user_id);                          // lock 2: wallet
        $position = $this->locks->position($order->user_id, $order->asset_id, create: true)
            ?? throw new LogicException('Position could not be created.');       // lock 3: position

        $before = $order->snapshot();
        $quantity = $order->quantity;

        if ($order->side === OrderSide::Buy) {
            // Buyer pays: round UP.
            $cost = $quantity->times($price, RoundingMode::Up);

            // Pay for the trade out of the reserved bucket...
            $this->ledger->record($wallet, LedgerBucket::Reserved, $cost->negated(), LedgerEntryType::TradeBuy, $order->id);
            // ...and give back the unused part (we reserved at limit price, filled lower).
            $this->ledger->release($wallet, $order->reserved_amount->minus($cost), $order->id);

            // New average cost = (old total cost + this cost) / new total quantity.
            $oldTotal = $position->totalQuantity();
            $newTotal = $oldTotal->plus($quantity);
            $position->average_cost = $oldTotal->times($position->average_cost)
                ->plus($cost)
                ->dividedBy($newTotal);
            $position->quantity = $position->quantity->plus($quantity);
            $notional = $cost;
        } else {
            // Seller receives: round DOWN.
            $proceeds = $quantity->times($price, RoundingMode::Down);

            $position->reserved_quantity = $position->reserved_quantity->minus($quantity);
            if ($position->totalQuantity()->isZero()) {
                $position->average_cost = Decimal::zero();
            }
            if ($proceeds->isPositive()) {
                $this->ledger->record($wallet, LedgerBucket::Available, $proceeds, LedgerEntryType::TradeSell, $order->id);
            }
            $notional = $proceeds;
        }

        $position->save();

        Execution::create([
            'order_id' => $order->id,        // UNIQUE: a second settlement would fail right here
            'asset_id' => $order->asset_id,
            'price' => $price,
            'quantity' => $quantity,
            'notional' => $notional,
            'fee' => Decimal::zero(),
            'executed_at' => $now,
        ]);

        $order->status = OrderStatus::Filled;
        $order->filled_at = $now->toMutable();
        $order->save();

        // Held until COMMIT; thrown away if anything above fails and the transaction rolls back.
        event(new OrderFilled($order->id, null, $before, $order->snapshot()));

        return 'filled';
    }
}
