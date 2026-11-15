<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TribunalCase extends Model
{
    protected $guarded = ['id'];

    /** The race the petition is about, if it names one. */
    public function election(): BelongsTo
    {
        return $this->belongsTo(Election::class);
    }

    protected function casts(): array
    {
        return ['milestones' => 'array'];
    }

    public function grounds(): HasMany
    {
        return $this->hasMany(TribunalGround::class)->orderBy('sort_order')->orderBy('id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(TribunalTask::class)->orderByRaw('done_at IS NOT NULL')->orderBy('due_on')->orderBy('id');
    }
}
