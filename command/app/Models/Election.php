<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * One race the campaign is fighting: the governorship, a Senate district, a state assembly
 * constituency, a council seat, or a re-run of any of them. A race is fought over the whole
 * state or over chosen LGAs and wards, and has its own parties, results, collation and IReV.
 */
class Election extends Model
{
    public const PRESIDENTIAL = 'presidential';

    public const SENATE = 'senate';

    public const REPS = 'reps';

    public const GOVERNORSHIP = 'governorship';

    public const ASSEMBLY = 'assembly';

    public const LGA_CHAIR = 'lga_chair';

    public const COUNCILLOR = 'councillor';

    /**
     * Each kind of race: what it is called, where it is fought, how it is won, and what its
     * final collation is called.
     *
     * area:  state (whole state), lgas (one or more LGAs), lgas_or_wards, one_lga, one_ward
     * rule:  spread (most votes and 25% in two-thirds of LGAs), state_quarter (most votes; a
     *        presidential candidate also needs 25% here), plurality (most votes)
     */
    public const TYPES = [
        self::PRESIDENTIAL => ['label' => 'Presidential', 'area' => 'state', 'rule' => 'state_quarter', 'final' => 'State', 'code' => 'PRES', 'order' => 1],
        self::SENATE => ['label' => 'Senate', 'area' => 'lgas', 'rule' => 'plurality', 'final' => 'Senatorial district', 'code' => 'SEN', 'order' => 2],
        self::REPS => ['label' => 'House of Reps', 'area' => 'lgas', 'rule' => 'plurality', 'final' => 'Federal constituency', 'code' => 'REP', 'order' => 3],
        self::GOVERNORSHIP => ['label' => 'Governorship', 'area' => 'state', 'rule' => 'spread', 'final' => 'State', 'code' => 'GOV', 'order' => 4],
        self::ASSEMBLY => ['label' => 'State Assembly', 'area' => 'lgas_or_wards', 'rule' => 'plurality', 'final' => 'State constituency', 'code' => 'HOA', 'order' => 5],
        self::LGA_CHAIR => ['label' => 'LGA chairman', 'area' => 'one_lga', 'rule' => 'plurality', 'final' => 'LGA', 'code' => 'CHR', 'order' => 6],
        self::COUNCILLOR => ['label' => 'Councillor', 'area' => 'one_ward', 'rule' => 'plurality', 'final' => 'Ward', 'code' => 'CLR', 'order' => 7],
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'election_date' => 'date',
            'is_rerun' => 'boolean',
            'announce' => 'boolean',
            'share_threshold' => 'float',
            'area_fraction' => 'float',
            'declared_at' => 'datetime',
            'declared_votes' => 'array',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function areas(): HasMany
    {
        return $this->hasMany(ElectionArea::class);
    }

    /** The parties on this race's ballot, in ballot order, each with its candidate and their photo. */
    public function parties(): BelongsToMany
    {
        return $this->belongsToMany(Party::class)->withPivot('candidate', 'photo_path', 'sort_order')->orderByPivot('sort_order')->orderBy('parties.sort_order');
    }

    /**
     * Where the command room loads a candidate's photo in this race; null without one. The
     * version changes with each upload, so the photo can be cached.
     */
    public function candidatePhotoUrl(Party $party): ?string
    {
        $path = $party->pivot?->photo_path;

        return $path ? "/api/v1/elections/{$this->id}/parties/{$party->id}/photo?v=".substr(md5($path), 0, 8) : null;
    }

    public function results(): HasMany
    {
        return $this->hasMany(ResultSubmission::class);
    }

    public function label(): string
    {
        return self::TYPES[$this->type]['label'] ?? ucfirst($this->type);
    }

    public function rule(): string
    {
        return self::TYPES[$this->type]['rule'] ?? 'plurality';
    }

    /** What the race's final collation is called: "State", "Senatorial district", … */
    public function finalLevel(): string
    {
        return self::TYPES[$this->type]['final'] ?? 'Final';
    }

    public function isStatewide(): bool
    {
        return (self::TYPES[$this->type]['area'] ?? 'state') === 'state' || ! $this->areas()->exists();
    }

    /** Polling units in this race's area. */
    public function units(): Builder
    {
        return self::unitsIn($this->campaign_id, $this->areaRows());
    }

    /**
     * @param  Collection<int, object{lga_id: int, ward_id: ?int}>  $areas
     */
    public static function unitsIn(int $campaignId, Collection $areas): Builder
    {
        $q = PollingUnit::query()->where('polling_units.campaign_id', $campaignId);
        if ($areas->isEmpty()) {
            return $q;
        }
        $lgas = $areas->whereNull('ward_id')->pluck('lga_id')->all();
        $wards = $areas->whereNotNull('ward_id')->pluck('ward_id')->all();

        return $q->where(fn ($w) => $w->whereIn('polling_units.ward_id', $wards ?: [0])
            ->orWhereIn('polling_units.ward_id', Ward::whereIn('lga_id', $lgas ?: [0])->select('id')));
    }

    public function coversUnit(PollingUnit $unit): bool
    {
        $areas = $this->areaRows();
        if ($areas->isEmpty()) {
            return $unit->campaign_id === $this->campaign_id;
        }
        $unit->loadMissing('ward');

        return $areas->contains(fn ($a) => $a->ward_id === $unit->ward_id || ($a->ward_id === null && $a->lga_id === $unit->ward->lga_id));
    }

    /** The LGAs this race touches, in code order. */
    public function lgaIds(): array
    {
        $areas = $this->areaRows();

        return $areas->isEmpty()
            ? Lga::where('campaign_id', $this->campaign_id)->orderBy('code')->pluck('id')->all()
            : Lga::whereIn('id', $areas->pluck('lga_id')->unique())->orderBy('code')->pluck('id')->all();
    }

    /** "Whole state", "Bida and Gbako", "4 wards in Bida", "Bida, Gbako and 3 more". */
    public function areaSummary(): string
    {
        $areas = $this->areaRows();
        if ($areas->isEmpty()) {
            return 'Whole state';
        }
        $wards = $areas->whereNotNull('ward_id');
        if ($wards->isNotEmpty() && $areas->whereNull('ward_id')->isEmpty() && $areas->pluck('lga_id')->unique()->count() === 1) {
            $lga = Lga::find($areas->first()->lga_id)?->name;
            if ($wards->count() === 1) {
                return Ward::find($wards->first()->ward_id)?->name.' ward, '.$lga;
            }

            return "{$wards->count()} wards in {$lga}";
        }
        $names = Lga::whereIn('id', $areas->pluck('lga_id')->unique())->orderBy('code')->pluck('name');

        return match (true) {
            $names->count() === 1 => $names[0],
            $names->count() <= 3 => $names->slice(0, -1)->implode(', ').' and '.$names->last(),
            default => $names->take(2)->implode(', ').' and '.($names->count() - 2).' more',
        };
    }

    /** Election-day records for this race are read-only once its result is declared. */
    public function isDeclared(): bool
    {
        return $this->declared_at !== null;
    }

    public function abortIfDeclared(): void
    {
        if ($this->isDeclared()) {
            abort(423, "{$this->name} records are read-only since its declaration on ".$this->declared_at->timezone(config('pollproof.timezone'))->format('j M Y').'.');
        }
    }

    /** @return Collection<int, object{lga_id: int, ward_id: ?int}> */
    private function areaRows(): Collection
    {
        return $this->relationLoaded('areas')
            ? $this->areas->map(fn ($a) => (object) ['lga_id' => $a->lga_id, 'ward_id' => $a->ward_id])
            : $this->areas()->get(['lga_id', 'ward_id'])->map(fn ($a) => (object) ['lga_id' => $a->lga_id, 'ward_id' => $a->ward_id]);
    }
}
