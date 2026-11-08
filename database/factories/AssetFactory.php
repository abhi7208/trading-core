<?php

namespace Database\Factories;

use App\Models\Asset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Asset>
 */
class AssetFactory extends Factory
{
    protected $model = Asset::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'symbol' => strtoupper($this->faker->unique()->lexify('???')).'-USD',
            'name' => $this->faker->company(),
            'price_precision' => 2,
            'quantity_precision' => 4,
            'is_tradable' => true,
        ];
    }

    public function untradable(): static
    {
        return $this->state(['is_tradable' => false]);
    }
}
