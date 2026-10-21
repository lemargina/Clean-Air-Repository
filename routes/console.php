<?php

use App\Jobs\BackfillMonth;
use App\Services\AirQuality\Aggregator;
use App\Services\AirQuality\ArchiveService;
use App\Services\AirQuality\BackfillService;
use App\Services\AirQuality\CleanestCityService;
use App\Services\AirQuality\Collector;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('air:geo-import', function () {
    $cities = json_decode(file_get_contents(database_path('geography/cities.json')), true, 512, JSON_THROW_ON_ERROR);
    DB::table('cities')->upsert($cities, ['external_identifier'], ['name_bg', 'name_en', 'municipality', 'region', 'population', 'geographic_source', 'latitude', 'longitude']);
    $this->info(count($cities).' cities imported');
})->purpose('Import attributed local city dataset');
Artisan::command('air:collect', function (Collector $collector, Aggregator $aggregator) {
    try {
        $this->info(json_encode($collector->collect()));
        $aggregator->pending();
    } catch (Throwable $e) {
        $this->error($e->getMessage());

        return 1;
    }

    return 0;
})->purpose('Collect real Bulgarian SDS011 observations');
Artisan::command('air:aggregate {--from=} {--to=}', function (Aggregator $aggregator) {
    if ($this->option('from')) {
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $this->option('from'));
        $to = CarbonImmutable::createFromFormat('!Y-m-d', $this->option('to') ?: $this->option('from'));
        if ($from > $to) {
            $this->error('Invalid range');

            return 1;
        }for ($d = $from; $d <= $to; $d = $d->addDay()) {
            $aggregator->day($d->toDateString());
        }
    } else {
        $this->info($aggregator->pending().' days recomputed');
    }

    return 0;
});
Artisan::command('air:backfill {--months=12} {--from=} {--to=} {--queue} {--sensor=}', function (BackfillService $service, Aggregator $aggregator) {
    try {
        $to = $this->option('to') ? CarbonImmutable::createFromFormat('!Y-m-d', $this->option('to'), 'Europe/Sofia') : CarbonImmutable::now('Europe/Sofia')->startOfMonth()->subDay()->startOfDay();
        $from = $this->option('from') ? CarbonImmutable::createFromFormat('!Y-m-d', $this->option('from'), 'Europe/Sofia') : $to->subMonthsNoOverflow((int) $this->option('months'))->addDay();
        if ($from > $to || $to >= CarbonImmutable::now('Europe/Sofia')->startOfDay() || (int) $this->option('months') < 1) {
            throw new RuntimeException('Invalid historical date range');
        }
        if ($this->option('sensor')) {
            for ($d = $from->subDay(); $d <= $to; $d = $d->addDay()) {
                try {
                    $this->info(json_encode($service->dailySensor($d->toDateString(), (int) $this->option('sensor'), $from->toDateString(), $to->toDateString())));
                } catch (RequestException $e) {
                    if ($e->response->status() !== 404) {
                        throw $e;
                    }
                }
            }$aggregator->pending();
            $this->warn('Single sensor import is a diagnostic subset, not full coverage.');

            return 0;
        }
        // Include preceding UTC month to cover the first Sofia day's early hours.
        for ($m = $from->subDay()->startOfMonth(); $m <= $to->startOfMonth(); $m = $m->addMonth()) {
            if ($this->option('queue')) {
                DB::table('import_batches')->insertOrIgnore(['source_file' => config('air.archive_url').'/csv_per_month/'.$m->format('Y-m').'/'.$m->format('Y-m').'_sds011.zip#'.$from->toDateString().':'.$to->toDateString(), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
                BackfillMonth::dispatch($m->format('Y-m'), $from->toDateString(), $to->toDateString());
                $this->info('Queued '.$m->format('Y-m'));
            } else {
                $this->info('Importing '.$m->format('Y-m'));
                $this->info(json_encode($service->month($m->format('Y-m'), $from->toDateString(), $to->toDateString())));
            }
        }
    } catch (Throwable $e) {
        $this->error($e->getMessage());

        return 1;
    }

    return 0;
});
Artisan::command('air:archive {--restore=}', function (ArchiveService $service, Aggregator $aggregator) {
    try {
        if (! $this->option('restore')) {
            $aggregator->pending();
        }$this->info((string) ($this->option('restore') ? $service->restore($this->option('restore')) : $service->archive()));
    } catch (Throwable $e) {
        $this->error($e->getMessage());

        return 1;
    }

    return 0;
});
Schedule::command('air:collect')->everyFifteenMinutes()->withoutOverlapping(10)->onOneServer();
Schedule::command('air:aggregate')->hourly()->withoutOverlapping(60);
Schedule::command('air:archive')->monthlyOn(2, '03:00')->withoutOverlapping(360);

Artisan::command('air:check-infrastructure', function () {
    try {
        DB::select('SELECT 1');

        return 0;
    } catch (Throwable) {
        return 1;
    }
});

Artisan::command('air:verify-history', function (CleanestCityService $service) {
    $coverage = $service->coverage();
    $this->info(json_encode($coverage, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    return $coverage['historical_import']['verified_twelve_month_history'] ? 0 : 1;
});
