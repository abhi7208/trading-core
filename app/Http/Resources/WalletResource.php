<?php

namespace App\Http\Resources;

use App\Models\Position;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/** @mixin Wallet */
class WalletResource extends JsonResource
{
    /**
     * @param  Collection<int, Position>  $positions
     */
    public function __construct(Wallet $wallet, private readonly Collection $positions)
    {
        parent::__construct($wallet);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'currency' => $this->currency,
            'available_balance' => $this->available_balance->toStorage(),
            'reserved_balance' => $this->reserved_balance->toStorage(),
            'positions' => PositionResource::collection($this->positions),
        ];
    }
}
