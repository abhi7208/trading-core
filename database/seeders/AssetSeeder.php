<?php

namespace Database\Seeders;

use App\Models\Asset;
use Illuminate\Database\Seeder;

class AssetSeeder extends Seeder
{
    /** symbol => [name, price precision, quantity precision, starting price] */
    public const ASSETS = [
        'BTC-USD' => ['Bitcoin', 2, 6, '65000.00'],
        'ETH-USD' => ['Ether', 2, 5, '3200.00'],
        'AAPL' => ['Apple Inc.', 2, 4, '190.00'],
        'TSLA' => ['Tesla Inc.', 2, 4, '240.00'],
        'XAU-USD' => ['Gold (troy ounce)', 2, 3, '2400.00'],
    ];

    public function run(): void
    {
        foreach (self::ASSETS as $symbol => [$name, $pricePrecision, $quantityPrecision]) {
            Asset::query()->updateOrCreate(['symbol' => $symbol], [
                'name' => $name,
                'price_precision' => $pricePrecision,
                'quantity_precision' => $quantityPrecision,
                'is_tradable' => true,
            ]);
        }
    }
}
