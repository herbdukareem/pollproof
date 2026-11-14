<?php

namespace App\Services\Voice;

use App\Models\Alert;
use App\Models\Announcement;
use App\Models\Incident;
use App\Services\AlertNotifier;
use Illuminate\Database\UniqueConstraintViolationException;
use Throwable;

/**
 * Decides what the command room says out loud, and makes sure it is said once.
 *
 * - Each problem is announced once: an alert when it is raised (or reopened), again only if
 *   nobody acts on it before the escalation rule fires.
 * - The same sentence is not announced again within REPEAT_MINUTES, whatever caused it.
 * - Floods (a hundred units with no agent at the check-in deadline) are recorded here but
 *   collapsed into "and 97 more" when a screen fetches them; see VoiceController::feed.
 */
final class Announcer
{
    public const REPEAT_MINUTES = 20;

    // Alert types that come with their own announcement elsewhere.
    private const SKIP = ['incident'];

    public function __construct(private readonly Kokoro $kokoro, private readonly AlertNotifier $notifier) {}

    public function incident(Incident $incident): ?Announcement
    {
        if (! $this->kokoro->enabled()) {
            return null;
        }
        $ward = $incident->pollingUnit?->ward;

        return $this->say($incident->campaign_id, 'incident', $incident->type, $incident->severity, "incident:{$incident->id}",
            Speech::incident($incident), $ward?->lga_id, $ward?->id, '/incidents');
    }

    public function alert(Alert $alert, bool $escalation = false): ?Announcement
    {
        if (! $this->kokoro->enabled() || (! $escalation && in_array($alert->type, self::SKIP, true))) {
            return null;
        }
        $text = Speech::alert($alert, $escalation);
        if ($text === null) {
            return null;
        }
        [$lgaId, $wardId] = $this->notifier->locate($alert);
        // A reopened problem is a new announcement; a refresh of an open one is not.
        $opened = ($alert->opened_at ?? $alert->created_at)?->getTimestamp();
        $key = ($escalation ? 'escalation' : 'alert').":{$alert->id}:{$opened}";

        return $this->say($alert->campaign_id, $escalation ? 'escalation' : 'alert', $alert->type, $escalation ? 'high' : $alert->severity, $key,
            $text, $lgaId, $wardId, $this->notifier->link($alert));
    }

    /** Statewide news about the race; see RaceWatch. */
    public function race(int $campaignId, string $topic, string $key, string $text): ?Announcement
    {
        return $this->kokoro->enabled() ? $this->say($campaignId, 'race', $topic, 'medium', "race:{$key}", $text, null, null, '/') : null;
    }

    private function say(int $campaignId, string $kind, string $topic, string $severity, string $key, string $text, ?int $lgaId, ?int $wardId, ?string $link): ?Announcement
    {
        $text = mb_substr(trim($text), 0, 500);
        $hash = sha1($text);
        if (Announcement::where('campaign_id', $campaignId)->where('dedupe_key', $key)->exists()) {
            return null;
        }
        if (Announcement::where('campaign_id', $campaignId)->where('text_hash', $hash)->where('created_at', '>=', now()->subMinutes(self::REPEAT_MINUTES))->exists()) {
            return null;
        }

        try {
            $a = Announcement::create([
                'campaign_id' => $campaignId, 'kind' => $kind, 'topic' => $topic, 'severity' => $severity,
                'dedupe_key' => $key, 'text' => $text, 'text_hash' => $hash,
                'lga_id' => $lgaId, 'ward_id' => $wardId, 'link' => $link,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null; // another request announced it a moment ago
        }

        // Have the audio ready before the first screen asks for it. Kokoro being down never
        // breaks the request that raised the alert; the screen tries again when it plays.
        $kokoro = $this->kokoro;
        dispatch(function () use ($kokoro, $text) {
            try {
                $kokoro->audio($text);
            } catch (Throwable $e) {
                report($e);
            }
        })->afterResponse();

        return $a;
    }
}
