<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A campaign is one client in one governorship race. Every domain row carries campaign_id.
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('state_name');
            $table->date('election_date');
            $table->decimal('lga_share_threshold', 5, 4)->default(0.25);
            $table->decimal('lga_count_fraction', 5, 4)->default(2 / 3);
            $table->unsignedInteger('geofence_radius_m')->default(100);
            $table->unsignedInteger('max_gps_accuracy_m')->default(30);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
