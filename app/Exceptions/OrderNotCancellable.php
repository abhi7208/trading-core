<?php

namespace App\Exceptions;

use App\Enums\OrderStatus;

class OrderNotCancellable extends DomainError
{
    public function __construct(private readonly OrderStatus $status)
    {
        parent::__construct("Only PENDING orders can be cancelled; this order is {$status->value}.");
    }

    public function errorCode(): string
    {
        return 'ORDER_NOT_CANCELLABLE';
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public function details(): array
    {
        return ['status' => $this->status->value];
    }
}
