<?php

use App\Models\Campaign;
use App\Models\NotificationRule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A reviewer's flag on a result: a reason and a comment, kept beside the signed result (which never changes).
 * Others can add comments to the flag, and it is cleared with a note once dealt with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('result_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('result_submission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('polling_unit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reason');
            $table->text('note');
            $table->string('status')->default('open')->index()->comment('open, cleared');
            $table->foreignId('cleared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cleared_at')->nullable();
            $table->text('clear_note')->nullable();
            $table->timestamps();
            $table->index(['campaign_id', 'status']);
        });

        Schema::create('result_flag_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('result_flag_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
        });

        // Existing campaigns get the notification rule for the new alert type.
        Campaign::query()->each(fn (Campaign $c) => NotificationRule::seedDefaults($c));
    }

    public function down(): void
    {
        Schema::dropIfExists('result_flag_comments');
        Schema::dropIfExists('result_flags');
    }
};
