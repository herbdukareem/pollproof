<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationRule extends Model
{
    protected $guarded = ['id'];

    /** Alert type => [plain description, who is told, channels, escalate after minutes, escalate to]. */
    public const DEFAULTS = [
        'collation_mismatch' => ['Collation sheet differs from our units', ['legal', 'lga_supervisor', 'collation_agent'], ['app', 'sms'], 5, ['dg']],
        'overvote' => ['Votes cast exceed accreditation', ['legal', 'verifier'], ['app', 'sms'], 5, ['dg']],
        'incident' => ['High-severity incident', ['lga_supervisor', 'situation_room'], ['app', 'sms'], 10, ['dg']],
        'agent_absent' => ['Agent not checked in by the deadline', ['ward_supervisor'], ['app', 'sms'], 10, ['lga_supervisor']],
        'agent_unavailable' => ["Agent says they can't make election day", ['ward_supervisor', 'situation_room'], ['app', 'sms'], 30, ['lga_supervisor']],
        'irev_mismatch' => ['IReV differs from our signed result', ['legal', 'data_officer'], ['app'], 15, ['dg']],
        'irev_missing' => ['Result not on IReV', ['data_officer'], ['app'], 60, ['situation_room']],
        'result_flagged' => ['A reviewer flagged a result', ['legal', 'situation_room'], ['app'], 15, ['dg']],
        'verify_backlog' => ['More than 20 results waiting to be verified', ['verifier'], ['app'], 15, ['situation_room']],
        'failed_logins' => ['Repeated failed sign-ins', ['dg'], ['app', 'sms', 'email'], null, []],
    ];

    protected function casts(): array
    {
        return ['recipients' => 'array', 'channels' => 'array', 'escalate_to' => 'array', 'enabled' => 'boolean'];
    }

    public function label(): string
    {
        return self::DEFAULTS[$this->event][0] ?? ucfirst(str_replace('_', ' ', $this->event));
    }

    public static function seedDefaults(Campaign $campaign): void
    {
        foreach (self::DEFAULTS as $event => [, $recipients, $channels, $after, $to]) {
            self::firstOrCreate(['campaign_id' => $campaign->id, 'event' => $event], [
                'recipients' => $recipients, 'channels' => $channels, 'escalate_after_minutes' => $after, 'escalate_to' => $to,
            ]);
        }
    }
}
