<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI in the command room.
 *
 * video_checks: a result announcement video, transcribed, the figures read out of it, and how
 * they compare with the agent's signed result for the same unit and race. A person reviews every
 * difference: confirmed (the result is flagged) or dismissed (the AI misheard).
 *
 * assistant_threads / assistant_messages: conversations with Ask PollProof. The messages table
 * is Neuron AI's message store; thread and message ids are binary so MySQL compares them exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('video_clip_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('election_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('polling_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('result_submission_id')->nullable()->constrained()->nullOnDelete()->comment('the signed result compared with');
            $table->string('status', 20)->default('queued')->comment('queued, working, done, failed, skipped');
            $table->string('outcome', 20)->nullable()->comment('match, differs, no_result, unclear');
            $table->longText('transcript')->nullable();
            $table->json('heard')->nullable()->comment('the figures read out of the transcript');
            $table->json('differences')->nullable()->comment('{figure: {heard, signed}}');
            $table->string('error', 500)->nullable();
            $table->json('models')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('checked_at')->nullable();
            $table->string('review', 20)->default('open')->comment('open, confirmed, dismissed');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 1000)->nullable();
            $table->timestamps();

            $table->index(['campaign_id', 'outcome', 'review']);
            $table->index(['polling_unit_id', 'election_id']);
        });

        Schema::create('assistant_threads', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            $table->timestamps();

            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('assistant_messages', function (Blueprint $table) {
            $table->id();
            $table->binary('thread_id', 255);
            $table->binary('message_id', 64);
            $table->string('role', 32);
            $table->longText('content')->nullable();
            $table->longText('meta')->nullable();
            $table->dateTime('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['thread_id', 'message_id']);
        });

        // Who hears about a video that differs from its result, for campaigns created before this.
        foreach (DB::table('campaigns')->pluck('id') as $campaignId) {
            DB::table('notification_rules')->insertOrIgnore([
                'campaign_id' => $campaignId, 'event' => 'ai_mismatch', 'enabled' => true,
                'recipients' => json_encode(['verifier', 'legal']), 'channels' => json_encode(['app']),
                'escalate_after_minutes' => 15, 'escalate_to' => json_encode(['situation_room']),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_messages');
        Schema::dropIfExists('assistant_threads');
        Schema::dropIfExists('video_checks');
    }
};
