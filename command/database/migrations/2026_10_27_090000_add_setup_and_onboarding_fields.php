<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->date('freeze_at')->nullable()->comment('Structure and roster lock on this date');
            $table->boolean('require_selfie')->default(false);
        });

        Schema::table('users', function (Blueprint $table) {
            // Command-room accounts
            $table->boolean('must_change_password')->default(false);
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamp('last_active_at')->nullable();
            // Field onboarding: invited → app installed (device) → practice sent → trained → confirmed
            $table->boolean('is_backup')->default(false)->comment('Backup LGA supervisor');
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('practice_passed_at')->nullable();
            $table->unsignedTinyInteger('quiz_score')->nullable();
            $table->timestamp('trained_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('unavailable_at')->nullable()->comment('Said they cannot make election day');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['must_change_password', 'deactivated_at', 'last_active_at', 'is_backup', 'invited_at', 'practice_passed_at', 'quiz_score', 'trained_at', 'confirmed_at', 'unavailable_at']);
        });
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn(['freeze_at', 'require_selfie']);
        });
    }
};
