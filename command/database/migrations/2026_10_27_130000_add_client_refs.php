<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The phone tags each check-in and incident with an ID it makes up, so a retried send doesn't create a duplicate. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['check_ins', 'incidents'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->uuid('client_ref')->nullable();
                $table->unique(['user_id', 'client_ref']);
            });
        }
    }

    public function down(): void
    {
        foreach (['check_ins', 'incidents'] as $t) {
            Schema::table($t, fn (Blueprint $table) => $table->dropUnique(['user_id', 'client_ref']));
            Schema::table($t, fn (Blueprint $table) => $table->dropColumn('client_ref'));
        }
    }
};
