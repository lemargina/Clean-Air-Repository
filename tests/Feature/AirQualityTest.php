<?php

namespace Tests\Feature;

use App\Services\AirQuality\Aggregator;
use App\Services\AirQuality\ArchiveService;
use App\Services\AirQuality\BackfillService;
use App\Services\AirQuality\CleanestCityService;
use App\Services\AirQuality\Collector;
use App\Services\AirQuality\Geography;
use App\Services\AirQuality\IngestionService;
use App\Services\AirQuality\MeasurementParser;
use App\Services\AirQuality\SourceClient;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AirQualityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['air.request_interval_ms' => 0]);
        DB::table('cities')->insert(['name_bg' => 'София', 'name_en' => 'Sofia', 'latitude' => 42.6975, 'longitude' => 23.3242, 'population' => 1000000, 'external_identifier' => 'test:sofia', 'geographic_source' => 'test only']);
    }

    private function row(array $changes = []): array
    {
        return array_replace(['sensor_id' => '1', 'location' => '2', 'lat' => '42.6975', 'lon' => '23.3242', 'timestamp' => '2026-01-01T10:00:00', 'P1' => '12.5', 'P2' => '5.25'], $changes);
    }

    private function live(array $changes = []): array
    {
        return array_replace_recursive(['sensor' => ['id' => 1, 'sensor_type' => ['name' => 'SDS011']], 'location' => ['id' => 2, 'country' => 'BG', 'latitude' => '42.6975', 'longitude' => '23.3242', 'indoor' => 0], 'timestamp' => '2026-01-01 10:00:00', 'sensordatavalues' => [['value_type' => 'P1', 'value' => '12.5'], ['value_type' => 'P2', 'value' => '5.25']]], $changes);
    }

    private function ingest(array $rows): int
    {
        return app(IngestionService::class)->ingest(array_map(fn ($r) => app(MeasurementParser::class)->parse($r), $rows), 'test:synthetic');
    }

    private function day(string $day, int $sensor = 1, float $pm = 5, int $hours = 24): void
    {
        $start = CarbonImmutable::parse($day, 'Europe/Sofia')->utc();
        $rows = [];
        for ($h = 0; $h < $hours; $h++) {
            for ($m = 0; $m < 2; $m++) {
                $rows[] = $this->row(['sensor_id' => (string) $sensor, 'timestamp' => $start->addHours($h)->addMinutes($m * 30)->format('Y-m-d\TH:i:s'), 'P2' => $pm, 'P1' => $pm + 5]);
            }
        }$this->ingest($rows);
        app(Aggregator::class)->day($day);
    }

    public function test_extracts_real_source_field_mapping(): void
    {
        $r = app(MeasurementParser::class)->live($this->live());
        $this->assertSame(5.25, $r['pm25']);
        $this->assertSame(12.5, $r['pm10']);
        $this->assertSame('2026-01-01', $r['local_date']);
    }

    public static function invalidRows(): array
    {
        return [['lat', '90'], ['lon', 'NaN'], ['lat', '42.69x'], ['lat', null], ['P2', null], ['P2', '-1'], ['P2', 'INF'], ['P2', 'NaN'], ['P1', '-4'], ['timestamp', 'tomorrow'], ['sensor_id', 'abc'], ['timestamp', '2000-01-01T00:00:00']];
    }

    #[DataProvider('invalidRows')]
    public function test_invalid_records_are_rejected(string $field, mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(MeasurementParser::class)->parse($this->row([$field => $value]));
    }

    public function test_polygon_rejects_foreign_points_inside_bbox(): void
    {
        $this->assertFalse(app(Geography::class)->contains(41.2, 23));
        $this->assertTrue(app(Geography::class)->contains(42.6975, 23.3242));
    }

    public function test_remote_sensor_stays_unassigned(): void
    {
        $a = app(Geography::class)->assign(43.7, 24.0);
        $this->assertNull($a['city_id']);
    }

    public function test_pm25_above_pm10_is_flagged_and_retained(): void
    {
        $this->ingest([$this->row(['P2' => 20])]);
        $this->assertDatabaseHas('measurements', ['is_suspicious' => true]);
    }

    public function test_duplicates_and_repeated_collection_are_idempotent(): void
    {
        Http::fake(['*' => Http::response([$this->live()])]);
        $a = app(Collector::class)->collect();
        $b = app(Collector::class)->collect();
        $this->assertSame(1, $a['inserted']);
        $this->assertSame(1, $b['duplicates']);
        $this->assertDatabaseCount('measurements', 1);
    }

    public function test_timeout_records_failure_without_losing_data(): void
    {
        $this->ingest([$this->row()]);
        Http::fake(['*' => Http::failedConnection()]);
        try {
            app(Collector::class)->collect();
            $this->fail('Expected timeout');
        } catch (ConnectionException) {
        }$this->assertDatabaseCount('measurements', 1);
        $this->assertDatabaseHas('collection_runs', ['status' => 'failed']);
        Http::assertSentCount(3);
    }

    public function test_429_retries_then_succeeds(): void
    {
        Http::fakeSequence()->push([], 429, ['Retry-After' => '0'])->push([$this->live()]);
        $this->assertSame(1, app(Collector::class)->collect()['inserted']);
        Http::assertSentCount(2);
    }

    public function test_long_retry_after_creates_cooldown(): void
    {
        Http::fake(['*' => Http::response([], 429, ['Retry-After' => '120'])]);
        try {
            app(Collector::class)->collect();
            $this->fail();
        } catch (RequestException) {
        }$this->assertGreaterThan(time(), Cache::get('air-source-blocked-until'));
        Cache::forget('air-source-blocked-until');
    }

    public function test_invalid_json_is_not_a_success(): void
    {
        Http::fake(['*' => Http::response('{bad', 200)]);
        try {
            app(Collector::class)->collect();
            $this->fail();
        } catch (\JsonException) {
        }$this->assertDatabaseHas('collection_runs', ['status' => 'failed']);
    }

    public function test_sensor_location_history_is_preserved_even_for_late_import(): void
    {
        $this->ingest([$this->row()]);
        $this->ingest([$this->row(['timestamp' => '2026-01-02T10:00:00', 'location' => '3', 'lat' => '42.15', 'lon' => '24.75'])]);
        $this->ingest([$this->row(['timestamp' => '2025-12-31T10:00:00'])]);
        $this->assertDatabaseCount('sensors', 1);
        $this->assertDatabaseCount('sensor_locations', 2);
        $this->assertDatabaseHas('sensors', ['external_location_id' => 3]);
        $this->assertNotSame(DB::table('measurements')->orderBy('measured_at')->first()->sensor_location_id, DB::table('measurements')->orderByDesc('measured_at')->first()->sensor_location_id);
    }

    public function test_no_measurements_produces_no_winner(): void
    {
        $r = app(CleanestCityService::class)->calculate('2026-01-01', '2026-01-01');
        $this->assertSame([], $r['winners']);
        $this->assertSame('no_qualifying_observations', $r['excluded'][0]['reason']);
    }

    public function test_insufficient_daily_and_period_coverage_excluded(): void
    {
        $this->day('2026-01-01', hours: 17);
        $this->assertDatabaseCount('city_daily_statistics', 0);
        $this->day('2026-01-02');
        $r = app(CleanestCityService::class)->calculate('2026-01-01', '2026-01-04');
        $this->assertSame([], $r['winners']);
        $this->assertSame(25.0, $r['excluded'][0]['coverage_percent']);
    }

    public function test_hour_requires_samples_spread_across_fifteen_minutes(): void
    {
        $this->ingest([$this->row(), $this->row(['timestamp' => '2026-01-01T10:01:00'])]);
        app(Aggregator::class)->day('2026-01-01');
        $this->assertDatabaseCount('sensor_daily_statistics', 0);
    }

    public function test_daily_recalculation_and_sensor_equal_weight(): void
    {
        $this->day('2026-01-01', 1, 5);
        $this->day('2026-01-01', 2, 15);
        app(Aggregator::class)->day('2026-01-01');
        $this->assertDatabaseCount('city_daily_statistics', 1);
        $this->assertEquals(10, DB::table('city_daily_statistics')->value('pm25'));
        $this->assertDatabaseCount('aggregation_days', 0);
    }

    public function test_dst_days_have_actual_expected_hours(): void
    {
        $this->day('2026-03-29', hours: 23);
        $this->assertEquals(23, DB::table('sensor_daily_statistics')->value('expected_hours'));
        $this->day('2025-10-26', hours: 25);
        $this->assertEquals(25, DB::table('sensor_daily_statistics')->where('date', '2025-10-26')->value('expected_hours'));
    }

    public function test_correct_ranking_and_ties(): void
    {
        $other = DB::table('cities')->insertGetId(['name_bg' => 'Пловдив', 'latitude' => 42.15, 'longitude' => 24.75, 'external_identifier' => 'test:plovdiv', 'geographic_source' => 'test']);
        $this->day('2026-01-01', pm: 8);
        DB::table('city_daily_statistics')->insert(['city_id' => $other, 'date' => '2026-01-01', 'pm25' => 4, 'pm10' => 9, 'sensors' => 1, 'samples' => 48, 'sensor_hours' => 24]);
        DB::table('sensor_daily_statistics')->insert(['sensor_id' => DB::table('sensors')->value('id'), 'city_id' => $other, 'date' => '2026-01-01', 'pm25' => 4, 'pm10' => 9, 'samples' => 48, 'valid_hours' => 24, 'pm10_hours' => 24, 'expected_hours' => 24, 'qualifies' => true]);
        $service = app(CleanestCityService::class);
        $r = $service->calculate('2026-01-01', '2026-01-01');
        $this->assertSame('Пловдив', $r['winners'][0]['city']);
        DB::table('city_daily_statistics')->update(['pm25' => 4]);
        $this->assertCount(2, $service->calculate('2026-01-01', '2026-01-01')['winners']);
    }

    public function test_api_validation_and_unknown_city(): void
    {
        foreach (['from=bad&to=2026-01-01', 'from=2026-01-02&to=2026-01-01', 'from=2099-01-01&to=2099-01-02', 'from=2026-02-30&to=2026-03-01'] as $query) {
            $this->getJson('/api/v1/air-quality/cleanest?'.$query)->assertStatus(422)->assertJsonStructure(['errors']);
        }$this->getJson('/api/v1/air-quality/cities/999?from=2026-01-01&to=2026-01-01')->assertNotFound();
    }

    public function test_local_api_survives_source_outage_and_has_attribution(): void
    {
        $this->day('2026-01-01');
        Http::fake(['*' => Http::response('', 503)]);
        $this->getJson('/api/v1/air-quality/cleanest?from=2026-01-01&to=2026-01-01')->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('winners.0.pm25', 5)->assertJsonStructure(['coverage' => ['source'], 'methodology']);
        Http::assertNothingSent();
        $this->get('/?from=2026-01-01&to=2026-01-01')->assertOk()->assertSee('София');
        $this->getJson('/api/v1/air-quality/cleanest?from=2025-01-01&to=2026-01-01')->assertUnprocessable();
    }

    public function test_health_and_protected_metrics(): void
    {
        $this->get('/health/live')->assertOk();
        $this->get('/health/ready')->assertOk()->assertJsonPath('data_state', 'degraded');
        $this->get('/metrics')->assertForbidden();
        config(['air.metrics_token' => 'test']);
        $this->withToken('test')->get('/metrics')->assertOk()->assertSee('air_database_up 1');
    }

    public function test_csv_stream_and_duplicates(): void
    {
        $csv = "sensor_id;sensor_type;location;lat;lon;timestamp;P1;P2\n1;SDS011;2;42.6975;23.3242;2026-01-01T10:00:00;12;5\n";
        for ($i = 0; $i < 2; $i++) {
            $s = fopen('php://temp', 'w+');
            fwrite($s, $csv);
            rewind($s);
            $r = app(BackfillService::class)->csv($s, 'test:csv', '2026-01-01', '2026-01-01');
            fclose($s);
            $this->assertSame(1 - $i, $r['inserted']);
        }
    }

    public function test_archive_verifies_before_delete_and_restores(): void
    {
        $this->day('2022-01-02');
        $service = app(ArchiveService::class);
        $service->exportMonth('2022-01-01');
        $this->assertDatabaseCount('measurements', 0);
        $this->assertDatabaseCount('city_daily_statistics', 1);
        $this->assertSame(48, $service->restore('2022-01-01'));
        $this->assertSame(0, $service->restore('2022-01-01'));
        $r = DB::table('archived_measurement_partitions')->first();
        unlink($r->path);
    }

    public function test_corrupt_archive_restore_rejected_without_deleting_data(): void
    {
        $this->day('2022-02-01');
        $s = app(ArchiveService::class);
        $s->exportMonth('2022-02-01');
        $r = DB::table('archived_measurement_partitions')->first();
        file_put_contents($r->path, 'broken');
        try {
            $s->restore('2022-02-01');
            $this->fail();
        } catch (\RuntimeException $e) {
            $this->assertSame('Missing or corrupt archive', $e->getMessage());
        }$this->assertDatabaseCount('city_daily_statistics', 1);
        unlink($r->path);
    }

    public function test_archiving_recent_data_is_rejected(): void
    {
        $this->ingest([$this->row()]);
        $this->expectException(\RuntimeException::class);
        app(ArchiveService::class)->exportMonth('2026-01-01');
    }

    public function test_empty_ui_has_disabled_dates(): void
    {
        $this->get('/')->assertOk()->assertSee('Все още няма локални измервания')->assertSee('disabled');
    }

    public function test_export_failure_never_deletes_and_retry_recovers(): void
    {
        $this->day('2022-04-02');
        $path = storage_path('app/archives/2022-04.jsonl.gz.partial');
        @mkdir($path, 0750, true);
        try {
            app(ArchiveService::class)->exportMonth('2022-04-01');
            $this->fail('Expected archive failure');
        } catch (\Throwable) {
        } finally {
            @rmdir($path);
        }
        $this->assertDatabaseCount('measurements', 48);
        app(ArchiveService::class)->exportMonth('2022-04-01');
        $this->assertDatabaseCount('measurements', 0);
        $r = DB::table('archived_measurement_partitions')->first();
        unlink($r->path);
    }

    public function test_backfill_interruption_resumes_from_completed_member(): void
    {
        $dir = storage_path('app/imports');
        if (! is_dir($dir)) {
            mkdir($dir, 0750, true);
        }$path = $dir.'/2026-01.zip';
        @unlink($path);
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE);
        $head = "sensor_id;sensor_type;location;lat;lon;timestamp;P1;P2\n";
        $zip->addFromString('first.csv', $head."1;SDS011;2;42.6975;23.3242;2026-01-01T10:00:00;12;5\n");
        $zip->addFromString('second.csv', $head."1;SDS011;2;42.6975;23.3242;2026-01-02T10:00:00;12;5\n");
        $zip->close();
        $parser = \Mockery::mock(MeasurementParser::class, [app(Geography::class)])->makePartial();
        $parser->shouldReceive('parse')->with(\Mockery::on(fn ($r) => str_contains($r['timestamp'], '2026-01-02')))->once()->andThrow(new \RuntimeException('Simulated interruption'));
        $service = new BackfillService(app(SourceClient::class), $parser, app(IngestionService::class), app(Aggregator::class));
        try {
            $service->month('2026-01', '2026-01-01', '2026-01-02');
            $this->fail();
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated interruption', $e->getMessage());
        }
        $this->assertDatabaseHas('import_batches', ['checkpoint' => 1, 'status' => 'failed']);
        $this->assertDatabaseCount('measurements', 1);
        app(BackfillService::class)->month('2026-01', '2026-01-01', '2026-01-02');
        $this->assertDatabaseHas('import_batches', ['checkpoint' => 2, 'status' => 'complete']);
        $this->assertDatabaseCount('measurements', 2);
        Http::assertNothingSent();
    }

    public function test_single_month_csv_resumes_inside_member(): void
    {
        $path = storage_path('app/imports/2026-02.zip');
        @unlink($path);
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE);
        $csv = "sensor_id;sensor_type;location;lat;lon;timestamp;P1;P2\n";
        for ($i = 0; $i < 1200; $i++) {
            $at = CarbonImmutable::parse('2026-02-01', 'UTC')->addMinutes($i)->format('Y-m-d\TH:i:s');
            $csv .= "1;SDS011;2;42.6975;23.3242;$at;12;5\n";
        }
        $zip->addFromString('2026-02_sds011.csv', $csv);
        $zip->close();
        $parser = \Mockery::mock(MeasurementParser::class, [app(Geography::class)])->makePartial();
        $stop = CarbonImmutable::parse('2026-02-01', 'UTC')->addMinutes(700)->format('Y-m-d\TH:i:s');
        $parser->shouldReceive('parse')->with(\Mockery::on(fn ($r) => $r['timestamp'] === $stop))->once()->andThrow(new \RuntimeException('Interrupted inside CSV'));
        $service = new BackfillService(app(SourceClient::class), $parser, app(IngestionService::class), app(Aggregator::class));
        try {
            $service->month('2026-02', '2026-02-01', '2026-02-02');
            $this->fail();
        } catch (\RuntimeException $e) {
            $this->assertSame('Interrupted inside CSV', $e->getMessage());
        }
        $this->assertDatabaseHas('import_batches', ['checkpoint' => 0, 'row_checkpoint' => 500, 'status' => 'failed']);
        $this->assertDatabaseCount('measurements', 500);
        app(BackfillService::class)->month('2026-02', '2026-02-01', '2026-02-02');
        $this->assertDatabaseCount('measurements', 1200);
        $this->assertDatabaseHas('dataset_state', ['raw_count' => 1200]);
        $this->assertDatabaseHas('import_batches', ['row_checkpoint' => 0, 'processed' => 1200, 'status' => 'complete']);
    }

    public function test_archived_daily_statistics_cannot_be_recomputed_without_restore(): void
    {
        $this->day('2022-05-02');
        app(ArchiveService::class)->exportMonth('2022-05-01');
        try {
            app(Aggregator::class)->day('2022-05-02');
            $this->fail();
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Restore archived raw', $e->getMessage());
        }
        $this->assertDatabaseCount('city_daily_statistics', 1);
        $r = DB::table('archived_measurement_partitions')->first();
        unlink($r->path);
    }

    public function test_different_sensor_reporting_frequencies_have_equal_daily_weight(): void
    {
        $this->day('2026-01-01', 1, 5);
        $this->day('2026-01-01', 2, 15);
        $start = CarbonImmutable::parse('2026-01-01', 'Europe/Sofia')->utc();
        $rows = [];
        for ($h = 0; $h < 24; $h++) {
            foreach ([15, 45] as $m) {
                $rows[] = $this->row(['timestamp' => $start->addHours($h)->addMinutes($m)->format('Y-m-d\TH:i:s'), 'P2' => 5]);
            }
        }
        $this->ingest($rows);
        app(Aggregator::class)->day('2026-01-01');
        $this->assertEquals(10, DB::table('city_daily_statistics')->value('pm25'));
    }

    public function test_date_validation_rejects_normalized_source_calendar_date(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(MeasurementParser::class)->parse($this->row(['timestamp' => '2026-02-30T10:00:00']));
    }

    public function test_verified_real_csv_fixture_preserves_provenance_and_values(): void
    {
        $h = fopen(base_path('tests/Fixtures/sensor-community-53351-2026-10-19.csv'), 'r');
        $source = 'https://archive.sensor.community/2026-10-19/2026-10-19_sds011_sensor_53351.csv';
        try {
            $r = app(BackfillService::class)->csv($h, $source, '2026-10-19', '2026-10-19');
        } finally {
            fclose($h);
        }
        $this->assertSame(10, $r['inserted']);
        $this->assertDatabaseHas('measurements', ['source' => $source, 'pm25' => '1.500', 'pm10' => '1.920']);
        $this->assertDatabaseHas('sensors', ['external_sensor_id' => 53351]);
        Http::assertNothingSent();
    }

    public function test_liveness_does_not_touch_database_including_telemetry(): void
    {
        $queries = [];
        DB::listen(function ($event) use (&$queries) {
            $queries[] = $event->sql;
        });
        $this->get('/health/live')->assertOk();
        $this->assertSame([], $queries);
    }
}
