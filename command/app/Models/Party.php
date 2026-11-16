<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Party extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        // A new party goes on the ballot of every race not yet declared; take it off a race
        // under Setup, Races.
        static::created(function (Party $party) {
            foreach (Election::where('campaign_id', $party->campaign_id)->whereNull('declared_at')->get() as $race) {
                $race->parties()->attach($party->id, ['sort_order' => (int) $race->parties()->max('election_party.sort_order') + 1]);
            }
        });
    }

    public function elections(): BelongsToMany
    {
        return $this->belongsToMany(Election::class)->withPivot('candidate', 'sort_order');
    }

    protected function casts(): array
    {
        return ['is_ours' => 'boolean'];
    }

    /** Where the command room loads the logo; the version changes with each upload, so it can be cached. */
    protected function logoUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->logo_path ? "/api/v1/parties/{$this->id}/logo?v=".substr(md5($this->logo_path), 0, 8) : null);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(ResultVote::class);
    }
}
