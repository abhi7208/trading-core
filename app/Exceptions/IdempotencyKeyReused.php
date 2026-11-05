<?php

namespace App\Exceptions;

class IdempotencyKeyReused extends DomainError
{
    public function __construct()
    {
        parent::__construct('This Idempotency-Key was already used with a different request body.');
    }

    public function errorCode(): string
    {
        return 'IDEMPOTENCY_KEY_REUSED';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
