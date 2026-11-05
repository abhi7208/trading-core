<?php

namespace App\Data;

/**
 * What PlaceOrder returns: the exact response body, and whether it was a replay.
 */
final readonly class PlacementResult
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        public int $status,
        public array $body,
        public bool $replayed,
    ) {}
}
