<?php

namespace App\Services\AirQuality;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class IngestionService
{
    private array $locations = [];

    public function __construct(private Geography $geography) {}

    public function ingest(array $records, string $source): int
    {
        if (! $records) {
            return 0;
        }

        try {
            return Cache::lock('air-raw-maintenance', 21600)->block(21600, fn () => DB::transaction(function () use ($records, $source) {
                $rows = [];
                $days = [];
                $groups = [];
                foreach ($records as $r) {
                    $key = $r['external_sensor_id'].':'.hash('sha256', json_encode([$r['external_location_id'], round($r['latitude'], 7), round($r['longitude'], 7)]));
                    if (! isset($this->locations[$key])) {
                        $assignment = $this->geography->assign($r['latitude'], $r['longitude']);
                        $s = ['external_sensor_id' => $r['external_sensor_id'], 'external_location_id' => $r['external_location_id'], 'sensor_type' => 'SDS011', 'latitude' => $r['latitude'], 'longitude' => $r['longitude'], ...$assignment, 'first_seen_at' => $r['measured_at'], 'last_seen_at' => $r['measured_at'], 'is_active' => $r['measured_at'] >= now('UTC')->subDays(7)->format('Y-m-d H:i:sP')];
                        DB::table('sensors')->insertOrIgnore($s);
                        $sensor = DB::table('sensors')->where('external_sensor_id', $r['external_sensor_id'])->first();
                        $location = ['sensor_id' => $sensor->id, 'fingerprint' => explode(':', $key)[1], 'external_location_id' => $r['external_location_id'], 'latitude' => $r['latitude'], 'longitude' => $r['longitude'], ...$assignment, 'first_seen_at' => $r['measured_at'], 'last_seen_at' => $r['measured_at']];
                        DB::table('sensor_locations')->insertOrIgnore($location);
                        $loc = DB::table('sensor_locations')->where('sensor_id', $sensor->id)->where('fingerprint', $location['fingerprint'])->first();
                        $this->locations[$key] = [$sensor->id, $loc->id, $loc->city_id];
                    }
                    [$sid,$lid,$cid] = $this->locations[$key];
                    $groups[$key] ??= ['sid' => $sid, 'lid' => $lid, 'cid' => $cid, 'first' => $r['measured_at'], 'last' => $r['measured_at'], 'record' => $r];
                    if ($r['measured_at'] < $groups[$key]['first']) {
                        $groups[$key]['first'] = $r['measured_at'];
                    }
                    if ($r['measured_at'] > $groups[$key]['last']) {
                        $groups[$key]['last'] = $r['measured_at'];
                        $groups[$key]['record'] = $r;
                    }
                    $rows[] = ['sensor_id' => $sid, 'sensor_location_id' => $lid, 'city_id' => $cid, 'measured_at' => $r['measured_at'], 'local_date' => $r['local_date'], 'pm25' => $r['pm25'], 'pm10' => $r['pm10'], 'is_suspicious' => $r['is_suspicious'], 'source' => $source, 'ingested_at' => now()];
                    $days[$r['local_date']] = ['date' => $r['local_date'], 'updated_at' => now()];
                }
                foreach ($groups as $g) {
                    $r = $g['record'];
                    $sid = $g['sid'];
                    $lid = $g['lid'];
                    $cid = $g['cid'];
                    DB::table('sensors')->where('id', $sid)->where('first_seen_at', '>', $g['first'])->update(['first_seen_at' => $g['first']]);
                    $location = DB::table('sensor_locations')->where('id', $lid)->first();
                    DB::table('sensors')->where('id', $sid)->where('last_seen_at', '<=', $g['last'])->update(['last_seen_at' => $g['last'], 'external_location_id' => $r['external_location_id'], 'latitude' => $r['latitude'], 'longitude' => $r['longitude'], 'city_id' => $cid, 'assignment_method' => $location->assignment_method, 'assignment_distance_km' => $location->assignment_distance_km, 'is_active' => $g['last'] >= now('UTC')->subDays(7)->format('Y-m-d H:i:sP')]);
                    DB::table('sensor_locations')->where('id', $lid)->where('first_seen_at', '>', $g['first'])->update(['first_seen_at' => $g['first']]);
                    DB::table('sensor_locations')->where('id', $lid)->where('last_seen_at', '<', $g['last'])->update(['last_seen_at' => $g['last']]);
                }
                $inserted = DB::table('measurements')->insertOrIgnore($rows);
                if ($inserted) {
                    $dates = array_column($rows, 'local_date');
                    $timestamps = array_column($rows, 'measured_at');
                    DB::table('dataset_state')->where('id', 1)->increment('raw_count', $inserted);
                    DB::table('dataset_state')->where('id', 1)->where(fn ($q) => $q->whereNull('history_from')->orWhere('history_from', '>', min($dates)))->update(['history_from' => min($dates)]);
                    DB::table('dataset_state')->where('id', 1)->where(fn ($q) => $q->whereNull('history_to')->orWhere('history_to', '<', max($dates)))->update(['history_to' => max($dates)]);
                    DB::table('dataset_state')->where('id', 1)->where(fn ($q) => $q->whereNull('last_measurement_at')->orWhere('last_measurement_at', '<', max($timestamps)))->update(['last_measurement_at' => max($timestamps)]);
                }

                DB::table('aggregation_days')->upsert(array_values($days), ['date'], ['updated_at']);

                return $inserted;
            }));
        } catch (\Throwable $e) {
            $this->locations = [];
            throw $e;
        }
    }
}
