<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('event')->comment('Alert type this rule routes');
            $table->jsonb('recipients')->comment('Role values told first');
            $table->jsonb('channels')->comment('app, sms, email');
            $table->unsignedSmallInteger('escalate_after_minutes')->nullable();
            $table->jsonb('escalate_to')->nullable()->comment('Role values told if nobody acts');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['campaign_id', 'event']);
        });

        Schema::create('notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('alert_id')->nullable()->constrained()->nullOnDelete();
            $table->string('severity')->default('medium');
            $table->string('title');
            $table->text('body');
            $table->string('url')->nullable();
            $table->boolean('escalation')->default(false);
            $table->boolean('test')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'read_at']);
        });

        Schema::table('alerts', function (Blueprint $table) {
            $table->timestamp('opened_at')->nullable()->comment('When it last became open; escalation counts from here');
            $table->timestamp('escalated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('alerts', fn (Blueprint $t) => $t->dropColumn(['opened_at', 'escalated_at']));
        Schema::dropIfExists('notices');
        Schema::dropIfExists('notification_rules');
    }
};
