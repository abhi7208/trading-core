<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stretch goal: structured logging with a correlation id.
 *
 * Every request gets an id (taken from the X-Correlation-ID header, or generated).
 * Laravel's Context facade adds it to every log line AND carries it into every queued
 * job and listener started by this request. So one id follows an order from the API call,
 * through ProcessOrderJob, into the audit log.
 */
class AssignCorrelationId
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->header('X-Correlation-ID');
        $id = is_string($incoming) && preg_match('/^[A-Za-z0-9\-]{8,64}$/', $incoming)
            ? $incoming
            : (string) Str::uuid();

        Context::add('correlation_id', $id);

        $response = $next($request);
        $response->headers->set('X-Correlation-ID', $id);

        return $response;
    }
}
