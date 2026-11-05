<?php

namespace App\Models;

use Database\Factories\AssetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A tradable instrument such as BTC-USD.
 *
 * @property int $id
 * @property string $symbol
 * @property string $name
 * @property int $price_precision
 * @property int $quantity_precision
 * @property bool $is_tradable
 */
class Asset extends Model
{
    /** @use HasFactory<AssetFactory> */
    use HasFactory;

    protected $fillable = ['symbol', 'name', 'price_precision', 'quantity_precision', 'is_tradable'];

    protected function casts(): array
    {
        return [
            'price_precision' => 'integer',
            'quantity_precision' => 'integer',
            'is_tradable' => 'boolean',
        ];
    }
}
