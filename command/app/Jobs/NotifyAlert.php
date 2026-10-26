<?php

namespace App\Jobs;

use App\Models\Alert;
use App\Services\AlertNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class NotifyAlert implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $alertId, public bool $escalation = false) {}

    public function handle(AlertNotifier $notifier): void
    {
        $alert = Alert::find($this->alertId);
        if ($alert && $alert->status !== 'resolved') {
            $notifier->notify($alert, $this->escalation);
        }
    }
}
