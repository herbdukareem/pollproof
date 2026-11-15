<?php

namespace App\Ai\Tools;

use App\Services\AgentAppBuild;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/** For diagnosing PollProof itself: the cron job, the queue, failed jobs, settings, disk space. */
class SystemHealth extends PollProofTool
{
    protected string $name = 'system_health';

    protected ?string $description = "PollProof's own health, for diagnosing problems: when the scheduler (cron job) last ran, jobs waiting in the queue, recent failed jobs and their errors, the SMS, USSD, voice and AI settings, the agent app version on the server, free disk space and the server time.";

    public function __invoke(): array
    {
        $ran = Cache::get('pollproof:scheduler-ran');
        $safe = function (callable $f) {
            try {
                return $f();
            } catch (Throwable) {
                return null;
            }
        };

        return [
            'server_time' => now()->toIso8601String(),
            'scheduler_last_ran' => $ran,
            'scheduler_ok' => $ran ? now()->diffInMinutes($ran, true) <= 3 : false,
            'queue_connection' => config('queue.default'),
            'jobs_waiting' => $safe(fn () => DB::table('jobs')->count()),
            'oldest_job_waiting_minutes' => $safe(fn () => ($t = DB::table('jobs')->min('created_at')) ? (int) floor((time() - $t) / 60) : 0),
            'failed_jobs_last_day' => $safe(fn () => DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count()),
            'recent_failures' => $safe(fn () => DB::table('failed_jobs')->latest('failed_at')->take(3)->get()->map(fn ($j) => [
                'job' => json_decode($j->payload, true)['displayName'] ?? 'job', 'at' => $j->failed_at, 'error' => mb_substr(strtok((string) $j->exception, "\n"), 0, 240),
            ])),
            'sms' => ['driver' => config('pollproof.sms_driver'), 'number_set' => filled(config('pollproof.sms_number'))],
            'ussd_code' => config('pollproof.ussd.code'),
            'voice_on' => (bool) config('pollproof.voice.enabled'),
            'ai' => ['on' => (bool) config('pollproof.ai.enabled'), 'transcribe_model' => config('pollproof.ai.transcribe_model'), 'model' => config('pollproof.ai.model')],
            'agent_app' => $safe(fn () => app(AgentAppBuild::class)->local()),
            'disk_free_gb' => $safe(fn () => round(disk_free_space(storage_path()) / 1073741824, 1)),
            'app_debug' => (bool) config('app.debug'),
        ];
    }
}
