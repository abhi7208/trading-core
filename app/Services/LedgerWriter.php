<?php

namespace App\Services;

use App\Enums\LedgerBucket;
use App\Enums\LedgerEntryType;
use App\Models\LedgerEntry;
use App\Models\Wallet;
use App\Support\Decimal;
use LogicException;

/**
 * THE ONLY place that changes a wallet balance.
 *
 * Every change does two things together:
 *   1. changes the bucket balance on the wallet row, and
 *   2. appends a ledger entry with the signed amount and the balance after.
 *
 * Because every balance change has a ledger row, at any moment:
 *   SUM(amount) of AVAILABLE entries == wallet.available_balance
 *   SUM(amount) of RESERVED  entries == wallet.reserved_balance
 *
 * The caller MUST already hold a row lock on the wallet (lockForUpdate) inside a
 * DB transaction. That is what makes this safe under concurrency.
 */
class LedgerWriter
{
    public function record(
        Wallet $lockedWallet,
        LedgerBucket $bucket,
        Decimal $signedAmount,
        LedgerEntryType $type,
        ?int $orderId = null,
    ): LedgerEntry {
        if ($signedAmount->isZero()) {
            throw new LogicException('Ledger amounts must not be zero.');
        }

        $column = $bucket === LedgerBucket::Available ? 'available_balance' : 'reserved_balance';
        /** @var Decimal $current */
        $current = $lockedWallet->{$column};
        $after = $current->plus($signedAmount);

        if ($after->isNegative()) {
            // Should never happen: callers check first. The DB CHECK constraint is the backstop.
            throw new LogicException("Wallet {$lockedWallet->id} {$column} would go negative.");
        }

        $lockedWallet->{$column} = $after;
        $lockedWallet->save();

        return LedgerEntry::create([
            'wallet_id' => $lockedWallet->id,
            'order_id' => $orderId,
            'type' => $type,
            'bucket' => $bucket,
            'amount' => $signedAmount,
            'balance_after' => $after,
        ]);
    }

    /** Move cash from available to reserved (BUY order accepted). */
    public function reserve(Wallet $lockedWallet, Decimal $amount, int $orderId): void
    {
        $this->record($lockedWallet, LedgerBucket::Available, $amount->negated(), LedgerEntryType::Reserve, $orderId);
        $this->record($lockedWallet, LedgerBucket::Reserved, $amount, LedgerEntryType::Reserve, $orderId);
    }

    /** Move cash from reserved back to available (cancel, reject, fail, unused part of a fill). */
    public function release(Wallet $lockedWallet, Decimal $amount, int $orderId): void
    {
        if ($amount->isZero()) {
            return;
        }
        $this->record($lockedWallet, LedgerBucket::Reserved, $amount->negated(), LedgerEntryType::Release, $orderId);
        $this->record($lockedWallet, LedgerBucket::Available, $amount, LedgerEntryType::Release, $orderId);
    }

    public function deposit(Wallet $lockedWallet, Decimal $amount): void
    {
        $this->record($lockedWallet, LedgerBucket::Available, $amount, LedgerEntryType::Deposit);
    }
}
