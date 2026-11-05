<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Base class for "expected" business errors (not bugs). The exception handler in
 * bootstrap/app.php turns every DomainError into the standard JSON error shape:
 *
 *   { "error": { "code": "INSUFFICIENT_FUNDS", "message": "...", "details": {} } }
 */
abstract class DomainError extends RuntimeException
{
    abstract public function errorCode(): string;

    public function httpStatus(): int
    {
        return 422;
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        return [];
    }
}
