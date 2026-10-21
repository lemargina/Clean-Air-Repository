<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $t) {
            $t->unsignedBigInteger('row_checkpoint')->default(0);
            $t->unsignedBigInteger('invalid')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('import_batches', fn (Blueprint $t) => $t->dropColumn(['row_checkpoint', 'invalid']));
    }
};
