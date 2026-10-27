<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->timestamp('declared_at')->nullable()->comment('Official declaration; election-day records are read-only from here');
            $table->json('declared_votes')->nullable();
            $table->string('retention_choice')->nullable()->comment('keep_until_cases_end, handover_then_delete, delete_now');
            $table->foreignId('retention_set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('retention_set_at')->nullable();
        });

        Schema::create('tribunal_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('petitioner');
            $table->string('our_role')->default('respondent');
            $table->string('status')->default('open')->comment('open, closed');
            $table->json('milestones')->nullable()->comment('[{date, label}]');
            $table->timestamps();
        });

        Schema::create('tribunal_grounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tribunal_case_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('label')->comment('e.g. OVER-VOTING');
            $table->text('claim');
            $table->text('answer')->nullable();
            $table->string('evidence_note')->nullable();
            $table->json('unit_ids')->nullable()->comment('Polling units whose evidence supports the answer');
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('in_progress')->comment('in_progress, ready');
            $table->timestamps();
        });

        Schema::create('tribunal_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tribunal_case_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_on')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tribunal_tasks');
        Schema::dropIfExists('tribunal_grounds');
        Schema::dropIfExists('tribunal_cases');
        Schema::table('campaigns', fn (Blueprint $t) => $t->dropConstrainedForeignId('retention_set_by'));
        Schema::table('campaigns', fn (Blueprint $t) => $t->dropColumn(['declared_at', 'declared_votes', 'retention_choice', 'retention_set_at']));
    }
};
