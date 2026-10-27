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
            'require_two_factor' => 'boolean',
            'declared_at' => 'datetime',
            'declared_votes' => 'array',
            'retention_set_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::created(fn (Campaign $campaign) => NotificationRule::seedDefaults($campaign));
    }

    public function notificationRules(): HasMany
    {
        return $this->hasMany(NotificationRule::class);
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

    /** Election-day records (results, collation, IReV, check-ins) are read-only once the result is declared. */
    public function isDeclared(): bool
    {
        return $this->declared_at !== null;
    }

    public function abortIfDeclared(): void
    {
        if ($this->isDeclared()) {
            abort(423, 'Election-day records are read-only since the declaration on '.$this->declared_at->timezone(config('pollproof.timezone'))->format('j M Y').'.');
        }
    }

    public function tribunalCases(): HasMany
    {
        return $this->hasMany(TribunalCase::class);
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
