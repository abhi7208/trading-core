<?php

namespace App\Console\Commands;

use App\Enums\LedgerBucket;
use App\Support\Decimal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Audit: rebuild every wallet's balances from the ledger and compare.
 *
 *   php artisan ledger:verify
 *
 * Exit code 1 if any wallet does not reconcile. Run it in CI, after load tests, or nightly.
 */
class VerifyLedger extends Command
{
    protected $signature = 'ledger:verify';

    protected $description = 'Check that every wallet balance equals the sum of its ledger entries';

    public function handle(): int
    {
        $rows = DB::table('wallets')
            ->leftJoin('ledger_entries', 'ledger_entries.wallet_id', '=', 'wallets.id')
            ->select('wallets.id', 'wallets.available_balance', 'wallets.reserved_balance')
            ->selectRaw('COALESCE(SUM(CASE WHEN ledger_entries.bucket = ? THEN ledger_entries.amount END), 0) AS ledger_available', [LedgerBucket::Available->value])
            ->selectRaw('COALESCE(SUM(CASE WHEN ledger_entries.bucket = ? THEN ledger_entries.amount END), 0) AS ledger_reserved', [LedgerBucket::Reserved->value])
            ->groupBy('wallets.id', 'wallets.available_balance', 'wallets.reserved_balance')
            ->get();

        $same = fn ($a, $b) => Decimal::of((string) $a)->isEqualTo(Decimal::of((string) $b));
        $bad = $rows->filter(fn ($r) => ! $same($r->available_balance, $r->ledger_available)
            || ! $same($r->reserved_balance, $r->ledger_reserved));

        if ($bad->isNotEmpty()) {
            $this->table(['wallet', 'available', 'ledger available', 'reserved', 'ledger reserved'],
                $bad->map(fn ($r) => [$r->id, $r->available_balance, $r->ledger_available, $r->reserved_balance, $r->ledger_reserved])->all());
            $this->error("{$bad->count()} wallet(s) do not reconcile.");

            return self::FAILURE;
        }

        $this->info("All {$rows->count()} wallets reconcile with the ledger.");

        return self::SUCCESS;
    }
}
