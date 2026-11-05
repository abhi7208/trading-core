<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Controls exactly what an order looks like in JSON. Internal ids and idempotency keys
 * are not exposed. Decimals are strings ("0.50000000") so clients never get floats.
 *
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'asset' => $this->asset->symbol,
            'side' => $this->side->value,
            'type' => $this->type->value,
            'quantity' => $this->quantity->toStorage(),
            'limit_price' => $this->limit_price?->toStorage(),
            'reserved_amount' => $this->reserved_amount->toStorage(),
            'status' => $this->status->value,
            'failure_reason' => $this->failure_reason,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'filled_at' => $this->filled_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'executions' => ExecutionResource::collection($this->whenLoaded('executions')),
        ];
    }
}
