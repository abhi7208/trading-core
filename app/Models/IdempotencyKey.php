<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property string $key
 * @property string $request_hash
 * @property int $order_id
 * @property int $response_status
 * @property array<string, mixed> $response_body
 */
class IdempotencyKey extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'key', 'request_hash', 'order_id', 'response_status', 'response_body'];

    protected function casts(): array
    {
        return ['response_body' => 'array'];
    }
}
