<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lgas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->timestamps();
            $table->unique(['campaign_id', 'code']);
        });

        Schema::create('wards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lga_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->timestamps();
            $table->unique(['lga_id', 'code']);
        });

        Schema::create('polling_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ward_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('registered_voters')->nullable();
            $table->timestamps();
            $table->unique(['campaign_id', 'code']);
        });

        Schema::create('parties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('code', 16);
            $table->string('name');
            $table->boolean('is_ours')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['campaign_id', 'code']);
        });

        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->string('phone')->index();
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('public_key', 64)->comment('Base64 Ed25519 public key');
            $table->string('label')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'public_key']);
        });

        Schema::create('check_ins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('polling_unit_id')->constrained()->cascadeOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedInteger('accuracy_m');
            $table->unsignedInteger('distance_m')->nullable();
            $table->string('status')->index()->comment('verified, pending_review, approved, rejected');
            $table->string('selfie_path')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('captured_at');
            $table->timestamps();
        });

        Schema::create('result_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('polling_unit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supersedes_id')->nullable()->constrained('result_submissions')->nullOnDelete();
            $table->string('correction_reason')->nullable();
            $table->string('channel')->default('app')->comment('app, sms, ussd');
            $table->unsignedInteger('accredited');
            $table->unsignedInteger('rejected')->default(0);
            $table->unsignedInteger('total_valid');
            $table->unsignedInteger('total_cast');
            $table->string('photo_path')->nullable();
            $table->string('photo_sha256', 64)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamp('captured_at');
            $table->string('payload_hash', 64)->index();
            $table->text('signature')->nullable();
            $table->boolean('signature_valid')->default(false);
            $table->boolean('is_current')->default(true)->index();
            $table->string('status')->default('pending_review')->index()->comment('pending_review, approved, sent_back');
            $table->jsonb('flags')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note')->nullable();
            $table->timestamps();
        });

        Schema::create('result_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('result_submission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('votes');
            $table->unique(['result_submission_id', 'party_id']);
        });

        Schema::create('collation_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('level')->comment('ward (EC8B), lga (EC8C), state (EC8D)');
            $table->unsignedBigInteger('scope_id')->nullable()->comment('ward_id or lga_id; null for state');
            $table->foreignId('entered_by')->constrained('users')->cascadeOnDelete();
            $table->jsonb('votes')->comment('{"PARTY_CODE": votes}');
            $table->unsignedInteger('accredited')->nullable();
            $table->unsignedInteger('rejected')->default(0);
            $table->unsignedInteger('total_cast');
            $table->string('photo_path')->nullable();
            $table->timestamp('announced_at');
            $table->timestamps();
            $table->index(['campaign_id', 'level', 'scope_id']);
        });

        Schema::create('irev_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('polling_unit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entered_by')->constrained('users')->cascadeOnDelete();
            $table->boolean('not_on_irev')->default(false);
            $table->jsonb('votes')->nullable();
            $table->unsignedInteger('accredited')->nullable();
            $table->unsignedInteger('rejected')->nullable();
            $table->string('screenshot_path')->nullable();
            $table->timestamp('seen_at');
            $table->timestamps();
        });

        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('polling_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->string('severity')->comment('low, medium, high');
            $table->text('description');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->jsonb('media_paths')->nullable();
            $table->string('status')->default('open')->comment('open, handling, resolved');
            $table->string('assigned_to')->nullable();
            $table->jsonb('updates')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
        });

        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('type')->index();
            $table->string('severity')->comment('low, medium, high');
            $table->string('title');
            $table->text('detail');
            $table->nullableMorphs('subject');
            $table->jsonb('data')->nullable();
            $table->string('status')->default('open')->index()->comment('open, acknowledged, resolved');
            $table->string('dedupe_key');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['campaign_id', 'dedupe_key']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->nullableMorphs('subject');
            $table->jsonb('meta')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('prev_hash', 64)->nullable();
            $table->string('hash', 64);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'alerts', 'incidents', 'irev_entries', 'collation_entries', 'result_votes', 'result_submissions', 'check_ins', 'devices', 'otp_codes', 'parties', 'polling_units', 'wards', 'lgas'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
