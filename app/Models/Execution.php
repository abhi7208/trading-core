<?php

namespace App\Models;

use App\Casts\DecimalCast;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A fill: "this order traded Q units at price P".
 *
 * @property int $id
 * @property int $order_id
 * @property int $asset_id
 * @property Decimal $price
 * @property Decimal $quantity
 * @property Decimal $notional
 * @property Decimal $fee
 * @property Carbon $executed_at
 */
class Execution extends Model
{
    public $timestamps = false;

    protected $fillable = ['order_id', 'asset_id', 'price', 'quantity', 'notional', 'fee', 'executed_at'];

    protected function casts(): array
    {
        return [
            'price' => DecimalCast::class,
            'quantity' => DecimalCast::class,
            'notional' => DecimalCast::class,
            'fee' => DecimalCast::class,
            'executed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
