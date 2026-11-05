<?php

namespace App\Http\Resources;

use App\Models\Execution;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Execution */
class ExecutionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'price' => $this->price->toStorage(),
            'quantity' => $this->quantity->toStorage(),
            'notional' => $this->notional->toStorage(),
            'fee' => $this->fee->toStorage(),
            'executed_at' => $this->executed_at->toIso8601String(),
        ];
    }
}
