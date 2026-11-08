<?php

namespace App\Models;

use App\Casts\DecimalCast;
use App\Enums\LedgerBucket;
use App\Enums\LedgerEntryType;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One money movement. APPEND-ONLY: rows are never updated or deleted.
 * This is enforced twice: here in PHP, and by database triggers (see the migration).
 *
 * @property int $id
 * @property int $wallet_id
 * @property int|null $order_id
 * @property LedgerEntryType $type
 * @property LedgerBucket $bucket
 * @property Decimal $amount
 * @property Decimal $balance_after
 */
class LedgerEntry extends Model
{
    public const UPDATED_AT = null; // only created_at

    protected $fillable = ['wallet_id', 'order_id', 'type', 'bucket', 'amount', 'balance_after'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => LedgerEntryType::class,
            'bucket' => LedgerBucket::class,
            'amount' => DecimalCast::class,
            'balance_after' => DecimalCast::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Ledger entries are append-only.'));
        static::deleting(fn () => throw new LogicException('Ledger entries are append-only.'));
    }
}
