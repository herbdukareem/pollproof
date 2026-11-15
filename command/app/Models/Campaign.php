<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

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
        static::created(function (Campaign $campaign) {
            NotificationRule::seedDefaults($campaign);
            // Every campaign starts with the governorship; more races are added under Setup, Races.
            $campaign->elections()->create([
                'type' => Election::GOVERNORSHIP,
                'name' => 'Governorship',
                'code' => 'GOV',
                'election_date' => $campaign->election_date,
                'share_threshold' => $campaign->lga_share_threshold ?? 0.25,
                'area_fraction' => $campaign->lga_count_fraction ?? 2 / 3,
            ]);
        });
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

    /**
     * Once every race is declared, the campaign's field records (check-ins, incidents, agents)
     * are read-only too. Each race's own results lock at its own declaration; see Election.
     */
    public function isDeclared(): bool
    {
        return $this->lastDeclaredAt() !== null;
    }

    /** When the last race was declared, or null while any race is still open. */
    public function lastDeclaredAt(): ?Carbon
    {
        $races = $this->elections()->reorder()->get(['declared_at']);
        if ($races->isEmpty() || $races->contains(fn ($e) => $e->declared_at === null)) {
            return null;
        }

        return $races->max('declared_at');
    }

    public function abortIfDeclared(): void
    {
        if ($at = $this->lastDeclaredAt()) {
            abort(423, 'Election-day records are read-only since the last declaration on '.$at->timezone(config('pollproof.timezone'))->format('j M Y').'.');
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

    /** The races this campaign is fighting, by date and then by the order they appear on the ballot. */
    public function elections(): HasMany
    {
        return $this->hasMany(Election::class)->orderBy('election_date')->orderBy('sort_order')->orderBy('id');
    }

    /**
     * The race to use when none is named: older agent apps, SMS without a race code, and pages
     * opened without one. The first race of the next (or latest) election day.
     */
    public function primaryElection(): ?Election
    {
        return $this->electionsOn($this->currentElectionDate())->first() ?? $this->elections()->first();
    }

    /**
     * The election day agents are working towards: the first race date from two days ago
     * onwards (so results still come in the day after), or the last one if all have passed.
     */
    public function currentElectionDate(): ?string
    {
        $from = now(config('pollproof.timezone'))->subDays(2)->toDateString();

        return $this->elections()->reorder()->where('election_date', '>=', $from)->min('election_date')
            ?? $this->elections()->reorder()->max('election_date');
    }

    /** @return Collection<int, Election> */
    public function electionsOn(?string $date)
    {
        return $date ? $this->elections()->whereDate('election_date', $date)->get() : collect();
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
