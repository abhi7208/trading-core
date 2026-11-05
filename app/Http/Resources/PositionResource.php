<?php

namespace App\Http\Resources;

use App\Models\Position;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Position */
class PositionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'asset' => $this->asset->symbol,
            'available_quantity' => $this->quantity->toStorage(),
            'reserved_quantity' => $this->reserved_quantity->toStorage(),
            'average_cost' => $this->average_cost->toStorage(),
        ];
    }
}
