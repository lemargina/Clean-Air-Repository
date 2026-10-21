<?php

namespace App\Services\AirQuality;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BackfillService
{
    public function __construct(private SourceClient $http, private MeasurementParser $parser, private IngestionService $ingestion, private Aggregator $aggregator) {}

    public function month(string $month, string $from, string $to): array
    {
        return Cache::lock('air-import-'.$month, 21600)->block(1, function () use ($month, $from, $to) {
            $url = config('air.archive_url').'/csv_per_month/'.$month.'/'.$month.'_sds011.zip';
            $key = $url.'#'.$from.':'.$to;
            DB::table('import_batches')->insertOrIgnore(['source_file' => $key, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            $batch = DB::table('import_batches')->where('source_file', $key)->first();
            if ($batch->status === 'complete') {
                return ['status' => 'already_complete'];
            }
            $dir = storage_path('app/imports');
            if (! is_dir($dir)) {
                mkdir($dir, 0750, true);
            }$path = $dir.'/'.$month.'.zip';
            try {
                DB::table('import_batches')->where('id', $batch->id)->update(['status' => 'downloading', 'error' => null, 'updated_at' => now()]);
                if (! is_file($path)) {
                    if (disk_free_space($dir) < 8 * 1024 ** 3) {
                        throw new \RuntimeException('Insufficient free disk: reserve at least 8 GiB before monthly download');
                    }
                    $tmp = $path.'.partial';
                    $this->http->get($url, $tmp);
                    $zip = new \ZipArchive;
                    if ($zip->open($tmp, \ZipArchive::CHECKCONS) !== true) {
                        throw new \RuntimeException('Invalid ZIP download');
                    }$zip->close();
                    rename($tmp, $path);
                }
                $zip = new \ZipArchive;
                if ($zip->open($path, \ZipArchive::CHECKCONS) !== true) {
                    unlink($path);
                    throw new \RuntimeException('Corrupt ZIP; removed for retry');
                }
                $hash = hash_file('sha256', $path);
                if ($batch->sha256 && $batch->sha256 !== $hash) {
                    throw new \RuntimeException('Source archive changed; reset checkpoint explicitly after investigation');
                }
                DB::table('import_batches')->where('id', $batch->id)->update(['status' => 'processing', 'sha256' => $hash, 'updated_at' => now()]);
                $processed = 0;
                $inserted = 0;
                for ($i = (int) $batch->checkpoint; $i < $zip->numFiles; $i++) {
                    $name = $zip->getNameIndex($i);
                    $stream = $zip->getStream($name);
                    if ($stream && str_ends_with($name, '.csv')) {
                        try {
                            $saved = DB::table('import_batches')->where('id', $batch->id)->first();
                            $lastCounts = ['processed' => 0, 'inserted' => 0, 'invalid' => 0];
                            $checkpoint = function ($row, $counts) use ($batch, &$lastCounts) {
                                DB::transaction(function () use ($row, $counts, $batch, &$lastCounts) {
                                    foreach (['processed', 'inserted', 'invalid'] as $field) {
                                        DB::table('import_batches')->where('id', $batch->id)->increment($field, $counts[$field] - $lastCounts[$field]);
                                    }
                                    DB::table('import_batches')->where('id', $batch->id)->update(['row_checkpoint' => $row, 'updated_at' => now()]);
                                });
                                $lastCounts = $counts;
                            };
                            $counts = $this->csv($stream, 'sensor.community:'.$month.'/'.$name, $from, $to, (int) $saved->row_checkpoint, $checkpoint);
                            $processed += $counts['processed'];
                            $inserted += $counts['inserted'];
                            if (ftell($stream) !== $zip->statIndex($i)['size']) {
                                throw new \RuntimeException('Truncated CSV member');
                            }
                        } finally {
                            fclose($stream);
                        }
                    }
                    // Checkpoint only after an entire member commits. Interrupted members replay safely.
                    DB::table('import_batches')->where('id', $batch->id)->update(['checkpoint' => $i + 1, 'row_checkpoint' => 0, 'updated_at' => now()]);
                }
                $zip->close();
                $this->aggregator->pending();
                DB::table('import_batches')->where('id', $batch->id)->update(['status' => 'complete', 'updated_at' => now()]);
                unlink($path);

                return compact('processed', 'inserted');
            } catch (\Throwable $e) {
                DB::table('import_batches')->where('id', $batch->id)->update(['status' => 'failed', 'error' => substr($e->getMessage(), 0, 2000), 'updated_at' => now()]);
                Log::error('air.import.failed', ['batch_id' => $batch->id, 'exception' => $e::class]);
                throw $e;
            }
        });
    }

    public function csv($stream, string $source, string $from, string $to, int $skipRows = 0, ?callable $checkpoint = null): array
    {
        $header = fgetcsv($stream, 0, ';', '"', '');
        if (! $header || array_diff(['sensor_id', 'sensor_type', 'location', 'lat', 'lon', 'timestamp', 'P1', 'P2'], $header)) {
            throw new \RuntimeException('Unsupported archive CSV schema');
        }
        $processed = 0;
        $inserted = 0;
        $invalid = 0;
        $buffer = [];
        $rowNumber = 0;
        $flush = function (int $row) use (&$buffer, &$inserted, &$processed, &$invalid, $source, $checkpoint): void {
            DB::transaction(function () use (&$buffer, &$inserted, &$processed, &$invalid, $source, $checkpoint, $row) {
                $inserted += $this->ingestion->ingest($buffer, $source);
                $buffer = [];
                if ($checkpoint) {
                    $checkpoint($row, compact('processed', 'inserted', 'invalid'));
                }
            });
        };
        while (($line = fgetcsv($stream, 0, ';', '"', '')) !== false) {
            $rowNumber++;
            if ($rowNumber <= $skipRows) {
                continue;
            }
            if ($rowNumber % 100000 === 0) {
                $flush($rowNumber - 1);
            }

            if (count($line) !== count($header)) {
                $invalid++;

                continue;
            }$r = array_combine($header, $line);
            if ($r['sensor_type'] !== 'SDS011') {
                continue;
            }
            // Cheap bounding box prefilter before full polygon and timestamp validation.
            if (! is_numeric($r['lat']) || ! is_numeric($r['lon']) || $r['lat'] < 41 || $r['lat'] > 45 || $r['lon'] < 22 || $r['lon'] > 29) {
                continue;
            }
            try {
                $record = $this->parser->parse($r);
                if ($record['local_date'] < $from || $record['local_date'] > $to) {
                    continue;
                }$buffer[] = $record;
                $processed++;
            } catch (\InvalidArgumentException) {
                $invalid++;

                continue;
            }
            if (count($buffer) >= 500) {
                $flush($rowNumber);
            }
        }
        $flush($rowNumber);

        return compact('processed', 'inserted', 'invalid');
    }

    public function dailySensor(string $date, int $sensor, string $from, string $to): array
    {
        $year = substr($date, 0, 4);
        $prefix = $year < now('UTC')->format('Y') ? $year.'/' : '';
        $url = config('air.archive_url').'/'.$prefix.$date.'/'.$date.'_sds011_sensor_'.$sensor.'.csv';
        $dir = storage_path('app');
        if (! is_dir($dir)) {
            mkdir($dir, 0750, true);
        }$path = tempnam($dir, 'csv-');
        try {
            $this->http->get($url, $path);
            $h = fopen($path, 'r');
            try {
                return $this->csv($h, $url, $from, $to);
            } finally {
                fclose($h);
            }
        } finally {
            @unlink($path);
        }
    }
}
