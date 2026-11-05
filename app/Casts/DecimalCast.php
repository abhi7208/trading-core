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

        if (is_float($value)) {
            throw new \InvalidArgumentException("Refusing to store a float in {$key}; pass a string or Decimal.");
        }

        return Decimal::of($value)->toStorage();
    }
}
