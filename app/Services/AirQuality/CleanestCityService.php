<?php

namespace App\Services\AirQuality;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class CleanestCityService
{
    public const METHODOLOGY = 'PM2.5: равни тегла на валидните UTC часове (≥2 проби, обхват ≥15 минути), после на сензорите за местния ден и на дните за периода. Валиден сензорен ден: ≥75% от 23/24/25 часа в Europe/Sofia. Допустим град: ≥75% от календарните дни. PM10 е допълващ показател със собствен праг за пълнота. Няма попълване на липсващи стойности. Равенство: еднаква PM2.5 след закръгляне до 0.001 µg/m³. Измерените прахови концентрации не са цялостна оценка на въздуха или здравния риск.';

    public function coverage(): array
    {
        $range = DB::table('dataset_state')->where('id', 1)->first();
        $daily = DB::table('city_daily_statistics')->selectRaw('MIN(date) AS first, MAX(date) AS last, COUNT(DISTINCT date) AS days')->first();
        $last = DB::table('collection_runs')->where('status', 'success')->max('finished_at');

        return ['from' => $range->history_from, 'to' => $range->history_to, 'measurements' => (int) $range->raw_count, 'last_measurement_at' => $range->last_measurement_at, 'qualified_from' => $daily->first, 'qualified_to' => $daily->last, 'qualified_days' => (int) $daily->days, 'last_successful_sync' => $last, 'stale' => ! $last || ! $range->last_measurement_at || CarbonImmutable::parse($last)->lt(now()->subMinutes(config('air.stale_minutes'))) || CarbonImmutable::parse($range->last_measurement_at)->lt(now()->subMinutes(config('air.stale_minutes'))), 'pending_aggregation_days' => DB::table('aggregation_days')->count(), 'historical_import' => $this->importStatus(), 'imports' => DB::table('import_batches')->selectRaw('status,COUNT(*) AS count')->groupBy('status')->get(), 'source' => 'Sensor.Community — Database Contents License 1.0; attribution: https://sensor.community/'];
    }

    private function importStatus(): array
    {
        $months = [];
        foreach (DB::table('import_batches')->where('status', 'complete')->pluck('source_file') as $uri) {
            if (! preg_match('~/csv_per_month/(\d{4}-\d{2})/[^#]+#(\d{4}-\d{2}-\d{2}):(\d{4}-\d{2}-\d{2})$~', $uri, $m)) {
                continue;
            }
            $start = CarbonImmutable::parse($m[1].'-01');
            if ($m[2] <= $start->toDateString() && $m[3] >= $start->endOfMonth()->toDateString()) {
                $months[$m[1]] = true;
            }
        }
        ksort($months);
        $streak = 0;
        $longest = 0;
        $previous = null;
        $chainStart = null;
        $bestStart = null;
        $bestEnd = null;
        foreach (array_keys($months) as $month) {
            $date = CarbonImmutable::parse($month.'-01');
            if ($previous && $date->equalTo($previous->addMonth())) {
                $streak++;
            } else {
                $streak = 1;
                $chainStart = $date;
            }
            if ($streak > $longest) {
                $longest = $streak;
                $bestStart = $chainStart;
                $bestEnd = $date->endOfMonth();
            }
            $previous = $date;
        }
        $qualified = $bestStart ? DB::table('city_daily_statistics')->whereBetween('date', [$bestStart->toDateString(), $bestEnd->toDateString()])->distinct()->count('date') : 0;
        $expected = $bestStart ? (int) $bestStart->diffInDays($bestEnd->startOfDay()) + 1 : 0;

        return ['completed_full_months' => array_keys($months), 'consecutive_full_months' => $longest, 'has_twelve_completed_months' => $longest >= 12, 'period_from' => $bestStart?->toDateString(), 'period_to' => $bestEnd?->toDateString(), 'qualified_days_in_period' => $qualified, 'expected_days_in_period' => $expected, 'verified_twelve_month_history' => $longest >= 12 && $qualified >= ceil($expected * .75)];
    }

    public function calculate(string $from, string $to): array
    {
        $start = microtime(true);
        $days = (int) CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1;
        $stats = DB::table('city_daily_statistics')->whereBetween('date', [$from, $to])->selectRaw('city_id,AVG(pm25) AS pm25,AVG(pm10) AS pm10,COUNT(*) AS days,COUNT(pm10) AS pm10_days')->groupBy('city_id')->get()->keyBy('city_id');
        $sensorCounts = DB::table('sensor_daily_statistics')->whereBetween('date', [$from, $to])->where('qualifies', true)->selectRaw('city_id,COUNT(DISTINCT sensor_id) AS sensors')->groupBy('city_id')->pluck('sensors', 'city_id');
        $eligible = [];
        $excluded = [];
        foreach (DB::table('cities')->orderBy('id')->get() as $city) {
            $s = $stats->get($city->id);
            $covered = (int) ($s->days ?? 0);
            $row = ['city_id' => $city->id, 'city' => $city->name_bg, 'pm25' => $s ? round((float) $s->pm25, 3) : null, 'pm10' => $s && $s->pm10_days >= ceil($days * config('air.min_day_fraction')) ? round((float) $s->pm10, 3) : null, 'pm10_days' => (int) ($s->pm10_days ?? 0), 'sensors' => (int) ($sensorCounts[$city->id] ?? 0), 'days_covered' => $covered, 'period_days' => $days, 'coverage_percent' => round(100 * $covered / $days, 2)];
            if ($covered >= ceil($days * config('air.min_day_fraction')) && $row['sensors'] >= 1) {
                $eligible[] = $row;
            } else {
                $excluded[] = [...$row, 'reason' => $covered ? 'insufficient_day_coverage' : 'no_qualifying_observations'];
            }
        }
        usort($eligible, fn ($a, $b) => ($a['pm25'] <=> $b['pm25']) ?: ($a['city_id'] <=> $b['city_id']));
        $rank = 0;
        $previous = null;
        foreach ($eligible as $i => &$row) {
            if ($previous !== $row['pm25']) {
                $rank = $i + 1;
            } $row['rank'] = $rank;
            $previous = $row['pm25'];
        } unset($row);
        $winners = array_values(array_filter($eligible, fn ($r) => $r['rank'] === 1));
        $coverage = $this->coverage();
        Metrics::add('air_historical_queries_total');
        Metrics::add('air_historical_query_seconds_sum', microtime(true) - $start);

        return ['from' => $from, 'to' => $to, 'status' => $winners ? 'ok' : 'insufficient_data', 'winners' => $winners, 'ranking' => $eligible, 'excluded' => $excluded, 'coverage' => $coverage, 'methodology' => self::METHODOLOGY];
    }
}
