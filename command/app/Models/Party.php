<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Party extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_ours' => 'boolean'];
    }

    public function votes(): HasMany
    {
        return $this->hasMany(ResultVote::class);
    }
}
