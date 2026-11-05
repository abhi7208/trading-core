<?php

namespace Database\Seeders;

use App\Models\Asset;
use App\Models\Position;
use App\Models\User;
use App\Models\Wallet;
use App\Services\LedgerWriter;
use App\Support\Decimal;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 3 traders with KNOWN API tokens (listed in README.md) and funded wallets.
 * Funding goes through LedgerWriter so the ledger reconciles from the very first row.
 */
class TraderSeeder extends Seeder
{
    /** email => [name, plain API token, USD deposit, opening holdings] */
    public const TRADERS = [
        'alice@example.com' => ['Alice', 'alice-demo-token-a1b2c3d4e5f6', '100000.00', ['BTC-USD' => ['1.5', '60000']]],
        'bob@example.com' => ['Bob', 'bob-demo-token-f6e5d4c3b2a1', '50000.00', ['AAPL' => ['100', '180']]],
        'carol@example.com' => ['Carol', 'carol-demo-token-0a9b8c7d6e5f', '10000.00', ['ETH-USD' => ['10', '3000']]],
    ];

    public function run(LedgerWriter $ledger): void
    {
        foreach (self::TRADERS as $email => [$name, $token, $deposit, $holdings]) {
            $user = User::query()->updateOrCreate(['email' => $email], [
                'name' => $name,
                'password' => 'password',
            ]);

            // Sanctum stores only the SHA-256 hash of a token. A token sent without the
            // "id|" prefix is looked up by that hash, so these plain strings work as Bearer tokens.
            $user->tokens()->delete();
            $user->tokens()->create([
                'name' => 'demo',
                'token' => hash('sha256', $token),
                'abilities' => ['*'],
            ]);

            $wallet = Wallet::query()->firstOrCreate(
                ['user_id' => $user->id, 'currency' => config('trading.currency')],
                ['available_balance' => '0', 'reserved_balance' => '0'],
            );

            if ($wallet->ledgerEntries()->doesntExist()) {
                DB::transaction(function () use ($ledger, $wallet, $deposit) {
                    $locked = Wallet::query()->whereKey($wallet->id)->lockForUpdate()->firstOrFail();
                    $ledger->deposit($locked, Decimal::of($deposit));
                });
            }

            // Opening holdings (as if transferred in), so SELL orders can be tried right away.
            foreach ($holdings as $symbol => [$quantity, $averageCost]) {
                Position::query()->firstOrCreate(
                    ['user_id' => $user->id, 'asset_id' => Asset::query()->where('symbol', $symbol)->value('id')],
                    ['quantity' => $quantity, 'reserved_quantity' => '0', 'average_cost' => $averageCost],
                );
            }
        }
    }
}
