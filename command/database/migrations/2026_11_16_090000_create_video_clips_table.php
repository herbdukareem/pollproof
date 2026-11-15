<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Videos field staff record on their phones: a result being announced, an incident, a collation
 * centre. Each arrives in pieces, so a dropped connection resumes where it stopped; `received`
 * is how many bytes are in so far, and the clip counts once all `size` bytes match `sha256`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_clips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('client_id')->comment('the phone\'s id for the clip, so a resend finds the same upload');
            $table->string('kind', 20)->comment('result, incident, collation, other');
            $table->foreignId('election_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('polling_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ward_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lga_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('incident_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->string('mime', 40);
            $table->unsignedBigInteger('size');
            $table->unsignedBigInteger('received')->default(0);
            $table->char('sha256', 64);
            $table->unsignedInteger('duration_s')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamp('captured_at');
            $table->string('path')->nullable()->comment('on the evidence disk, once complete');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'client_id']);
            $table->index(['campaign_id', 'completed_at']);
            $table->index(['campaign_id', 'polling_unit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_clips');
    }
};
