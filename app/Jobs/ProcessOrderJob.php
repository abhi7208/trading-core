<?php

namespace App\Jobs;

use App\Actions\CloseOrder;
use App\Actions\SettleOrder;
use App\Enums\OrderStatus;
use App\Enums\RejectionReason;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Settles ONE order, on the dedicated "orders" queue.
 *
 * Assume this job can run twice, run late, or crash halfway:
 *  - twice  -> SettleOrder re-checks the status under a row lock, so the second run is a no-op
 *  - late   -> SettleOrder uses the price at run time and checks expiry and price freshness
 *  - crash  -> settlement is one DB transaction, so a crash rolls everything back; we retry
 *
 * Business outcomes (wait / reject) are NOT exceptions, so they are never retried.
 * Only unexpected errors (DB deadlock, lost connection, Redis down) throw and get retried.
 */
class ProcessOrderJob implements ShouldQueue
{
    use Queueable;

    /** Total attempts for transient errors. */
    public int $tries = 5;

    public function __construct(public readonly int $orderId)
    {
        $this->onQueue('orders');
    }

    /**
     * Wait 1s, then 5s, 15s, 30s between attempts (exponential-ish backoff).
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [1, 5, 15, 30];
    }

    public function handle(SettleOrder $settle): void
    {
        $settle->handle($this->orderId);
    }

    /**
     * Called by Laravel once all attempts are used up. Mark the order FAILED and release
     * its reservation, so the trader's money is never stuck. CloseOrder is itself
     * idempotent: if the order already filled, it does nothing.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('ProcessOrderJob failed permanently', [
            'order_id' => $this->orderId,
            'error' => $exception?->getMessage(),
        ]);

        app(CloseOrder::class)->handle($this->orderId, OrderStatus::Failed, RejectionReason::JobFailed->value);
    }
}
