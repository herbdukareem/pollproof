<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class CheckIn extends Model
{
    public const VERIFIED = 'verified';

    public const PENDING_REVIEW = 'pending_review';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    /** A later check-in the same day took this one's place (the agent checked in again before review). */
    public const REPLACED = 'replaced';

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

    /**
     * Check-ins captured on the same local calendar day as $moment (campaign time zone).
     * Agents check in every day they work, so "checked in" always means checked in that day.
     */
    public function scopeOnDay(Builder $query, CarbonInterface|string $moment): Builder
    {
        $local = Carbon::make($moment)->setTimezone(config('pollproof.timezone'));

        return $query->whereBetween('captured_at', [$local->copy()->startOfDay()->utc(), $local->copy()->endOfDay()->utc()]);
    }

    public function scopeToday(Builder $query): Builder
    {
        return $query->onDay(now());
    }
}
