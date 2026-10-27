<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResultSubmission extends Model
{
    public const PENDING_REVIEW = 'pending_review';

    public const APPROVED = 'approved';

    public const SENT_BACK = 'sent_back';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'flags' => 'array',
            'signature_valid' => 'boolean',
            'is_current' => 'boolean',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    public function votes(): HasMany
    {
        return $this->hasMany(ResultVote::class);
    }

    public function pollingUnit(): BelongsTo
    {
        return $this->belongsTo(PollingUnit::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    /** @return array<string,int> votes keyed by party code */
    public function votesByParty(): array
    {
        return $this->votes->load('party')->mapWithKeys(fn ($v) => [$v->party->code => $v->votes])->all();
    }

    /** Short fingerprint agents send by SMS and read back for confirmation. */
    public function fingerprint(): string
    {
        return strtoupper(substr($this->payload_hash, 0, 8));
    }
}
