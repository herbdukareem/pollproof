<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Makes sure a check-in can be saved without a GPS position (USSD check-ins have none).
 *
 * 2026_10_30_100000_allow_ussd_check_ins does this, but a server can have it recorded as run
 * while the columns are still required (for example after it stopped part-way through, since
 * MySQL can't roll back an ALTER). Then every USSD check-in fails with "Field 'latitude'
 * doesn't have a default value". This puts the columns right whatever state they are in.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('check_ins', 'channel')) {
            Schema::table('check_ins', function (Blueprint $table) {
                $table->string('channel', 10)->default('app')->after('polling_unit_id')->comment('app, ussd');
            });
        }

        $required = collect(Schema::getColumns('check_ins'))
            ->whereIn('name', ['latitude', 'longitude', 'accuracy_m'])
            ->reject(fn ($c) => $c['nullable'])
            ->pluck('name');
        if ($required->isEmpty()) {
            return;
        }

        Schema::table('check_ins', function (Blueprint $table) use ($required) {
            if ($required->contains('latitude')) {
                $table->decimal('latitude', 10, 7)->nullable()->change();
            }
            if ($required->contains('longitude')) {
                $table->decimal('longitude', 10, 7)->nullable()->change();
            }
            if ($required->contains('accuracy_m')) {
                $table->unsignedInteger('accuracy_m')->nullable()->change();
            }
        });
    }

    public function down(): void
    {
        // Nothing to undo: putting NOT NULL back would break existing USSD check-ins.
    }
};
