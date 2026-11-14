<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the command room reads out loud: one row per thing worth saying, written once so every
 * screen says the same sentence and nobody hears it twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20)->comment('incident, alert, escalation, race');
            $table->string('topic', 40)->comment('alert or incident type; race: lead, spread, progress');
            $table->string('severity', 10)->default('medium');
            $table->string('dedupe_key', 160);
            $table->string('text', 500);
            $table->char('text_hash', 40);
            $table->unsignedBigInteger('lga_id')->nullable();
            $table->unsignedBigInteger('ward_id')->nullable();
            $table->string('link', 200)->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'dedupe_key']);
            $table->index(['campaign_id', 'text_hash', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
