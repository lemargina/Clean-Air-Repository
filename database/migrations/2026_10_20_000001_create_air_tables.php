<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cities', function (Blueprint $t) {
            $t->id();
            $t->string('name_bg');
            $t->string('name_en')->nullable();
            $t->decimal('latitude', 10, 7);
            $t->decimal('longitude', 10, 7);
            $t->string('municipality')->nullable();
            $t->string('region')->nullable();
            $t->unsignedBigInteger('population')->nullable();
            $t->string('external_identifier')->unique();
            $t->string('geographic_source');
        });
        Schema::create('sensors', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('external_sensor_id')->unique();
            $t->unsignedBigInteger('external_location_id');
            $t->string('sensor_type');
            $t->decimal('latitude', 10, 7);
            $t->decimal('longitude', 10, 7);
            $t->foreignId('city_id')->nullable()->constrained();
            $t->string('assignment_method');
            $t->double('assignment_distance_km')->nullable();
            $t->timestampTz('first_seen_at');
            $t->timestampTz('last_seen_at')->index();
            $t->boolean('is_active')->default(true);
        });
        Schema::create('sensor_locations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sensor_id')->constrained();
            $t->string('fingerprint', 64);
            $t->unsignedBigInteger('external_location_id');
            $t->decimal('latitude', 10, 7);
            $t->decimal('longitude', 10, 7);
            $t->foreignId('city_id')->nullable()->constrained();
            $t->string('assignment_method');
            $t->double('assignment_distance_km')->nullable();
            $t->timestampTz('first_seen_at');
            $t->timestampTz('last_seen_at');
            $t->unique(['sensor_id', 'fingerprint']);
        });
        Schema::create('measurements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sensor_id')->constrained();
            $t->foreignId('sensor_location_id')->constrained();
            $t->foreignId('city_id')->nullable()->constrained();
            $t->timestampTz('measured_at');
            $t->date('local_date');
            $t->decimal('pm25', 10, 3);
            $t->decimal('pm10', 10, 3)->nullable();
            $t->boolean('is_suspicious')->default(false);
            $t->string('source');
            $t->timestampTz('ingested_at');
            $t->unique(['sensor_id', 'measured_at']);
            $t->index(['local_date', 'city_id']);
            $t->index('measured_at');
        });
        Schema::create('sensor_daily_statistics', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sensor_id')->constrained();
            $t->foreignId('city_id')->constrained();
            $t->date('date');
            $t->double('pm25');
            $t->double('pm10')->nullable();
            $t->integer('samples');
            $t->integer('valid_hours');
            $t->integer('pm10_hours');
            $t->integer('expected_hours');
            $t->boolean('qualifies');
            $t->unique(['date', 'city_id', 'sensor_id']);
            $t->index(['date', 'qualifies']);
        });
        Schema::create('city_daily_statistics', function (Blueprint $t) {
            $t->id();
            $t->foreignId('city_id')->constrained();
            $t->date('date');
            $t->double('pm25');
            $t->double('pm10')->nullable();
            $t->integer('sensors');
            $t->integer('samples');
            $t->integer('sensor_hours');
            $t->unique(['date', 'city_id']);
        });
        Schema::create('collection_runs', function (Blueprint $t) {
            $t->id();
            $t->timestampTz('started_at');
            $t->timestampTz('finished_at')->nullable();
            $t->string('status');
            $t->integer('source_status')->nullable();
            $t->integer('processed')->default(0);
            $t->integer('inserted')->default(0);
            $t->integer('duplicates')->default(0);
            $t->integer('invalid')->default(0);
            $t->double('duration_seconds')->nullable();
            $t->text('error')->nullable();
        });
        Schema::create('import_batches', function (Blueprint $t) {
            $t->id();
            $t->string('source_file')->unique();
            $t->string('status')->default('pending')->index();
            $t->unsignedBigInteger('checkpoint')->default(0);
            $t->unsignedBigInteger('processed')->default(0);
            $t->unsignedBigInteger('inserted')->default(0);
            $t->string('sha256', 64)->nullable();
            $t->text('error')->nullable();
            $t->timestampsTz();
        });
        Schema::create('data_quality_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sensor_id')->nullable()->constrained();
            $t->timestampTz('measured_at')->nullable();
            $t->string('reason');
            $t->string('source');
            $t->timestampTz('created_at');
        });
        Schema::create('aggregation_days', function (Blueprint $t) {
            $t->date('date')->primary();
            $t->timestampTz('updated_at');
        });
        Schema::create('archived_measurement_partitions', function (Blueprint $t) {
            $t->id();
            $t->date('month')->unique();
            $t->string('path');
            $t->string('sha256', 64);
            $t->unsignedBigInteger('row_count');
            $t->string('status');
            $t->timestampsTz();
        });
        Schema::create('metric_counters', function (Blueprint $t) {
            $t->string('name')->primary();
            $t->double('value')->default(0);
        });
    }

    public function down(): void
    {
        foreach (['metric_counters', 'archived_measurement_partitions', 'aggregation_days', 'data_quality_events', 'import_batches', 'collection_runs', 'city_daily_statistics', 'sensor_daily_statistics', 'measurements', 'sensor_locations', 'sensors', 'cities'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
