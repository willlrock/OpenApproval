<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class LogRequestContext
{
    public function handle(Request $request, Closure $next): Response
    {
        Context::flush();
        if ($request->is('tracker', 'tracker/*')) {
            return $next($request);
        }

        // Generate locally: an untrusted incoming header must not join unrelated operations.
        $id = (string) Str::uuid();
        $request->attributes->set('correlation_id', $id);
        Context::add(['correlation_id' => $id, 'source' => 'http', 'http_method' => $request->method()]);
        $started = hrtime(true);
        try {
            $response = $next($request);
        } catch (\Throwable $error) {
            $status = $error instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? $error->getStatusCode() : 500;
            Context::add([
                'http_status' => $status,
                'duration_ms' => round((hrtime(true) - $started) / 1_000_000, 2),
            ]);
            Log::log($status >= 500 ? 'error' : 'info', 'http.request_failed');

            throw $error;
        }
        $response->headers->set('X-Correlation-ID', $id);

        $context = [
            'route' => $request->route()?->getName(),
            // Template only: verification hashes, search strings and file paths stay private.
            'route_template' => $request->route()?->uri(),
            'actor_id' => $request->user()?->getAuthIdentifier(),
            'http_status' => $response->getStatusCode(),
            'duration_ms' => round((hrtime(true) - $started) / 1_000_000, 2),
        ];
        Context::add($context);
        if (! $request->is('up', 'health/*') || $response->getStatusCode() >= 500) {
            $level = $response->getStatusCode() >= 500 ? 'error'
                : ($context['duration_ms'] >= config('observability.slow_request_ms') ? 'warning' : 'info');
            Log::log($level, 'http.request_completed');
        }

        return $response;
    }
}
