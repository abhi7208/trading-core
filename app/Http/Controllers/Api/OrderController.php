<?php

namespace App\Http\Controllers\Api;

use App\Actions\CancelOrder;
use App\Actions\PlaceOrder;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListOrdersRequest;
use App\Http\Requests\PlaceOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Asset;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * THIN controller: read the request, call an action, return a resource.
 * No business rules live here.
 */
class OrderController extends Controller
{
    /** POST /api/v1/orders */
    public function store(PlaceOrderRequest $request, PlaceOrder $placeOrder): JsonResponse
    {
        $result = $placeOrder->handle($request->toData());

        return response()
            ->json($result->body, $result->status)
            ->header('Idempotent-Replayed', $result->replayed ? 'true' : 'false');
    }

    /** GET /api/v1/orders?status=PENDING&asset=BTC-USD&per_page=20&cursor=... */
    public function index(ListOrdersRequest $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $query = Order::query()
            ->where('user_id', $user->id)
            ->with('asset')
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('asset')) {
            $assetId = Asset::query()->where('symbol', strtoupper($request->string('asset')->toString()))->value('id');
            $query->where('asset_id', $assetId ?? 0);
        }

        // Cursor pagination: "give me 20 after this id". Stays fast on page 10,000,
        // unlike OFFSET which has to skip all the earlier rows.
        return OrderResource::collection($query->cursorPaginate($request->integer('per_page', 20)));
    }

    /** GET /api/v1/orders/{uuid} */
    public function show(Order $order): OrderResource
    {
        Gate::authorize('view', $order);

        return new OrderResource($order->load(['asset', 'executions']));
    }

    /** DELETE /api/v1/orders/{uuid} */
    public function destroy(Request $request, Order $order, CancelOrder $cancelOrder): OrderResource
    {
        Gate::authorize('cancel', $order);

        /** @var User $user */
        $user = $request->user();
        $cancelled = $cancelOrder->handle($order, $user->id, $request->ip());

        return new OrderResource($cancelled->load(['asset', 'executions']));
    }
}
