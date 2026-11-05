<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WalletResource;
use App\Models\Position;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    /** GET /api/v1/wallet */
    public function __invoke(Request $request): WalletResource
    {
        /** @var User $user */
        $user = $request->user();

        $wallet = Wallet::query()
            ->where('user_id', $user->id)
            ->where('currency', config('trading.currency'))
            ->firstOrFail();

        $positions = Position::query()
            ->where('user_id', $user->id)
            ->with('asset')
            ->orderBy('asset_id')
            ->get();

        return new WalletResource($wallet, $positions);
    }
}
