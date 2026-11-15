<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A campaign runs in several races: governorship and the state assembly seats on one day,
 * presidential and the National Assembly on another, council polls and re-runs on others.
 * Each race has its own area, parties on the ballot, results, collation, IReV and declaration.
 *
 * Every existing campaign becomes one governorship race covering the whole state, and every
 * record it already has is moved into that race.
 */
return new class extends Migration
{
    private const RACE_ALERTS = ['overvote', 'collation_mismatch', 'irev_missing', 'irev_mismatch', 'result_flagged'];

    private const RACE_TABLES = ['result_submissions', 'collation_entries', 'irev_entries', 'tribunal_cases', 'alerts', 'announcements'];

    public function up(): void
    {
        Schema::create('elections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20)->comment('presidential, senate, reps, governorship, assembly, lga_chair, councillor');
            $table->string('name', 80);
            $table->string('code', 12)->comment('short code agents use by SMS and USSD, e.g. GOV, SEN-E');
            $table->date('election_date');
            $table->boolean('is_rerun')->default(false)->comment('a by-election, supplementary or re-run poll');
            $table->decimal('share_threshold', 5, 4)->default(0.25);
            $table->decimal('area_fraction', 5, 4)->default(2 / 3);
            $table->boolean('announce')->default(true)->comment('read race news out loud in the command room');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('declared_at')->nullable();
            $table->json('declared_votes')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'code']);
        });

        // Where a race is fought: whole LGAs (ward_id null) or single wards. None means the whole state.
        Schema::create('election_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('election_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lga_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ward_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unique(['election_id', 'lga_id', 'ward_id']);
        });

        // The parties on a race's ballot, with their candidates.
        Schema::create('election_party', function (Blueprint $table) {
            $table->id();
            $table->foreignId('election_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_id')->constrained()->cascadeOnDelete();
            $table->string('candidate', 120)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unique(['election_id', 'party_id']);
        });

        foreach (self::RACE_TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('election_id')->nullable()->after('campaign_id')->constrained()->cascadeOnDelete();
            });
        }

        foreach (DB::table('campaigns')->get() as $c) {
            $id = DB::table('elections')->insertGetId([
                'campaign_id' => $c->id,
                'type' => 'governorship',
                'name' => 'Governorship',
                'code' => 'GOV',
                'election_date' => $c->election_date,
                'share_threshold' => $c->lga_share_threshold ?? 0.25,
                'area_fraction' => $c->lga_count_fraction ?? 2 / 3,
                'declared_at' => $c->declared_at ?? null,
                'declared_votes' => $c->declared_votes ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            foreach (DB::table('parties')->where('campaign_id', $c->id)->orderBy('sort_order')->get() as $i => $p) {
                DB::table('election_party')->insert(['election_id' => $id, 'party_id' => $p->id, 'sort_order' => $i]);
            }
            foreach (['result_submissions', 'collation_entries', 'irev_entries', 'tribunal_cases'] as $name) {
                DB::table($name)->where('campaign_id', $c->id)->update(['election_id' => $id]);
            }
            // Only alerts about a result sheet belong to the race; an incident or an absent agent is about none.
            DB::table('alerts')->where('campaign_id', $c->id)->whereIn('type', self::RACE_ALERTS)->update(['election_id' => $id]);
            DB::table('announcements')->where('campaign_id', $c->id)->where('kind', 'race')->update(['election_id' => $id]);
        }

        // Results always belong to a race; alerts, announcements and cases may be about none.
        foreach (['result_submissions', 'collation_entries', 'irev_entries'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedBigInteger('election_id')->nullable(false)->change();
            });
        }
        Schema::table('result_submissions', function (Blueprint $table) {
            $table->index(['election_id', 'polling_unit_id', 'is_current']);
        });
    }

    public function down(): void
    {
        Schema::table('result_submissions', fn (Blueprint $t) => $t->dropIndex(['election_id', 'polling_unit_id', 'is_current']));
        foreach (self::RACE_TABLES as $name) {
            Schema::table($name, fn (Blueprint $t) => $t->dropConstrainedForeignId('election_id'));
        }
        Schema::dropIfExists('election_party');
        Schema::dropIfExists('election_areas');
        Schema::dropIfExists('elections');
    }
};
