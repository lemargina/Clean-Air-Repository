<?php

namespace App\Services\AirQuality;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Collector
{
    public function __construct(private SourceClient $http, private MeasurementParser $parser, private IngestionService $ingestion) {}

    public function collect(): array
    {
        return Cache::lock('air-collect', 300)->block(1, function () {
            $start = microtime(true);
            $id = DB::table('collection_runs')->insertGetId(['started_at' => now(), 'status' => 'running']);
            $counts = ['processed' => 0, 'inserted' => 0, 'duplicates' => 0, 'invalid' => 0];
            $status = null;
            try {
                if (Cache::get('air-source-blocked-until', 0) > time()) {
                    throw new \RuntimeException('Source Retry-After cooldown');
                }
                $response = $this->http->get(config('air.live_url'));
                $status = $response->status();
                $json = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($json) || ! array_is_list($json)) {
                    throw new \RuntimeException('Expected JSON array');
                }
                $batch = [];
                $events = [];
                foreach ($json as $record) {
                    if (($record['sensor']['sensor_type']['name'] ?? '') !== 'SDS011') {
                        continue;
                    }
                    $counts['processed']++;
                    try {
                        $r = $this->parser->live($record);
                        $batch[] = $r;
                        if ($r['is_suspicious']) {
                            $events[] = ['reason' => 'suspicious_concentration', 'source' => 'live', 'created_at' => now()];
                        }
                    } catch (\InvalidArgumentException $e) {
                        $counts['invalid']++;
                        $events[] = ['reason' => $e->getMessage(), 'source' => 'live', 'created_at' => now()];
                    }
                    if (count($batch) >= 500) {
                        $counts['inserted'] += $this->ingestion->ingest($batch, 'sensor.community:live');
                        $batch = [];
                    }
                }
                $counts['inserted'] += $this->ingestion->ingest($batch, 'sensor.community:live');
                foreach (array_chunk($events, 500) as $chunk) {
                    DB::table('data_quality_events')->insert($chunk);
                }
                $counts['duplicates'] = $counts['processed'] - $counts['invalid'] - $counts['inserted'];
                DB::table('sensors')->where('last_seen_at', '<', now()->subDays(7))->update(['is_active' => false]);
                DB::table('collection_runs')->where('id', $id)->update([...$counts, 'source_status' => $status, 'status' => 'success', 'finished_at' => now(), 'duration_seconds' => microtime(true) - $start]);

                return $counts;
            } catch (\Throwable $e) {
                if ($e instanceof RequestException) {
                    $status = $e->response->status();
                }
                DB::table('collection_runs')->where('id', $id)->update([...$counts, 'source_status' => $status, 'status' => 'failed', 'finished_at' => now(), 'duration_seconds' => microtime(true) - $start, 'error' => substr($e->getMessage(), 0, 2000)]);
                Log::error('air.collection.failed', ['run_id' => $id, 'exception' => $e::class]);
                throw $e;
            }
        });
    }
}
