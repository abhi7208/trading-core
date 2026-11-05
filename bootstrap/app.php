<?php

use App\Exceptions\DomainError;
use App\Http\Middleware\AssignCorrelationId;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * ONE consistent error format for the whole API:
 *
 *   { "error": { "code": "SOME_CODE", "message": "Human readable", "details": { ... } } }
 */
$apiError = function (string $code, string $message, int $status, array $details = [], array $headers = []): JsonResponse {
    return response()->json([
        'error' => ['code' => $code, 'message' => $message, 'details' => (object) $details],
    ], $status, $headers);
};

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withEvents(discover: false) // listeners are registered explicitly in AppServiceProvider
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [AssignCorrelationId::class]);
    })
    ->withExceptions(function (Exceptions $exceptions) use ($apiError): void {
        $isApi = fn (Request $request) => $request->is('api/*');

        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $isApi($request) || $request->expectsJson());

        // Business errors (insufficient funds, idempotency conflict, ...)
        $exceptions->render(function (DomainError $e, Request $request) use ($apiError, $isApi) {
            return $isApi($request) ? $apiError($e->errorCode(), $e->getMessage(), $e->httpStatus(), $e->details()) : null;
        });

        $exceptions->render(function (ValidationException $e, Request $request) use ($apiError, $isApi) {
            return $isApi($request) ? $apiError('VALIDATION_FAILED', 'The given data was invalid.', 422, ['fields' => $e->errors()]) : null;
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($apiError, $isApi) {
            return $isApi($request) ? $apiError('UNAUTHENTICATED', 'A valid API token is required.', 401) : null;
        });

        // 403 (policy), 404 (missing order), 405, 429 (rate limit), ...
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($apiError, $isApi) {
            if (! $isApi($request)) {
                return null;
            }
            $status = $e->getStatusCode();
            $code = match ($status) {
                403 => 'FORBIDDEN',
                404 => 'NOT_FOUND',
                405 => 'METHOD_NOT_ALLOWED',
                429 => 'TOO_MANY_REQUESTS',
                default => 'HTTP_'.$status,
            };
            $message = $status === 404 ? 'Resource not found.' : ($e->getMessage() ?: 'Request failed.');

            return $apiError($code, $message, $status, [], $e->getHeaders());
        });

        // Anything unexpected: never leak internals unless APP_DEBUG is on.
        $exceptions->render(function (Throwable $e, Request $request) use ($apiError, $isApi) {
            if (! $isApi($request) || $e instanceof HttpResponseException) {
                return null;
            }
            $details = config('app.debug') ? ['exception' => $e::class, 'message' => $e->getMessage()] : [];

            return $apiError('SERVER_ERROR', 'Something went wrong.', 500, $details);
        });
    })->create();
