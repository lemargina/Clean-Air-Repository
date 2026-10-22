<?php

namespace App\Services\AirQuality;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ArchiveService
{
    public function archive(): int
    {
        return Cache::lock('air-archive', 21600)->block(1, function () {
            $cutoff = now('UTC')->subMonthsNoOverflow(36)->startOfMonth();
            $months = DB::table('measurements')->where('measured_at', '<', $cutoff)->selectRaw("to_char(measured_at AT TIME ZONE 'UTC','YYYY-MM-01') AS month")->distinct()->pluck('month');
            $count = 0;
            foreach ($months as $month) {
                $this->exportMonth($month);
                $count++;
            }

return $count;
        });
    }

    public function exportMonth(string $month): void
    {
        Cache::lock('air-raw-maintenance', 21600)->block(21600, fn () => $this->exportLockedMonth($month));
    }

    private function exportLockedMonth(string $month): void
    {
        $from = CarbonImmutable::parse($month, 'UTC')->startOfMonth();
        if ($from->addMonth()->gt(now('UTC')->subMonthsNoOverflow(36)->startOfMonth())) {
            throw new \RuntimeException('Archive retention guard');
        }
        $dir = storage_path('app/archives');
        if (! is_dir($dir)) {
            mkdir($dir, 0750, true);
        }$path = $dir.'/'.$from->format('Y-m').'.jsonl.gz';
        $temp = $path.'.partial';
        $range = fn () => DB::table('measurements')->where('measured_at', '>=', $from)->where('measured_at', '<', $from->addMonth());

        $existing = DB::table('archived_measurement_partitions')->where('month', $from->toDateString())->first();
        if ($existing && $existing->status === 'archived') {
            if ($range()->exists()) {
                throw new \RuntimeException('Late import into archived month: restore first');
            }

return;
        }
        try {
            $h = gzopen($temp, 'wb6');
            if (! $h) {
                throw new \RuntimeException('Archive open failed');
            }$count = 0;
            $digest = hash_init('sha256');
            $maxId = 0;
            try {
                foreach ($range()->orderBy('id')->cursor() as $row) {
                    $line = json_encode($row, JSON_THROW_ON_ERROR)."\n";
                    if (gzwrite($h, $line) !== strlen($line)) {
                        throw new \RuntimeException('Archive write failed');
                    }hash_update($digest, $line);
                    $count++;
                    $maxId = max($maxId, $row->id);
                }
            } finally {
                gzclose($h);
            }
            if (! $count) {
                unlink($temp);

                return;
            }
            $expected = hash_final($digest);
            $verified = $this->verify($temp);
            if ($verified['count'] !== $count || $verified['digest'] !== $expected) {
                throw new \RuntimeException('Archive integrity mismatch');
            }
            if (! rename($temp, $path)) {
                throw new \RuntimeException('Archive rename failed');
            }$sha = hash_file('sha256', $path);
            DB::transaction(function () use ($range, $from, $path, $sha, $count, $maxId) {
                DB::table('archived_measurement_partitions')->upsert([['month' => $from->toDateString(), 'path' => $path, 'sha256' => $sha, 'row_count' => $count, 'status' => 'archived', 'created_at' => now(), 'updated_at' => now()]], ['month'], ['path', 'sha256', 'row_count', 'status', 'updated_at']);
                $deleted = $range()->where('id', '<=', $maxId)->delete();
                DB::table('dataset_state')->where('id', 1)->decrement('raw_count', $deleted);
            });
        } catch (\Throwable $e) {
            Metrics::add('air_archive_errors_total');
            throw $e;
        }
    }

    private function verify(string $path): array
    {
        $h = gzopen($path, 'rb');
        if (! $h) {
            throw new \RuntimeException('Cannot read archive');
        }$hash = hash_init('sha256');
        $count = 0;
        try {
            while (($line = gzgets($h)) !== false) {
                json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                hash_update($hash, $line);
                $count++;
            }
        } finally {
            gzclose($h);
        }

return ['count' => $count, 'digest' => hash_final($hash)];
    }

    public function restore(string $month): int
    {
        return Cache::lock('air-archive', 21600)->block(1, function () use ($month) {
            $record = DB::table('archived_measurement_partitions')->where('month', CarbonImmutable::parse($month)->startOfMonth()->toDateString())->first();
            if (! $record || ! is_file($record->path) || ! hash_equals($record->sha256, hash_file('sha256', $record->path))) {
                throw new \RuntimeException('Missing or corrupt archive');
            }
            if ($this->verify($record->path)['count'] !== $record->row_count) {
                throw new \RuntimeException('Archive row count mismatch');
            }
            $h = gzopen($record->path, 'rb');
            $buffer = [];
            $inserted = 0;
            try {
                while (($line = gzgets($h)) !== false) {
                    $r = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    unset($r['id']);
                    $buffer[] = $r;
                    if (count($buffer) >= 500) {
                        $inserted += $this->restoreRows($buffer);
                        $buffer = [];
                    }
                }$inserted += $this->restoreRows($buffer);
            } finally {
                gzclose($h);
            }
            DB::table('archived_measurement_partitions')->where('id', $record->id)->update(['status' => 'restored', 'updated_at' => now()]);

            return $inserted;
        });
    }

    private function restoreRows(array $rows): int
    {
        if (! $rows) {
            return 0;
        }

return DB::transaction(function () use ($rows) {
            $inserted = DB::table('measurements')->insertOrIgnore($rows);
            DB::table('dataset_state')->where('id',1)->increment('raw_count',$inserted);

            return $inserted;
        });
    }
}
