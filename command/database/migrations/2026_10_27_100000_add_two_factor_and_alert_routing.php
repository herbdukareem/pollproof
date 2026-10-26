<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->unsignedBigInteger('two_factor_last_step')->nullable()->comment('Last TOTP time step used, so a code works once');
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->boolean('require_two_factor')->default(false);
            $table->time('checkin_deadline')->default('08:30')->comment('Agents not checked in by this time on election day raise an alert');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'two_factor_last_step']));
        Schema::table('campaigns', fn (Blueprint $t) => $t->dropColumn(['require_two_factor', 'checkin_deadline']));
    }
};
