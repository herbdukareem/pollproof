<?php

use App\Models\Campaign;
use App\Models\NotificationRule;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /** Existing campaigns get the notification rule for check-ins waiting to be confirmed. */
    public function up(): void
    {
        Campaign::query()->each(fn (Campaign $c) => NotificationRule::seedDefaults($c));
    }

    public function down(): void
    {
        NotificationRule::where('event', 'checkin_review')->delete();
    }
};
