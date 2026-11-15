<?php

namespace App\Jobs;

use App\Models\VideoCheck;
use App\Services\VideoChecker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Transcribes a result announcement video and compares it with the signed result. */
class CheckResultVideo implements ShouldQueue
{
    use Queueable;

    /** Transcribing three minutes of audio can take a while on a slow link. */
    public int $timeout = 300;

    public int $tries = 2;

    public function __construct(public int $checkId) {}

    public function handle(VideoChecker $checker): void
    {
        $check = VideoCheck::find($this->checkId);
        if ($check && $check->status !== 'done') {
            $checker->run($check);
        }
    }
}
