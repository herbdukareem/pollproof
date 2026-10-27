<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResultVote extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }
}
