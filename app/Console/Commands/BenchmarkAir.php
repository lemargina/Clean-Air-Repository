<?php

namespace App\Console\Commands;

use App\Services\AirQuality\Aggregator;
use App\Services\AirQuality\BackfillService;
use App\Services\AirQuality\CleanestCityService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class BenchmarkAir extends Command
{
    protected $signature = 'air:benchmark {--rows=100000}';

    protected $description = 'Destructive synthetic benchmark, restricted to an isolated air_benchmark database';

    public function handle(): int
    {
        if (! str_starts_with((string) DB::connection()->getDatabaseName(), 'air_benchmark')) {
            $this->error('Requires dedicated air_benchmark database');

            return 1;
        }
        $n = (int) $this->option('rows');
        if (! in_array($n, [100000, 1000000, 10000000])) {
            return 1;
        }
        Artisan::call('migrate:fresh', ['--force' => true]);
        Artisan::call('air:geo-import');
        $sensors = (int) ceil($n / 35040);
        $start = microtime(true);
        DB::statement("INSERT INTO sensors (external_sensor_id,external_location_id,sensor_type,latitude,longitude,city_id,assignment_method,first_seen_at,last_seen_at,is_active) SELECT s,s,'SDS011',42.6975,23.3242,1+((s-1)%3),'synthetic_benchmark_only','2025-01-01','2026-01-01',true FROM generate_series(1,?) s", [$sensors]);
        DB::statement("INSERT INTO sensor_locations (sensor_id,fingerprint,external_location_id,latitude,longitude,city_id,assignment_method,first_seen_at,last_seen_at) SELECT id,'synthetic-'||id,id,latitude,longitude,city_id,'synthetic_benchmark_only',first_seen_at,last_seen_at FROM sensors");
        DB::statement("INSERT INTO measurements (sensor_id,sensor_location_id,city_id,measured_at,local_date,pm25,pm10,is_suspicious,source,ingested_at)
 SELECT sid,sid,1+((sid-1)%3),at,(at AT TIME ZONE 'Europe/Sofia')::date,5+(i%30),10+(i%30)+(i%10),false,'SYNTHETIC_BENCHMARK_ONLY',now() FROM (
 SELECT i,1+((i-1)%?) AS sid,TIMESTAMPTZ '2025-01-01 00:00:00+00'+((floor((i-1)/?)::bigint%8760)*INTERVAL '1 hour')+(floor((i-1)/(?*8760))*INTERVAL '15 minutes') AS at FROM generate_series(1,?) i) data", [$sensors, $sensors, $sensors, $n]);
        $seed = microtime(true) - $start;
        DB::statement("UPDATE dataset_state SET raw_count=?,history_from='2025-01-01',history_to='2026-01-01',last_measurement_at='2026-01-01' WHERE id=1", [$n]);
        DB::statement('ANALYZE measurements');
        $start = microtime(true);
        foreach (DB::table('measurements')->distinct()->orderBy('local_date')->pluck('local_date') as $date) {
            app(Aggregator::class)->day($date);
        }$aggregation = microtime(true) - $start;
        DB::statement('ANALYZE city_daily_statistics');
        DB::statement('ANALYZE sensor_daily_statistics');
        $results = [];
        foreach ([1, 7, 30, 365] as $days) {
            $from = '2025-01-01';
            $to = CarbonImmutable::parse($from)->addDays($days - 1)->toDateString();
            $timings = [];
            for ($i = 0; $i < 5; $i++) {
                $t = microtime(true);
                app(CleanestCityService::class)->calculate($from, $to);
                $timings[] = (microtime(true) - $t) * 1000;
            }
            $plan = DB::select('EXPLAIN (ANALYZE,BUFFERS,FORMAT JSON) SELECT city_id,AVG(pm25),AVG(pm10),COUNT(*) FROM city_daily_statistics WHERE date BETWEEN ? AND ? GROUP BY city_id', [$from, $to]);
            $results[$days] = ['latency_ms' => $timings, 'plan' => json_decode($plan[0]->{'QUERY PLAN'}, true)];
        }
        $plan = DB::select("EXPLAIN (ANALYZE,BUFFERS,FORMAT JSON) SELECT sensor_id,AVG(pm25) FROM measurements WHERE local_date='2025-06-01' AND is_suspicious=false GROUP BY sensor_id");
        // Parser + real ingestion service throughput on synthetic CSV, never production.
        $stream = fopen('php://temp/maxmemory:1048576', 'w+');
        fwrite($stream, "sensor_id;sensor_type;location;lat;lon;timestamp;P1;P2\n");
        for ($i = 0; $i < 5000; $i++) {
            $at = CarbonImmutable::parse('2026-02-01', 'UTC')->addSeconds($i * 60)->format('Y-m-d\TH:i:s');
            fwrite($stream, "999999;SDS011;999999;42.6975;23.3242;$at;12;5\n");
        }rewind($stream);
        $t = microtime(true);
        $csv = app(BackfillService::class)->csv($stream, 'SYNTHETIC_BENCHMARK_ONLY', '2026-02-01', '2026-02-10');
        $csvSeconds = microtime(true) - $t;
        fclose($stream);
        $report = ['rows' => $n, 'sensors' => $sensors, 'seed_seconds' => $seed, 'aggregation_seconds' => $aggregation, 'csv_ingestion_seconds' => $csvSeconds, 'csv_ingestion_records' => $csv, 'peak_php_memory_bytes' => memory_get_peak_usage(true), 'database_bytes' => DB::selectOne('SELECT pg_database_size(current_database()) AS bytes')->bytes, 'postgres' => DB::selectOne('SELECT version() AS version')->version, 'queries' => $results, 'raw_day_plan' => json_decode($plan[0]->{'QUERY PLAN'}, true)];
        $path = base_path('docs/benchmark-'.$n.'.json');
        file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info($path);

        return 0;
    }
}
