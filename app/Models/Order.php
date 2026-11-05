<?php

namespace App\Models;

use App\Casts\DecimalCast;
use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Support\Decimal;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int $asset_id
 * @property OrderSide $side
 * @property OrderType $type
 * @property Decimal $quantity
 * @property Decimal|null $limit_price
 * @property Decimal $reserved_amount
 * @property OrderStatus $status
 * @property string $idempotency_key
 * @property string|null $failure_reason
 * @property Carbon|null $expires_at
 * @property Carbon|null $filled_at
 * @property Carbon|null $cancelled_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Asset $asset
 * @property-read User $user
 */
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    protected $fillable = [
        'uuid', 'user_id', 'asset_id', 'side', 'type', 'quantity', 'limit_price',
        'reserved_amount', 'status', 'idempotency_key', 'failure_reason', 'expires_at',
        'filled_at', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'side' => OrderSide::class,     // DB string <-> PHP enum
            'type' => OrderType::class,
            'status' => OrderStatus::class,
            'quantity' => DecimalCast::class,
            'limit_price' => DecimalCast::class,
            'reserved_amount' => DecimalCast::class,
            'expires_at' => 'datetime',
            'filled_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** URLs use /orders/{uuid}, so ids are not guessable. */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /** @return HasMany<Execution, $this> */
    public function executions(): HasMany
    {
        return $this->hasMany(Execution::class);
    }

    public function isPending(): bool
    {
        return $this->status === OrderStatus::Pending;
    }

    /**
     * Small "photo" of the order's important fields, used for before/after audit entries.
     *
     * @return array<string, string|null>
     */
    public function snapshot(): array
    {
        return [
            'status' => $this->status->value,
            'quantity' => (string) $this->quantity,
            'limit_price' => $this->limit_price?->__toString(),
            'reserved_amount' => (string) $this->reserved_amount,
            'failure_reason' => $this->failure_reason,
        ];
    }
}
