<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;

/** Development driver: writes messages to the log instead of sending them. */
final class LogSmsSender implements SmsSender
{
    /** @var list<array{phone: string, message: string}> */
    public static array $sent = [];

    public function send(string $phone, string $message): void
    {
        self::$sent[] = ['phone' => $phone, 'message' => $message];
        Log::info('SMS to '.$phone.': '.$message);
    }
}
