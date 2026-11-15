<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IrevEntry;
use App\Models\PollingUnit;
use App\Services\AuditLogger;
use App\Services\Races;
use App\Services\Reconciler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IrevEntryController extends Controller
{
    /** A data officer records what IReV shows for a unit in one race (or that it is missing). */
    public function store(Request $request, Reconciler $reconciler, AuditLogger $audit): JsonResponse
    {
        $user = $request->user();
        $race = Races::fromRequest($request);
        $race->abortIfDeclared();
        $data = $request->validate([
            'polling_unit_id' => ['required', 'integer'],
            'not_on_irev' => ['required', 'boolean'],
            'votes' => ['required_if:not_on_irev,false', 'nullable', 'array'],
            'votes.*' => ['integer', 'min:0'],
            'accredited' => ['nullable', 'integer', 'min:0'],
            'rejected' => ['nullable', 'integer', 'min:0'],
            'seen_at' => ['required', 'date'],
            'screenshot' => ['nullable', 'image', 'max:10240'],
        ]);
        $unit = PollingUnit::where('campaign_id', $user->campaign_id)->findOrFail($data['polling_unit_id']);
        abort_unless($race->coversUnit($unit), 422, "{$unit->code} is not in the {$race->name} race.");
        $votes = isset($data['votes']) ? array_change_key_case($data['votes'], CASE_UPPER) : null;
        $unknown = array_diff(array_keys($votes ?? []), $race->parties()->pluck('code')->all());
        abort_if($unknown, 422, "Not on the {$race->name} ballot: ".implode(', ', $unknown));

        $entry = IrevEntry::create([
            'campaign_id' => $user->campaign_id,
            'election_id' => $race->id,
            'polling_unit_id' => $unit->id,
            'entered_by' => $user->id,
            'not_on_irev' => (bool) $data['not_on_irev'],
            'votes' => $votes !== null ? array_map('intval', $votes) : null,
            'accredited' => $data['accredited'] ?? null,
            'rejected' => $data['rejected'] ?? null,
            'screenshot_path' => $request->file('screenshot')?->store("campaigns/{$user->campaign_id}/irev", config('pollproof.evidence_disk')),
            'seen_at' => $data['seen_at'],
        ]);
        $comparison = $reconciler->checkIrev($entry);
        $audit->log($user, 'irev.entered', $entry, ['status' => $comparison['status']], $request->ip());

        return response()->json(['id' => $entry->id, 'comparison' => $comparison], 201);
    }
}
