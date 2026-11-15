<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Incident extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['media_paths' => 'array', 'updates' => 'array', 'occurred_at' => 'datetime'];
    }

    public function pollingUnit(): BelongsTo
    {
        return $this->belongsTo(PollingUnit::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Videos the reporter recorded of it, once they have arrived in full. */
    public function videos(): HasMany
    {
        return $this->hasMany(VideoClip::class)->whereNotNull('completed_at')->orderBy('captured_at');
    }
}
