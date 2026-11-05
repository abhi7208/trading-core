<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Base for all order lifecycle events.
 *
 * ShouldDispatchAfterCommit: if we fire the event inside a DB transaction, Laravel holds
 * it until the transaction COMMITS. If the transaction ROLLS BACK, the event is thrown
 * away. So a rolled-back settlement can never produce an event or an audit row.
 *
 * Only plain values (ints, strings, arrays) are stored, so the event can be safely
 * serialized onto the queue for the queued audit listener.
 */
abstract class OrderEvent implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  array<string, string|null>  $before  order state before the change
     * @param  array<string, string|null>  $after  order state after the change
     */
    public function __construct(
        public readonly int $orderId,
        public readonly ?int $actorId,
        public readonly array $before,
        public readonly array $after,
        public readonly ?string $ipAddress = null,
    ) {}

    /** Name written to audit_logs.action, e.g. "order.filled". */
    abstract public function action(): string;
}
