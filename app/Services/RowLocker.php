<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Position;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

/**
 * Takes row locks (SELECT ... FOR UPDATE) in ONE agreed order.
 *
 *     LOCK ORDER:   1. order   ->   2. wallet   ->   3. position
 *
 * WHY: a deadlock happens when process A holds row X and waits for row Y while process B
 * holds Y and waits for X. If every code path locks rows in the same order, that circle
 * can never form. Every action in this app takes its locks through this class, in this
 * order, and only inside a DB transaction (locks are held until COMMIT/ROLLBACK).
 *
 *  - PlaceOrder:  wallet (BUY)  or  position (SELL)      (no order row exists yet)
 *  - CloseOrder:  order -> wallet (BUY)  or  order -> position (SELL)
 *  - SettleOrder: order -> wallet -> position
 */
class RowLocker
{
    public function order(int $orderId): ?Order
    {
        $this->assertInTransaction();

        return Order::query()->whereKey($orderId)->lockForUpdate()->first();
    }

    public function wallet(int $userId): Wallet
    {
        $this->assertInTransaction();

        return Wallet::query()
            ->where('user_id', $userId)
            ->where('currency', config('trading.currency'))
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Lock a position. With $create = true the row is created first if missing
     * (a BUY can be someone's first purchase of an asset). INSERT IGNORE makes two
     * concurrent "first buys" safe: one inserts, the other silently skips, then both lock.
     */
    public function position(int $userId, int $assetId, bool $create = false): ?Position
    {
        $this->assertInTransaction();

        if ($create) {
            Position::query()->insertOrIgnore([
                'user_id' => $userId,
                'asset_id' => $assetId,
                'quantity' => '0',
                'reserved_quantity' => '0',
                'average_cost' => '0',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return Position::query()
            ->where('user_id', $userId)
            ->where('asset_id', $assetId)
            ->lockForUpdate()
            ->first();
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Row locks must be taken inside a DB transaction.');
        }
    }
}
