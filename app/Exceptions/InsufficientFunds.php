<?php

namespace App\Exceptions;

use App\Support\Decimal;

class InsufficientFunds extends DomainError
{
    public function __construct(private readonly Decimal $required, private readonly Decimal $available)
    {
        parent::__construct('Not enough available balance for this order.');
    }

    public function errorCode(): string
    {
        return 'INSUFFICIENT_FUNDS';
    }

    public function details(): array
    {
        return ['required' => (string) $this->required, 'available' => (string) $this->available];
    }
}
