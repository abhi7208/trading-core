<?php

namespace App\Listeners;

use App\Events\OrderEvent;
use App\Models\AuditLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Context;

/**
 * QUEUED listener (ShouldQueue): writing the audit row happens in a background worker,
 * so it never slows down the API or the settlement transaction.
 *
 * It only ever sees events that were committed (see OrderEvent), so the audit trail
 * contains exactly what really happened.
 */
class WriteAuditLog implements ShouldQueue
{
    public string $queue = 'default';

    public int $tries = 5;

    public function handle(OrderEvent $event): void
    {
        AuditLog::create([
            'actor_id' => $event->actorId,           // null = system (worker / scheduler)
            'action' => $event->action(),
            'subject_type' => 'order',
            'subject_id' => $event->orderId,
            'context' => [
                'before' => $event->before,
                'after' => $event->after,
                // Laravel's Context travels from the HTTP request into queued jobs/listeners.
                'correlation_id' => Context::get('correlation_id'),
            ],
            'ip_address' => $event->ipAddress,
        ]);
    }
}
