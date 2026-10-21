<?php

namespace App\Services\AirQuality;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class SourceClient
{
    public function get(string $url, ?string $sink = null): Response
    {
        return Cache::lock('air-source-http', 3700)->block(1, function () use ($url, $sink) {
            if (Cache::get('air-source-blocked-until', 0) > time()) {
                throw new \RuntimeException('Source Retry-After cooldown');
            }
            $last = Cache::get('air-source-last', 0);
            $wait = (config('air.request_interval_ms') / 1000) - (microtime(true) - $last);
            if ($wait > 0) {
                usleep((int) ($wait * 1e6));
            }
            for ($attempt = 0; $attempt < 3; $attempt++) {
                try {
                    $request = Http::withHeaders(['User-Agent' => config('air.user_agent')])->connectTimeout(10)->timeout($sink ? 3600 : config('air.timeout'));
                    $response = $sink ? $request->withOptions(['sink' => $sink])->get($url) : $request->get($url);
                    Cache::put('air-source-last', microtime(true), 86400);
                    if ($response->status() !== 429 && $response->status() < 500) {
                        return $response->throw();
                    }
                    $retry = $response->header('Retry-After');
                    $delay = is_numeric($retry) ? (int) $retry : ($retry ? max(0, strtotime($retry) - time()) : 2 ** $attempt);
                    if ($delay > 60) {
                        Cache::put('air-source-blocked-until', time() + $delay, $delay);
                        $response->throw();
                    }
                } catch (ConnectionException $e) {
                    if ($attempt === 2) {
                        throw $e;
                    } $delay = 2 ** $attempt;
                }
                if ($attempt === 2) {
                    return $response->throw();
                }
                sleep(max(1, $delay));
            }
            throw new \RuntimeException('Source retry limit');
        });
    }
}
