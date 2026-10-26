<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Lga extends Model
{
    protected $guarded = ['id'];

    public function wards(): HasMany
    {
        return $this->hasMany(Ward::class);
    }

    public function pollingUnits(): HasManyThrough
    {
        return $this->hasManyThrough(PollingUnit::class, Ward::class);
    }
}
