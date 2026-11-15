<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A result announcement video read by the AI and compared with the agent's signed result. */
class VideoCheck extends Model
{
    public const OUTCOMES = [
        'differs' => 'Differs from the signed result',
        'match' => 'Matches the signed result',
        'no_result' => 'No signed result to compare',
        'unclear' => 'No figures could be made out',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['heard' => 'array', 'differences' => 'array', 'models' => 'array', 'checked_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(VideoClip::class, 'video_clip_id');
    }

    public function election(): BelongsTo
    {
        return $this->belongsTo(Election::class);
    }

    public function pollingUnit(): BelongsTo
    {
        return $this->belongsTo(PollingUnit::class);
    }

    public function result(): BelongsTo
    {
        return $this->belongsTo(ResultSubmission::class, 'result_submission_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
