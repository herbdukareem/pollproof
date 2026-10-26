<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheckIn extends Model
{
    public const VERIFIED = 'verified';

    public const PENDING_REVIEW = 'pending_review';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['captured_at' => 'datetime', 'reviewed_at' => 'datetime', 'latitude' => 'float', 'longitude' => 'float'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pollingUnit(): BelongsTo
    {
        return $this->belongsTo(PollingUnit::class);
    }

    public function counts(): bool
    {
        return in_array($this->status, [self::VERIFIED, self::APPROVED], true);
    }
}
