<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Each candidate's photo, per race: a party fields a different candidate in each race. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('election_party', function (Blueprint $table) {
            $table->string('photo_path')->nullable()->after('candidate')->comment('on the evidence disk');
        });
    }

    public function down(): void
    {
        Schema::table('election_party', function (Blueprint $table) {
            $table->dropColumn('photo_path');
        });
    }
};
