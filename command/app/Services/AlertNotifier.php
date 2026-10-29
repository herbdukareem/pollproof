<?php

namespace App\Services;

use App\Enums\Role;
use App\Jobs\SendSms;
use App\Models\Alert;
use App\Models\CollationEntry;
use App\Models\Incident;
use App\Models\IrevEntry;
use App\Models\Notice;
use App\Models\NotificationRule;
use App\Models\PollingUnit;
use App\Models\ResultSubmission;
use App\Models\User;
use App\Models\Ward;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Tells the right people about an alert, following the campaign's notification rules.
 * Command-room roles hear about everything their rule covers; supervisors and collation agents
 * only about problems in their own LGA or ward.
 */
final class AlertNotifier
{
    /** @return int how many people were told */
    public function notify(Alert $alert, bool $escalation = false): int
    {
        $rule = NotificationRule::where('campaign_id', $alert->campaign_id)->where('event', $alert->type)->first();
        if (! $rule || ! $rule->enabled) {
            return 0;
        }
        $roles = $escalation ? ($rule->escalate_to ?? []) : $rule->recipients;
        $users = $this->recipients($alert, $roles);
        $minutes = $rule->escalate_after_minutes;
        $title = $escalation ? "No action after {$minutes} min: {$alert->title}" : $alert->title;
        $this->deliver($users, $alert->campaign_id, $title, $alert->detail, $alert->severity, $this->url($alert), $rule->channels, $alert->id, $escalation);

        return $users->count();
    }

    /** @param Collection<int, User> $users */
    public function deliver(Collection $users, int $campaignId, string $title, string $body, string $severity, ?string $url, array $channels, ?int $alertId = null, bool $escalation = false, bool $test = false): void
    {
        foreach ($users as $user) {
            if (in_array('app', $channels, true)) {
                Notice::create([
                    'campaign_id' => $campaignId, 'user_id' => $user->id, 'alert_id' => $alertId, 'severity' => $severity,
                    'title' => $title, 'body' => $body, 'url' => $url, 'escalation' => $escalation, 'test' => $test,
                ]);
            }
            if (in_array('sms', $channels, true) && $user->phone) {
                SendSms::dispatch($user->phone, Str::limit('PollProof: '.$title.'. '.$body, 300));
            }
            if (in_array('email', $channels, true) && $user->email) {
                $text = $body.($url ? "\n\nOpen in PollProof: ".url($url) : '');
                Mail::raw($text, fn ($m) => $m->to($user->email)->subject('PollProof: '.$title));
            }
        }
    }

    /** @return Collection<int, User> */
    public function recipients(Alert $alert, array $roles): Collection
    {
        [$lgaId, $wardId] = $this->locate($alert);
        $command = array_values(array_filter($roles, fn ($r) => ! Role::from($r)->isField()));

        return User::where('campaign_id', $alert->campaign_id)->whereNull('deactivated_at')
            ->where(function ($q) use ($roles, $command, $lgaId, $wardId) {
                $q->whereIn('role', $command);
                if (in_array('lga_supervisor', $roles, true) && $lgaId) {
                    $q->orWhere(fn ($w) => $w->where('role', 'lga_supervisor')->where('lga_id', $lgaId));
                }
                if (in_array('ward_supervisor', $roles, true) && $wardId) {
                    $q->orWhere(fn ($w) => $w->where('role', 'ward_supervisor')->where('ward_id', $wardId));
                }
                if (in_array('collation_agent', $roles, true) && ($wardId || $lgaId)) {
                    $q->orWhere(fn ($w) => $w->where('role', 'collation_agent')->where($wardId ? 'ward_id' : 'lga_id', $wardId ?? $lgaId));
                }
            })
            ->get();
    }

    /** @return array{0: ?int, 1: ?int} LGA and ward the alert is about, if any */
    public function locate(Alert $alert): array
    {
        $subject = $alert->subject;
        $unit = match (true) {
            $subject instanceof PollingUnit => $subject,
            $subject instanceof Incident, $subject instanceof IrevEntry, $subject instanceof ResultSubmission => $subject->pollingUnit,
            $subject instanceof User => $subject->pollingUnit,
            default => null,
        };
        if ($unit) {
            $ward = $unit->ward;

            return [$ward->lga_id, $ward->id];
        }
        if ($subject instanceof CollationEntry) {
            return match ($subject->level) {
                CollationEntry::WARD => [Ward::find($subject->scope_id)?->lga_id, $subject->scope_id],
                CollationEntry::LGA => [$subject->scope_id, null],
                default => [null, null],
            };
        }
        if ($subject instanceof User) {
            return [$subject->lga_id, $subject->ward_id];
        }

        return [null, null];
    }

    /** The command-room screen where this alert is dealt with. */
    public function link(Alert $alert): string
    {
        return $this->url($alert);
    }

    private function url(Alert $alert): string
    {
        return match ($alert->type) {
            'collation_mismatch' => '/collation',
            'overvote' => $alert->subject instanceof PollingUnit ? "/units/{$alert->subject_id}/evidence" : '/collation',
            'incident' => '/incidents',
            'agent_absent', 'agent_unavailable' => '/agents',
            'irev_mismatch', 'irev_missing' => '/irev',
            'result_flagged' => $alert->subject instanceof ResultSubmission ? '/units?search='.$alert->subject->pollingUnit?->code : '/verify',
            'verify_backlog' => '/verify',
            'failed_logins' => '/admin/users',
            default => '/overview',
        };
    }
}
