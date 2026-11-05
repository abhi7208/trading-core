<?php

namespace App\Data;

use App\Enums\OrderSide;
use App\Enums\OrderType;
use App\Support\Decimal;
use Carbon\CarbonImmutable;

/**
 * DTO ("data transfer object"): a typed, read-only bag of the validated order input.
 * The controller builds it from the Form Request and hands it to the PlaceOrder action,
 * so the action never touches the raw HTTP request.
 */
final readonly class PlaceOrderData
{
    public function __construct(
        public int $userId,
        public int $assetId,
        public OrderSide $side,
        public OrderType $type,
        public Decimal $quantity,
        public ?Decimal $limitPrice,
        public ?CarbonImmutable $expiresAt,
        public string $idempotencyKey,
        public string $requestHash,
        public ?string $ipAddress,
    ) {}
}
