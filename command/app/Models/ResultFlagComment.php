<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResultFlagComment extends Model
{
    protected $guarded = ['id'];

    public function flag(): BelongsTo
    {
        return $this->belongsTo(ResultFlag::class, 'result_flag_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
