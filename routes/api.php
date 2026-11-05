<?php

use App\Http\Controllers\Api\MarketSummaryController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\WalletController;
use Illuminate\Support\Facades\Route;

// Every route here is under /api (added by bootstrap/app.php) and requires a Sanctum token.
Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Route::post('orders', [OrderController::class, 'store'])->middleware('throttle:orders');
    Route::get('orders', [OrderController::class, 'index']);
    Route::get('orders/{order}', [OrderController::class, 'show']);
    Route::delete('orders/{order}', [OrderController::class, 'destroy']);

    Route::get('wallet', WalletController::class);
    Route::get('market/summary', MarketSummaryController::class);
});
