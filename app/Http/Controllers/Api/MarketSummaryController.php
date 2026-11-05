<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MarketSummary;
use Illuminate\Http\JsonResponse;

class MarketSummaryController extends Controller
{
    /** GET /api/v1/market/summary */
    public function __invoke(MarketSummary $summary): JsonResponse
    {
        return response()->json(['data' => $summary->get()]);
    }
}
