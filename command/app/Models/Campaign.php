<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'election_date' => 'date',
            'lga_share_threshold' => 'float',
            'lga_count_fraction' => 'float',
            'freeze_at' => 'date',
            'require_selfie' => 'boolean',
        ];
    }

    /** After the freeze date the polling-unit list, roster and supervisors can no longer be changed. */
    public function isFrozen(): bool
    {
        return $this->freeze_at !== null && now()->startOfDay()->gte($this->freeze_at);
    }

    public function abortIfFrozen(): void
    {
        if ($this->isFrozen()) {
            abort(423, 'Setup was locked on '.$this->freeze_at->format('j M Y').'. Ask the DG to move the freeze date to make changes.');
        }
    }

    public function lgas(): HasMany
    {
        return $this->hasMany(Lga::class);
    }

    public function wards(): HasMany
    {
        return $this->hasMany(Ward::class);
    }

    public function pollingUnits(): HasMany
    {
        return $this->hasMany(PollingUnit::class);
    }

    public function parties(): HasMany
    {
        return $this->hasMany(Party::class)->orderBy('sort_order');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
