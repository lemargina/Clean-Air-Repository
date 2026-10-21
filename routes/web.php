<?php

use App\Http\Controllers\AirQualityController;
use App\Services\AirQuality\CleanestCityService;
use App\Services\AirQuality\Metrics;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', [AirQualityController::class, 'index']);
Route::prefix('api/v1')->middleware('throttle:120,1')->group(function () {
    Route::get('cities', [AirQualityController::class, 'cities']);
    Route::get('data-coverage', [AirQualityController::class, 'coverage']);
    Route::get('air-quality/cleanest', [AirQualityController::class, 'ranking']);
    Route::get('air-quality/ranking', [AirQualityController::class, 'ranking']);
    Route::get('air-quality/cities/{cityId}', [AirQualityController::class, 'city'])->whereNumber('cityId');
});
Route::get('/health/live', fn () => response()->json(['status' => 'alive']));
Route::get('/health/ready', function (CleanestCityService $service) {
    try {
        DB::select('SELECT 1');
        Cache::put('health-probe', 1, 10);
        if (Cache::get('health-probe') !== 1) {
            throw new RuntimeException('cache');
        }$coverage = $service->coverage();

        return response()->json(['status' => 'ready', 'data_state' => $coverage['stale'] ? 'degraded' : 'fresh', 'coverage' => $coverage]);
    } catch (Throwable) {
        return response()->json(['status' => 'not_ready'], 503);
    }
});
Route::get('/metrics', function (Request $request, Metrics $metrics) {
    abort_unless(config('air.metrics_token') && hash_equals(config('air.metrics_token'), (string) $request->bearerToken()), 403);
    try {
        return response($metrics->render(), 200, ['Content-Type' => 'text/plain; version=0.0.4']);
    } catch (Throwable) {
        return response("air_database_up 0\n", 503, ['Content-Type' => 'text/plain']);
    }
});
