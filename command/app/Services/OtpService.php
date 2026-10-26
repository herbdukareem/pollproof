<?php

namespace App\Services;

use App\Models\OtpCode;
use App\Services\Sms\SmsSender;
use Illuminate\Support\Facades\Hash;

final class OtpService
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly SmsSender $sms) {}

    public function send(string $phone): void
    {
        $code = (string) random_int(100000, 999999);
        OtpCode::where('phone', $phone)->whereNull('used_at')->update(['used_at' => now()]);
        OtpCode::create([
            'phone' => $phone,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes((int) config('pollproof.otp_ttl_minutes')),
        ]);
        $this->sms->send($phone, "Your PollProof code is {$code}. It expires in ".config('pollproof.otp_ttl_minutes').' minutes. Do not share it.');
    }

    public function verify(string $phone, string $code): bool
    {
        $otp = OtpCode::where('phone', $phone)->whereNull('used_at')->where('expires_at', '>', now())->latest('id')->first();
        if (! $otp || $otp->attempts >= self::MAX_ATTEMPTS) {
            return false;
        }
        if (! Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');

            return false;
        }
        $otp->update(['used_at' => now()]);

        return true;
    }
}
