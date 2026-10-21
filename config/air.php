<?php

return [
    'live_url' => 'https://data.sensor.community/airrohr/v1/filter/country=BG&type=SDS011',
    'archive_url' => 'https://archive.sensor.community',
    'user_agent' => env('AIR_USER_AGENT', 'CleanAirFinderBulgaria/1.0 (local development)'),
    'timeout' => 30, 'request_interval_ms' => env('AIR_REQUEST_INTERVAL_MS', 1000),
    'max_distance_km' => (float) env('AIR_MAX_DISTANCE_KM', 10),
    'min_hours_fraction' => .75, 'min_samples_hour' => 2, 'min_hour_span_minutes' => 15,
    'min_day_fraction' => .75, 'stale_minutes' => 60,
    'metrics_token' => env('METRICS_TOKEN'),
];
