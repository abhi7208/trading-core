<?php

namespace App\Exceptions;

use App\Support\Decimal;

class InsufficientPosition extends DomainError
{
    public function __construct(private readonly Decimal $required, private readonly Decimal $available)
    {
        parent::__construct('Not enough available quantity to sell.');
    }

    public function errorCode(): string
    {
        return 'INSUFFICIENT_POSITION';
    }

    public function details(): array
    {
        return ['required' => (string) $this->required, 'available' => (string) $this->available];
    }
}
