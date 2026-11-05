<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates a positive decimal sent AS A STRING, e.g. "0.015".
 *
 * Why strings? JSON numbers are decoded by PHP into floats, and 0.1 as a float is not
 * exactly 0.1. Requiring "0.1" (a string) keeps the exact value the trader typed.
 *
 * Also enforces the asset's precision (max decimals) and fits DECIMAL(20,8) (12 integer digits).
 */
class DecimalString implements ValidationRule
{
    public function __construct(private readonly int $maxDecimals) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^(\d{1,12})(?:\.(\d+))?$/', $value, $m)) {
            $fail('The :attribute must be a positive decimal string such as "10.5".');

            return;
        }

        $decimals = rtrim($m[2] ?? '', '0');
        if (strlen($decimals) > $this->maxDecimals) {
            $fail("The :attribute may have at most {$this->maxDecimals} decimal places for this asset.");

            return;
        }

        if (preg_match('/^[0.]+$/', $value)) {
            $fail('The :attribute must be greater than zero.');
        }
    }
}
