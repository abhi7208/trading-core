<?php

namespace App\Casts;

use App\Support\Decimal;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Tells Eloquent: "this DECIMAL column should come out of the database as a Decimal
 * object, and go back in as an exact string". MySQL returns DECIMAL columns as strings,
 * so no float conversion ever happens.
 *
 * @implements CastsAttributes<Decimal|null, Decimal|string|int|null>
 */
class DecimalCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Decimal
    {
        return $value === null ? null : Decimal::of((string) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        // Decimal::of() throws on a float, so no float can ever reach a money column.
        return Decimal::of($value)->toStorage();
    }
}
