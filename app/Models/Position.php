<?php

namespace App\Models;

use App\Casts\DecimalCast;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How much of an asset a user holds.
 *  - quantity          = free to sell
 *  - reserved_quantity = locked by open SELL orders
 *
 * @property int $id
 * @property int $user_id
 * @property int $asset_id
 * @property Decimal $quantity
 * @property Decimal $reserved_quantity
 * @property Decimal $average_cost
 * @property-read Asset $asset
 */
class Position extends Model
{
    protected $fillable = ['user_id', 'asset_id', 'quantity', 'reserved_quantity', 'average_cost'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity' => DecimalCast::class,
            'reserved_quantity' => DecimalCast::class,
            'average_cost' => DecimalCast::class,
        ];
    }

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function totalQuantity(): Decimal
    {
        return $this->quantity->plus($this->reserved_quantity);
    }
}
