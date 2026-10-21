<?php

namespace App\Services\AirQuality;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Aggregator
{
    public function day(string $date): void
    {
        Cache::lock('air-aggregate-'.$date, 1800)->block(10, function () use ($date) {
            DB::transaction(function () use ($date) {
                $utcStart = CarbonImmutable::parse($date, 'Europe/Sofia')->startOfDay()->utc();
                foreach (DB::table('archived_measurement_partitions')->where('status', 'archived')->pluck('month') as $month) {
                    $archivedStart = CarbonImmutable::parse($month, 'UTC');
                    if ($archivedStart < $utcStart->addDay() && $archivedStart->addMonth() > $utcStart) {
                        throw new \RuntimeException('Restore archived raw month before recomputing '.$date);
                    }
                }
                $dirty = DB::table('aggregation_days')->where('date', $date)->first();
                $start = CarbonImmutable::parse($date, 'Europe/Sofia')->startOfDay();
                $hours = (int) $start->diffInHours($start->addDay());
                DB::table('sensor_daily_statistics')->where('date', $date)->delete();
                DB::table('city_daily_statistics')->where('date', $date)->delete();
                DB::statement("INSERT INTO sensor_daily_statistics (sensor_id,city_id,date,pm25,pm10,samples,valid_hours,pm10_hours,expected_hours,qualifies)
 WITH hours AS (
 SELECT sensor_id,city_id,date_trunc('hour',measured_at) AS hour, AVG(pm25) AS pm25,AVG(pm10) AS pm10,COUNT(*) AS samples,
 COUNT(pm10) AS pm10_samples,EXTRACT(EPOCH FROM (MAX(measured_at) FILTER (WHERE pm10 IS NOT NULL)-MIN(measured_at) FILTER (WHERE pm10 IS NOT NULL))) AS pm10_span,EXTRACT(EPOCH FROM (MAX(measured_at)-MIN(measured_at))) AS span
 FROM measurements WHERE local_date=? AND city_id IS NOT NULL AND is_suspicious=false GROUP BY sensor_id,city_id,date_trunc('hour',measured_at)
 ), valid AS (SELECT * FROM hours WHERE samples>=? AND span>=?)
 SELECT sensor_id,city_id,CAST(? AS date),AVG(pm25),AVG(CASE WHEN pm10_samples>=? AND pm10_span>=900 THEN pm10 END),SUM(samples),COUNT(*),COUNT(CASE WHEN pm10_samples>=? AND pm10_span>=900 THEN 1 END),?,COUNT(*)>=?
 FROM valid GROUP BY sensor_id,city_id", [$date, config('air.min_samples_hour'), config('air.min_hour_span_minutes') * 60, $date, config('air.min_samples_hour'), config('air.min_samples_hour'), $hours, (int) ceil($hours * config('air.min_hours_fraction'))]);
                DB::statement('INSERT INTO city_daily_statistics (city_id,date,pm25,pm10,sensors,samples,sensor_hours)
 SELECT city_id,date,AVG(pm25),AVG(CASE WHEN pm10_hours>=CEIL(expected_hours*CAST(? AS numeric)) THEN pm10 END),COUNT(*),SUM(samples),SUM(valid_hours)
 FROM sensor_daily_statistics WHERE date=? AND qualifies=true GROUP BY city_id,date', [config('air.min_hours_fraction'), $date]);
                if ($dirty) {
                    DB::table('aggregation_days')->where('date', $date)->where('updated_at', $dirty->updated_at)->delete();
                }
            });
        });
    }

    public function pending(): int
    {
        $n = 0;
        foreach (DB::table('aggregation_days')->orderBy('date')->pluck('date') as $date) {
            $this->day($date);
            $n++;
        }

return $n;
    }
}
