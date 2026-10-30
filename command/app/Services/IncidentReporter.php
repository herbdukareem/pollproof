<?php

namespace App\Services;

use App\Models\Incident;
use App\Models\User;

/** Records an incident from an agent (app or USSD) and raises an alert for a high-severity one. */
final class IncidentReporter
{
    public function __construct(private readonly AlertService $alerts) {}

    public function report(User $user, string $type, string $severity, string $description, string $occurredAt, ?float $latitude = null, ?float $longitude = null, array $mediaPaths = [], ?string $clientRef = null): Incident
    {
        $incident = Incident::create([
            'campaign_id' => $user->campaign_id,
            'user_id' => $user->id,
            'polling_unit_id' => $user->polling_unit_id,
            'type' => $type,
            'severity' => $severity,
            'description' => $description,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'media_paths' => $mediaPaths,
            'occurred_at' => $occurredAt,
            'updates' => [],
            'client_ref' => $clientRef,
        ]);
        if ($incident->severity === 'high') {
            $where = $user->pollingUnit?->code ?? 'Unassigned agent';
            $this->alerts->raise($user->campaign_id, 'incident', 'high', "incident:{$incident->id}",
                "{$where}: ".str_replace('_', ' ', $incident->type), $incident->description, $incident);
        }

        return $incident;
    }
}
