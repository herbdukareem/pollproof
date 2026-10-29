<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResultFlag extends Model
{
    public const OPEN = 'open';

    public const CLEARED = 'cleared';

    /** reason => [label, alert severity] */
    public const REASONS = [
        'figures_differ' => ['Figures differ from the photo', 'high'],
        'tampering' => ['Signs of alteration or tampering', 'high'],
        'photo_unclear' => ['Photo unclear or incomplete', 'medium'],
        'wrong_form' => ['Wrong unit or wrong form', 'medium'],
        'irregular_process' => ['Irregular process at the unit', 'high'],
        'other' => ['Other concern', 'medium'],
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['cleared_at' => 'datetime'];
    }

    public function result(): BelongsTo
    {
        return $this->belongsTo(ResultSubmission::class, 'result_submission_id');
    }

    public function pollingUnit(): BelongsTo
    {
        return $this->belongsTo(PollingUnit::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function clearer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cleared_by');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(ResultFlagComment::class)->orderBy('id');
    }

    public function label(): string
    {
        return self::REASONS[$this->reason][0] ?? 'Flag';
    }

    public function present(): array
    {
        $this->loadMissing(['user:id,name,code,role', 'clearer:id,name', 'comments.user:id,name,code,role']);
        $person = fn (?User $u) => $u ? ['id' => $u->id, 'name' => $u->name, 'role' => $u->role->label()] : null;

        return [
            'id' => $this->id,
            'result_id' => $this->result_submission_id,
            'reason' => $this->reason,
            'label' => $this->label(),
            'severity' => self::REASONS[$this->reason][1] ?? 'medium',
            'note' => $this->note,
            'status' => $this->status,
            'by' => $person($this->user),
            'at' => $this->created_at,
            'cleared_by' => $this->clearer?->name,
            'cleared_at' => $this->cleared_at,
            'clear_note' => $this->clear_note,
            'comments' => $this->comments->map(fn (ResultFlagComment $c) => ['id' => $c->id, 'body' => $c->body, 'by' => $person($c->user), 'at' => $c->created_at])->values(),
        ];
    }
}
