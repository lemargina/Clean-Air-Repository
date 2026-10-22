<?php

namespace App\Services\AirQuality;

use Illuminate\Support\Facades\DB;

class Metrics
{
    public static function add(string $name, float $value = 1): void
    {
        try {
            DB::table('metric_counters')->insertOrIgnore(['name' => $name, 'value' => 0]);
            DB::table('metric_counters')->where('name', $name)->increment('value', $value);
        } catch (\Throwable) {
        }
    }

    public function render(): string
    {
        $values = DB::table('metric_counters')->pluck('value', 'name')->all();
        $last = DB::table('collection_runs')->where('status', 'success')->max('finished_at');
        $sum = DB::table('collection_runs')->selectRaw('COALESCE(SUM(processed),0) AS processed,COALESCE(SUM(invalid),0) AS invalid')->first();
        $values += ['air_database_up' => 1, 'air_sensors' => DB::table('sensors')->count(), 'air_active_sensors' => DB::table('sensors')->where('last_seen_at', '>=', now()->subDays(7))->count(), 'air_measurements' => DB::table('measurements')->count(), 'air_valid_measurements' => DB::table('measurements')->where('is_suspicious', false)->count(), 'air_last_collection_timestamp' => $last ? strtotime($last) : 0, 'air_collection_success_total' => DB::table('collection_runs')->where('status', 'success')->count(), 'air_collection_failure_total' => DB::table('collection_runs')->where('status', 'failed')->count(), 'air_invalid_measurement_ratio' => $sum->processed ? $sum->invalid / $sum->processed : 0, 'air_import_backlog' => DB::table('import_batches')->where('status', '!=', 'complete')->count(), 'air_import_complete' => DB::table('import_batches')->where('status', 'complete')->count(), 'air_queue_pending' => DB::table('jobs')->count(), 'air_source_measurement_timestamp' => strtotime(DB::table('dataset_state')->where('id', 1)->value('last_measurement_at') ?? '1970-01-01 UTC'), 'air_backfill_rows_processed' => DB::table('import_batches')->sum('processed'), 'air_backfill_rows_invalid' => DB::table('import_batches')->sum('invalid'), 'air_queue_failures' => DB::table('failed_jobs')->count(), 'air_archive_failures' => DB::table('archived_measurement_partitions')->where('status', 'failed')->count(), 'air_aggregation_backlog' => DB::table('aggregation_days')->count()];
        $out = '';
        foreach ($values as $k => $v) {
            $out .= $k.' '.(float) $v."\n";
        }

return $out;
    }
}
