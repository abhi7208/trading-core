<?php

namespace App\Actions;

use App\Data\PlacementResult;
use App\Data\PlaceOrderData;
use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Exceptions\IdempotencyKeyReused;
use App\Exceptions\InsufficientFunds;
use App\Exceptions\InsufficientPosition;
use App\Exceptions\NoFreshPrice;
use App\Http\Resources\OrderResource;
use App\Jobs\ProcessOrderJob;
use App\Models\Asset;
use App\Models\IdempotencyKey;
use App\Models\Order;
use App\Services\LedgerWriter;
use App\Services\OrderMatcher;
use App\Services\PriceStore;
use App\Services\RowLocker;
use App\Support\Decimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Accept an order and reserve the money (BUY) or the holdings (SELL) right away.
 *
 * Flow:
 *   1. Seen this Idempotency-Key before? Return the original response (or 409).
 *   2. In ONE transaction:
 *        lock the wallet (BUY) or position (SELL) row       <- concurrency safety
 *        check there is enough available
 *        create the order, move available -> reserved, remember the response
 *   3. AFTER the commit, queue ProcessOrderJob.
 */
class PlaceOrder
{
    public function __construct(
        private readonly RowLocker $locks,
        private readonly LedgerWriter $ledger,
        private readonly PriceStore $prices,
    ) {}

    public function handle(PlaceOrderData $data): PlacementResult
    {
        $existing = $this->findKey($data);
        if ($existing !== null) {
            return $this->replay($existing, $data);
        }

        try {
            // attempts: 3 -> Laravel retries the whole closure if MySQL reports a deadlock.
            [$orderId, $result] = DB::transaction(fn () => $this->create($data), attempts: 3);
        } catch (UniqueConstraintViolationException) {
            // Two identical requests arrived at the same moment. The other one committed
            // first, so the UNIQUE(user_id, idempotency_key) index rejected ours and the whole
            // transaction rolled back (nothing was reserved twice). Return the winner's response.
            $existing = $this->findKey($data) ?? throw new \LogicException('Idempotency record missing after conflict.');

            return $this->replay($existing, $data);
        }

        // Our transaction has COMMITTED by this line, so a worker that picks up the job is
        // guaranteed to see the order row. afterCommit() also covers the case where a caller
        // wrapped us in an outer transaction: the job is queued only when that one commits.
        ProcessOrderJob::dispatch($orderId)->afterCommit();

        return $result;
    }

    /**
     * @return array{0: int, 1: PlacementResult}
     */
    private function create(PlaceOrderData $data): array
    {
        $asset = Asset::query()->findOrFail($data->assetId);
        $reservedAmount = Decimal::zero();
        $wallet = null;
        $position = null;

        if ($data->side === OrderSide::Buy) {
            $price = $data->type === OrderType::Limit
                ? $data->limitPrice
                : $this->marketReservationPrice($asset);

            // Round UP: never reserve less than the order could cost.
            $reservedAmount = $data->quantity->times($price ?? Decimal::zero(), RoundingMode::Up);

            // SELECT ... FOR UPDATE: any other request for this wallet now WAITS here until we
            // commit. So two requests can never both see the same "available" amount.
            $wallet = $this->locks->wallet($data->userId);

            if ($wallet->available_balance->isLessThan($reservedAmount)) {
                throw new InsufficientFunds($reservedAmount, $wallet->available_balance);
            }
        } else {
            $position = $this->locks->position($data->userId, $data->assetId);
            $available = $position !== null ? $position->quantity : Decimal::zero();

            if ($available->isLessThan($data->quantity)) {
                throw new InsufficientPosition($data->quantity, $available);
            }
        }

        $order = Order::create([
            'uuid' => (string) Str::uuid7(),
            'user_id' => $data->userId,
            'asset_id' => $data->assetId,
            'side' => $data->side,
            'type' => $data->type,
            'quantity' => $data->quantity,
            'limit_price' => $data->limitPrice,
            'reserved_amount' => $reservedAmount,
            'status' => OrderStatus::Pending,
            'idempotency_key' => $data->idempotencyKey,
            'expires_at' => $this->expiry($data),
        ]);

        if ($wallet !== null) {
            $this->ledger->reserve($wallet, $reservedAmount, $order->id);
        }

        if ($position !== null) {
            $position->quantity = $position->quantity->minus($data->quantity);
            $position->reserved_quantity = $position->reserved_quantity->plus($data->quantity);
            $position->save();
        }

        $order->setRelation('asset', $asset);
        $body = ['data' => (new OrderResource($order))->resolve()];

        IdempotencyKey::create([
            'user_id' => $data->userId,
            'key' => $data->idempotencyKey,
            'request_hash' => $data->requestHash,
            'order_id' => $order->id,
            'response_status' => 202,
            'response_body' => $body,
        ]);

        return [$order->id, new PlacementResult(202, $body, replayed: false)];
    }

    /**
     * MARKET BUY: there is no limit price, so we reserve at latest price + a safety collar
     * (5% by default). If the real fill price is above that, the order is rejected rather
     * than spending money we did not reserve.
     */
    private function marketReservationPrice(Asset $asset): Decimal
    {
        $quote = $this->prices->latest($asset->id);

        if ($quote === null || ! $quote->isFresh(CarbonImmutable::now(), OrderMatcher::MAX_PRICE_AGE_SECONDS)) {
            throw new NoFreshPrice($asset->symbol);
        }

        $collar = Decimal::of((string) config('trading.market_buy_collar'));

        return $quote->price->times(Decimal::of(1)->plus($collar), RoundingMode::Up);
    }

    private function expiry(PlaceOrderData $data): ?CarbonImmutable
    {
        if ($data->type === OrderType::Market) {
            return null;
        }

        return $data->expiresAt ?? CarbonImmutable::now()->addHours((int) config('trading.default_limit_ttl_hours'));
    }

    private function findKey(PlaceOrderData $data): ?IdempotencyKey
    {
        return IdempotencyKey::query()
            ->where('user_id', $data->userId)
            ->where('key', $data->idempotencyKey)
            ->first();
    }

    private function replay(IdempotencyKey $existing, PlaceOrderData $data): PlacementResult
    {
        if (! hash_equals($existing->request_hash, $data->requestHash)) {
            throw new IdempotencyKeyReused;
        }

        return new PlacementResult($existing->response_status, $existing->response_body, replayed: true);
    }
}
