<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class RequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = preg_match('/^[A-Za-z0-9_-]{8,64}$/', (string) $request->header('X-Request-Id')) ? $request->header('X-Request-Id') : (string) Str::ulid();
        $traceId = preg_match('/^[\da-f]{32}$/i', (string) $request->header('X-Trace-Id')) ? strtolower((string) $request->header('X-Trace-Id')) : bin2hex(random_bytes(16));
        $request->attributes->set('request_id', $requestId);
        $request->attributes->set('trace_id', $traceId);
        Context::add(['request_id' => $requestId, 'trace_id' => $traceId]);
        $started = hrtime(true);
        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);
        $response->headers->set('X-Trace-Id', $traceId);
        Log::info('http.request.completed', ['method' => $request->method(), 'path' => $request->path(), 'route' => $request->route()?->getName() ?? $request->route()?->uri(), 'status' => $response->getStatusCode(), 'duration_ms' => round((hrtime(true) - $started) / 1_000_000, 2), 'actor_type' => auth('admin')->check() ? 'admin' : ($request->user() ? 'user' : 'anonymous')]);

        return $response;
    }
}
