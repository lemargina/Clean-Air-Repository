<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dataset_state', function (Blueprint $t) {
            $t->unsignedSmallInteger('id')->primary();
            $t->unsignedBigInteger('raw_count')->default(0);
            $t->date('history_from')->nullable();
            $t->date('history_to')->nullable();
            $t->timestampTz('last_measurement_at')->nullable();
        });
        $r = DB::table('measurements')->selectRaw('COUNT(*) AS count,MIN(local_date) AS first,MAX(local_date) AS last,MAX(measured_at) AS at')->first();
        DB::table('dataset_state')->insert(['id' => 1, 'raw_count' => $r->count, 'history_from' => $r->first, 'history_to' => $r->last, 'last_measurement_at' => $r->at]);
    }

    public function down(): void
    {
        Schema::dropIfExists('dataset_state');
    }
};
