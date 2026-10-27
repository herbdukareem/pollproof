<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Append-only, hash-chained audit log. Each entry's hash covers its own content and the
 * previous entry's hash for the same campaign, so editing or deleting any row breaks the chain.
 */
final class AuditLogger
{
    public function log(?User $user, string $action, ?Model $subject = null, array $meta = [], ?string $ip = null, ?int $campaignId = null): AuditLog
    {
        $campaignId ??= $user?->campaign_id;

        return DB::transaction(function () use ($user, $action, $subject, $meta, $ip, $campaignId) {
            // Serialise writers per campaign so the chain never forks: lock the campaign's row until commit.
            // Entries without a campaign (unknown-email sign-in attempts) lock the newest entry of that chain instead.
            if ($campaignId !== null) {
                Campaign::whereKey($campaignId)->lockForUpdate()->value('id');
            } else {
                AuditLog::whereNull('campaign_id')->orderByDesc('id')->lockForUpdate()->value('id');
            }

            $prev = AuditLog::where('campaign_id', $campaignId)->orderByDesc('id')->value('hash');
            $entry = new AuditLog([
                'campaign_id' => $campaignId,
                'user_id' => $user?->id,
                'action' => $action,
                'meta' => $meta,
                'ip' => $ip,
                'prev_hash' => $prev,
                'created_at' => now()->utc()->startOfSecond(),
            ]);
            if ($subject) {
                $entry->subject_type = $subject->getMorphClass();
                $entry->subject_id = $subject->getKey();
            }
            $entry->hash = self::hashFor($entry);
            $entry->save();

            return $entry;
        });
    }

    public static function hashFor(AuditLog $entry): string
    {
        return hash('sha256', json_encode([
            'prev' => $entry->prev_hash,
            'campaign' => $entry->campaign_id,
            'user' => $entry->user_id,
            'action' => $entry->action,
            'subject' => [$entry->subject_type, $entry->subject_id],
            'meta' => self::sortKeys($entry->meta ?? []),
            'ip' => $entry->ip,
            'at' => $entry->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** MySQL's JSON type does not keep key order, so hash a key-sorted copy. */
    private static function sortKeys(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($v) => is_array($v) ? self::sortKeys($v) : $v, $value);
    }

    /** @return array{valid: bool, checked: int, broken_at: int|null} */
    public function verify(int $campaignId): array
    {
        $prev = null;
        $checked = 0;
        foreach (AuditLog::where('campaign_id', $campaignId)->orderBy('id')->cursor() as $entry) {
            $checked++;
            if ($entry->prev_hash !== $prev || $entry->hash !== self::hashFor($entry)) {
                return ['valid' => false, 'checked' => $checked, 'broken_at' => $entry->id];
            }
            $prev = $entry->hash;
        }

        return ['valid' => true, 'checked' => $checked, 'broken_at' => null];
    }
}
