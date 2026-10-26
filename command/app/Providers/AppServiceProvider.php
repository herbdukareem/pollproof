<?php

namespace App\Providers;

use App\Services\Sms\LogSmsSender;
use App\Services\Sms\SmsSender;
use App\Services\Sms\TermiiSmsSender;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SmsSender::class, fn () => match (config('pollproof.sms_driver')) {
            'termii' => new TermiiSmsSender(
                apiKey: (string) config('pollproof.termii.api_key'),
                senderId: (string) config('pollproof.termii.sender_id'),
                channel: (string) config('pollproof.termii.channel'),
                baseUrl: (string) config('pollproof.termii.base_url'),
            ),
            default => new LogSmsSender,
        });
    }

    public function boot(): void
    {
        RateLimiter::for('otp', fn (Request $request) => [
            Limit::perMinute(3)->by('otp:'.$request->input('phone')),
            Limit::perMinute(20)->by('otp-ip:'.$request->ip()),
        ]);
    }
}
