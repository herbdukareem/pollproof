<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TribunalCase extends Model
{
    protected $guarded = ['id'];

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
