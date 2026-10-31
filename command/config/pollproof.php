<?php

return [
    'sms_driver' => env('POLLPROOF_SMS_DRIVER', 'log'),
    'sms_webhook_token' => env('POLLPROOF_SMS_WEBHOOK_TOKEN'),
    'otp_ttl_minutes' => (int) env('POLLPROOF_OTP_TTL_MINUTES', 10),
    'evidence_disk' => env('POLLPROOF_EVIDENCE_DISK', 'local'),

    // On hosting without a process supervisor (Hostinger web hosting), let the every-minute
    // scheduler start a short-lived queue worker instead of running `queue:work` all the time.
    'queue_via_scheduler' => (bool) env('POLLPROOF_QUEUE_VIA_SCHEDULER', false),

    // Election-day times (check-in deadline) are in this zone.
    'timezone' => env('POLLPROOF_TIMEZONE', 'Africa/Lagos'),

    // Number agents text results and YES/NO replies to (shown in the app's fallback screen).
    'sms_number' => env('POLLPROOF_SMS_NUMBER'),

    // USSD through Africa's Talking, for agents whose phones have no data. The callback URL set in the
    // Africa's Talking dashboard is https://<your domain>/api/v1/ussd/africastalking/<token>.
    'ussd' => [
        'token' => env('POLLPROOF_USSD_TOKEN'),
        // The code agents dial, e.g. *384*1234#. Shown in the invite SMS and the app's fallback screen.
        'code' => env('POLLPROOF_USSD_CODE'),
    ],

    // Link sent to agents in the invite SMS.
    'app_download_url' => env('POLLPROOF_APP_URL', 'https://pollproof.ng/app'),

    // Where GitHub Actions publishes each agent-app build (pollproof-agent.apk and version.json).
    // `php artisan pollproof:fetch-agent-app`, or "Get latest build" in the command room, copies it here.
    'agent_app' => [
        'release_url' => env('POLLPROOF_AGENT_APP_RELEASE', 'https://github.com/herbdukareem/pollproof/releases/download/agent-app'),
    ],

    // Agents pass training with this many correct answers out of the quiz.
    'quiz_pass_mark' => 8,

    'termii' => [
        'api_key' => env('TERMII_API_KEY'),
        'sender_id' => env('TERMII_SENDER_ID', 'PollProof'),
        'channel' => env('TERMII_CHANNEL', 'dnd'),
        'base_url' => env('TERMII_BASE_URL', 'https://api.ng.termii.com'),
    ],
];
