<?php

namespace App\Http\Middleware;

use App\Services\AirQuality\Metrics;
use Closure;

class ObserveRequests
{
    public function handle($request, Closure $next)
    {
        $start = microtime(true);
        try {
            return $next($request);
        } finally {
            if (! $request->is('metrics', 'health/live')) {
                Metrics::add('air_http_requests_total');
                Metrics::add('air_http_request_seconds_sum', microtime(true) - $start);
            }
        }
    }
}
