<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Party;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Campaign settings and the party list. DG only. */
class CampaignController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function show(Request $request): JsonResponse
    {
        $campaign = $request->user()->campaign;

        return response()->json([
            'campaign' => $campaign->only(['id', 'name', 'state_name', 'election_date', 'freeze_at', 'geofence_radius_m', 'max_gps_accuracy_m', 'require_selfie', 'require_two_factor', 'checkin_deadline', 'lga_share_threshold', 'lga_count_fraction']),
            'frozen' => $campaign->isFrozen(),
            'parties' => $campaign->parties()->withCount('votes')->get()->map(fn ($p) => [
                'id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'is_ours' => $p->is_ours, 'sort_order' => $p->sort_order, 'has_votes' => $p->votes_count > 0,
            ]),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $campaign = $request->user()->campaign;
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'state_name' => ['sometimes', 'string', 'max:60'],
            'election_date' => ['sometimes', 'date'],
            'freeze_at' => ['sometimes', 'nullable', 'date'],
            'geofence_radius_m' => ['sometimes', 'integer', 'between:25,1000'],
            'max_gps_accuracy_m' => ['sometimes', 'integer', 'between:5,200'],
            'require_selfie' => ['sometimes', 'boolean'],
            'require_two_factor' => ['sometimes', 'boolean'],
            'checkin_deadline' => ['sometimes', 'date_format:H:i'],
        ]);
        $before = $campaign->only(array_keys($data));
        $campaign->update($data);
        $this->audit->log($request->user(), 'campaign.updated', $campaign, ['before' => $before, 'after' => $campaign->only(array_keys($data))], $request->ip());

        return $this->show($request);
    }

    public function storeParty(Request $request): JsonResponse
    {
        $campaign = $request->user()->campaign;
        $campaign->abortIfFrozen();
        $data = $this->validateParty($request, $campaign->id);
        if ($data['is_ours'] ?? false) {
            $campaign->parties()->update(['is_ours' => false]);
        }
        $party = Party::create($data + ['campaign_id' => $campaign->id, 'sort_order' => ($campaign->parties()->max('sort_order') ?? -1) + 1]);
        $this->audit->log($request->user(), 'party.created', $party, $data, $request->ip());

        return $this->show($request)->setStatusCode(201);
    }

    public function updateParty(Request $request, Party $party): JsonResponse
    {
        $campaign = $request->user()->campaign;
        abort_unless($party->campaign_id === $campaign->id, 404);
        $campaign->abortIfFrozen();
        $data = $this->validateParty($request, $campaign->id, $party);
        abort_if(isset($data['code']) && $data['code'] !== $party->code && $party->votes()->exists(), 422, 'This party already has votes recorded; its code cannot change.');
        if ($data['is_ours'] ?? false) {
            $campaign->parties()->where('id', '!=', $party->id)->update(['is_ours' => false]);
        }
        $party->update($data);
        $this->audit->log($request->user(), 'party.updated', $party, $data, $request->ip());

        return $this->show($request);
    }

    public function destroyParty(Request $request, Party $party): JsonResponse
    {
        $campaign = $request->user()->campaign;
        abort_unless($party->campaign_id === $campaign->id, 404);
        $campaign->abortIfFrozen();
        abort_if($party->votes()->exists(), 422, 'This party already has votes recorded and cannot be removed.');
        $this->audit->log($request->user(), 'party.deleted', $party, $party->only(['code', 'name']), $request->ip());
        $party->delete();

        return $this->show($request);
    }

    private function validateParty(Request $request, int $campaignId, ?Party $party = null): array
    {
        if (is_string($request->input('code'))) {
            $request->merge(['code' => strtoupper(trim($request->input('code')))]);
        }
        $data = $request->validate([
            'code' => [$party ? 'sometimes' : 'required', 'string', 'max:10', 'regex:/^[A-Za-z]+$/', Rule::notIn(['AC', 'X']),
                Rule::unique('parties', 'code')->where('campaign_id', $campaignId)->ignore($party?->id)],
            'name' => [$party ? 'sometimes' : 'required', 'string', 'max:80'],
            'is_ours' => ['sometimes', 'boolean'],
        ], ['code.regex' => 'Use letters only, as on the ballot (e.g. APC, PDP).', 'code.not_in' => 'AC and X are used by the SMS format.']);

        return $data;
    }
}
