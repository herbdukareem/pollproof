<?php

namespace App\Jobs;

use App\Services\Sms\SmsSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Bulk messages (invites, reminders) go through the queue so a page of 3,000 agents doesn't wait on the gateway. */
class SendSms implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 60];

    public function __construct(public string $phone, public string $message) {}

    public function handle(SmsSender $sms): void
    {
        $sms->send($this->phone, $this->message);
    }
}
