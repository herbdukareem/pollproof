<?php

return [
    'sms_driver' => env('POLLPROOF_SMS_DRIVER', 'log'),
    'sms_webhook_token' => env('POLLPROOF_SMS_WEBHOOK_TOKEN'),
    'otp_ttl_minutes' => (int) env('POLLPROOF_OTP_TTL_MINUTES', 10),
    'evidence_disk' => env('POLLPROOF_EVIDENCE_DISK', 'local'),

    // Election-day times (check-in deadline) are in this zone.
    'timezone' => env('POLLPROOF_TIMEZONE', 'Africa/Lagos'),

    // Number agents text results and YES/NO replies to (shown in the app's fallback screen).
    'sms_number' => env('POLLPROOF_SMS_NUMBER'),

    // Link sent to agents in the invite SMS.
    'app_download_url' => env('POLLPROOF_APP_URL', 'https://pollproof.ng/app'),

    // Agents pass training with this many correct answers out of the quiz.
    'quiz_pass_mark' => 8,

    'termii' => [
        'api_key' => env('TERMII_API_KEY'),
        'sender_id' => env('TERMII_SENDER_ID', 'PollProof'),
        'channel' => env('TERMII_CHANNEL', 'dnd'),
        'base_url' => env('TERMII_BASE_URL', 'https://api.ng.termii.com'),
    ],
];
