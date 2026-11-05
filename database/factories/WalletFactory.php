<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Creates an EMPTY wallet. Fund it through LedgerWriter::deposit() so the ledger
 * always matches (tests use the fundedTrader() helper for that).
 *
 * @extends Factory<Wallet>
 */
class WalletFactory extends Factory
{
    protected $model = Wallet::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'currency' => 'USD',
            'available_balance' => '0',
            'reserved_balance' => '0',
        ];
    }
}
