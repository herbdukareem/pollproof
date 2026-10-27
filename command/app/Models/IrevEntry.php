<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IrevEntry extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['votes' => 'array', 'not_on_irev' => 'boolean', 'seen_at' => 'datetime'];
    }

    public function pollingUnit(): BelongsTo
    {
        return $this->belongsTo(PollingUnit::class);
    }
}
