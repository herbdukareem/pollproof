<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A check-in by USSD has no GPS position; it waits for the supervisor to confirm it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('check_ins', function (Blueprint $table) {
            $table->string('channel', 10)->default('app')->after('polling_unit_id')->comment('app, ussd');
            $table->decimal('latitude', 10, 7)->nullable()->change();
            $table->decimal('longitude', 10, 7)->nullable()->change();
            $table->unsignedInteger('accuracy_m')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('check_ins', fn (Blueprint $table) => $table->dropColumn('channel'));
    }
};
