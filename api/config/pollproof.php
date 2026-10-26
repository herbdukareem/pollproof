<?php

return [
    'sms_driver' => env('POLLPROOF_SMS_DRIVER', 'log'),
    'sms_webhook_token' => env('POLLPROOF_SMS_WEBHOOK_TOKEN'),
    'otp_ttl_minutes' => (int) env('POLLPROOF_OTP_TTL_MINUTES', 10),
    'evidence_disk' => env('POLLPROOF_EVIDENCE_DISK', 'local'),
];
