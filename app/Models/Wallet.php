<?php

namespace App\Models;

use App\Casts\DecimalCast;
use App\Support\Decimal;
use Database\Factories\WalletFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cash for one user in one currency.
 *
 * IMPORTANT: never change the balances directly. Always go through LedgerWriter, which
 * changes the balance AND writes the matching ledger entry in the same transaction.
 *
 * @property int $id
 * @property int $user_id
 * @property string $currency
 * @property Decimal $available_balance
 * @property Decimal $reserved_balance
 */
class Wallet extends Model
{
    /** @use HasFactory<WalletFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'currency', 'available_balance', 'reserved_balance'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'available_balance' => DecimalCast::class,
            'reserved_balance' => DecimalCast::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<LedgerEntry, $this> */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
}
