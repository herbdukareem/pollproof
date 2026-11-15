<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoClip extends Model
{
    public const KINDS = [
        'result' => 'Result announcement',
        'incident' => 'Incident',
        'collation' => 'Collation',
        'other' => 'Something else',
    ];

    /** The size of each piece the phone sends. Small enough for a weak signal and any host's body limit. */
    public const CHUNK_BYTES = 1024 * 1024;

    /** Ten minutes of the app's medium-quality video is well under this. */
    public const MAX_BYTES = 400 * 1024 * 1024;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['captured_at' => 'datetime', 'completed_at' => 'datetime', 'latitude' => 'float', 'longitude' => 'float', 'size' => 'integer', 'received' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pollingUnit(): BelongsTo
    {
        return $this->belongsTo(PollingUnit::class);
    }

    public function ward(): BelongsTo
    {
        return $this->belongsTo(Ward::class);
    }

    public function lga(): BelongsTo
    {
        return $this->belongsTo(Lga::class);
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function election(): BelongsTo
    {
        return $this->belongsTo(Election::class);
    }

    /** The file extension for the clip's type. */
    public function extension(): string
    {
        return match (true) {
            str_contains($this->mime, '3gpp') => '3gp',
            str_contains($this->mime, 'quicktime') => 'mov',
            str_contains($this->mime, 'webm') => 'webm',
            str_contains($this->mime, 'matroska') => 'mkv',
            default => 'mp4',
        };
    }

    public function isComplete(): bool
    {
        return $this->completed_at !== null;
    }

    /** Where the pieces collect while the upload is in progress (always the local disk: they are appended to). */
    public function partPath(): string
    {
        return "video-uploads/{$this->campaign_id}/{$this->id}.part";
    }

    /** What the command room sees of a clip. */
    public function present(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'kind_label' => self::KINDS[$this->kind] ?? $this->kind,
            'note' => $this->note,
            'size' => $this->size,
            'duration_s' => $this->duration_s,
            'captured_at' => $this->captured_at,
            'received_at' => $this->completed_at,
            'complete' => $this->isComplete(),
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'by' => $this->user ? ['id' => $this->user->id, 'name' => $this->user->name, 'code' => $this->user->code, 'role' => $this->user->role->label()] : null,
            'polling_unit' => $this->pollingUnit?->only(['id', 'code', 'name']),
            'ward' => $this->ward?->name,
            'lga' => $this->lga?->name,
            'incident' => $this->incident ? ['id' => $this->incident->id, 'type' => $this->incident->type, 'severity' => $this->incident->severity] : null,
            'election' => $this->election?->only(['id', 'name', 'code', 'type']),
            'url' => $this->isComplete() ? url("/api/v1/videos/{$this->id}/file") : null,
        ];
    }
}
