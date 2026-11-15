<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CollationEntry extends Model
{
    public const WARD = 'ward';

    public const LGA = 'lga';

    public const STATE = 'state';

    protected $guarded = ['id'];

    public function election(): BelongsTo
    {
        return $this->belongsTo(Election::class);
    }

    protected function casts(): array
    {
        return ['votes' => 'array', 'announced_at' => 'datetime'];
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }
}
