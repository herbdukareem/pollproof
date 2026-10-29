<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PollingUnit extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['latitude' => 'float', 'longitude' => 'float'];
    }

    public function resultFlags(): HasMany
    {
        return $this->hasMany(ResultFlag::class);
    }

    public function ward(): BelongsTo
    {
        return $this->belongsTo(Ward::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(ResultSubmission::class);
    }

    public function currentResult(): HasOne
    {
        return $this->hasOne(ResultSubmission::class)->where('is_current', true);
    }

    public function agent(): HasOne
    {
        return $this->hasOne(User::class)->where('role', 'agent');
    }
}
