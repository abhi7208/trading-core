<?php

/**
 * A separate PHP PROCESS used by the concurrency tests.
 *
 *   php tests/Concurrency/worker.php place  '{"user_id":1,...,"start_at":1730000000.5}'
 *   php tests/Concurrency/worker.php cancel '{"order_id":7,"user_id":1,"start_at":...}'
 *   php tests/Concurrency/worker.php settle '{"order_id":7,"start_at":...}'
 *
 * Each process boots Laravel on its own (own DB connection, own memory), then sleeps
 * until "start_at" so that all processes hit the database at the same moment.
 * It prints one line of JSON describing what happened.
 */

use App\Actions\CancelOrder;
use App\Actions\PlaceOrder;
use App\Actions\SettleOrder;
use App\Data\PlaceOrderData;
use App\Enums\OrderSide;
use App\Enums\OrderType;
use App\Exceptions\DomainError;
use App\Models\Order;
use App\Support\Decimal;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $action, $json] = $argv;
/** @var array<string, mixed> $args */
$args = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

// Barrier: wait until the agreed start time.
$wait = (float) $args['start_at'] - microtime(true);
if ($wait > 0) {
    usleep((int) ($wait * 1_000_000));
}

// Optional extra delay so a test can give one side of a race a small head start.
if (! empty($args['delay_ms'])) {
    usleep((int) $args['delay_ms'] * 1000);
}

$startedAt = microtime(true);
$result = ['ok' => true];

try {
    switch ($action) {
        case 'place':
            $placed = app(PlaceOrder::class)->handle(new PlaceOrderData(
                userId: (int) $args['user_id'],
                assetId: (int) $args['asset_id'],
                side: OrderSide::from($args['side']),
                type: OrderType::Limit,
                quantity: Decimal::of($args['quantity']),
                limitPrice: Decimal::of($args['limit_price']),
                expiresAt: null,
                idempotencyKey: $args['key'],
                requestHash: hash('sha256', $args['quantity'].'|'.$args['limit_price']),
                ipAddress: null,
            ));
            $result += ['order' => $placed->body['data']['id'], 'replayed' => $placed->replayed];
            break;

        case 'cancel':
            $order = Order::query()->findOrFail((int) $args['order_id']);
            app(CancelOrder::class)->handle($order, (int) $args['user_id'], null);
            $result += ['outcome' => 'cancelled'];
            break;

        case 'settle':
            $result += ['outcome' => app(SettleOrder::class)->handle((int) $args['order_id'])];
            break;

        default:
            throw new InvalidArgumentException("Unknown action {$action}");
    }
} catch (DomainError $e) {
    $result = ['ok' => false, 'code' => $e->errorCode()];
} catch (Throwable $e) {
    $result = ['ok' => false, 'code' => 'EXCEPTION', 'message' => $e::class.': '.$e->getMessage()];
}

echo json_encode($result + ['started_at' => $startedAt, 'finished_at' => microtime(true)]), PHP_EOL;
