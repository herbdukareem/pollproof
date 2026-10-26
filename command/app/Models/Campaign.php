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
        ];
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
