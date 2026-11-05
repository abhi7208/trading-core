<?php

namespace App\Models;

use App\Casts\DecimalCast;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One historical price. Written in batches by market:simulate (via the query builder,
 * not this model, for speed). The CURRENT price lives in Redis, see PriceStore.
 *
 * @property int $id
 * @property int $asset_id
 * @property Decimal $price
 * @property Carbon $recorded_at
 */
class MarketTick extends Model
{
    public $timestamps = false;

    protected $fillable = ['asset_id', 'price', 'recorded_at'];

    protected function casts(): array
    {
        return [
            'price' => DecimalCast::class,
            'recorded_at' => 'datetime',
        ];
    }
}
