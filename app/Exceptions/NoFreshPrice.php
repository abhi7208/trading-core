<?php

namespace App\Exceptions;

class NoFreshPrice extends DomainError
{
    public function __construct(string $symbol)
    {
        parent::__construct("No price newer than 30 seconds for {$symbol}; market orders cannot be priced.");
    }

    public function errorCode(): string
    {
        return 'NO_FRESH_PRICE';
    }
}
