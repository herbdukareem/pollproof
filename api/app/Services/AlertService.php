<?php

namespace App\Services;

use App\Models\Alert;
use Illuminate\Database\Eloquent\Model;

final class AlertService
{
    /**
     * Raise or refresh an alert. The dedupe key keeps one live alert per problem,
     * so re-entering the same sheet updates the alert instead of creating noise.
     */
    public function raise(int $campaignId, string $type, string $severity, string $dedupeKey, string $title, string $detail, ?Model $subject = null, array $data = []): Alert
    {
        $alert = Alert::firstOrNew(['campaign_id' => $campaignId, 'dedupe_key' => $dedupeKey]);
        // Someone already dealt with exactly this problem: don't reopen it on a routine re-check.
        if ($alert->exists && $alert->status === 'resolved' && self::normalise($alert->data ?? []) === self::normalise($data)) {
            return $alert;
        }
        $alert->fill([
            'type' => $type,
            'severity' => $severity,
            'title' => $title,
            'detail' => $detail,
            'data' => $data,
            'status' => 'open',
            'resolved_by' => null,
            'resolved_at' => null,
        ]);
        if ($subject) {
            $alert->subject()->associate($subject);
        }
        $alert->save();

        return $alert;
    }

    private static function normalise(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($v) => is_array($v) ? self::normalise($v) : $v, $value);
    }

    /** Close an alert automatically when the underlying problem is gone. */
    public function clear(int $campaignId, string $dedupeKey): void
    {
        Alert::where('campaign_id', $campaignId)
            ->where('dedupe_key', $dedupeKey)
            ->where('status', '!=', 'resolved')
            ->update(['status' => 'resolved', 'resolved_at' => now()]);
    }
}
