<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Jobs\SendSms;
use App\Models\PollingUnit;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\RosterImporter;
use App\Services\SupervisorAssigner;
use App\Services\SupervisorImporter;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Loading and preparing the field team before election day. */
class RosterController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function importAgents(Request $request, RosterImporter $importer): JsonResponse
    {
        $campaign = $request->user()->campaign;
        $campaign->abortIfFrozen();
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:10240']]);
        $summary = $importer->import($campaign, $request->file('file')->getRealPath());
        $this->audit->log($request->user(), 'roster.imported', null, collect($summary)->except('rejected')->all() + ['rejected' => count($summary['rejected']), 'file' => $request->file('file')->getClientOriginalName()], $request->ip());

        return response()->json($summary);
    }

    /** Text agents the app link. Without ids, invites every assigned agent who hasn't installed the app yet. */
    public function invite(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate(['ids' => ['nullable', 'array', 'max:5000'], 'ids.*' => ['integer']]);
        $agents = User::where('campaign_id', $user->campaign_id)->where('role', Role::Agent)->whereNull('deactivated_at')
            ->when($data['ids'] ?? null, fn ($q, $ids) => $q->whereIn('id', $ids), fn ($q) => $q->whereNotNull('polling_unit_id')->whereDoesntHave('devices'))
            ->with('pollingUnit')->get();

        $url = config('pollproof.app_download_url');
        foreach ($agents as $agent) {
            $where = $agent->pollingUnit ? "the agent for {$agent->pollingUnit->code} ({$agent->pollingUnit->name})" : 'a reserve agent';
            SendSms::dispatch($agent->phone, "PollProof: you are {$where}. Install the app from {$url} and sign in with this phone number. Your agent ID is {$agent->code}.");
        }
        User::whereIn('id', $agents->pluck('id'))->update(['invited_at' => now()]);
        $this->audit->log($user, 'roster.invited', null, ['agents' => $agents->count()], $request->ip());

        return response()->json(['sent' => $agents->count()]);
    }

    /**
     * Match agents who registered in a ward but have no unit to that ward's empty units.
     * Reserves are kept back. With apply=false nothing is saved.
     */
    public function autoAssign(Request $request): JsonResponse
    {
        $campaign = $request->user()->campaign;
        $data = $request->validate(['apply' => ['required', 'boolean']]);
        if ($data['apply']) {
            $campaign->abortIfFrozen();
        }

        $waiting = User::where('campaign_id', $campaign->id)->where('role', Role::Agent)->whereNull('deactivated_at')
            ->whereNull('polling_unit_id')->where('is_reserve', false)->whereNotNull('ward_id')->orderBy('code')->get()->groupBy('ward_id');
        $taken = User::where('campaign_id', $campaign->id)->where('role', Role::Agent)->whereNotNull('polling_unit_id')->pluck('polling_unit_id');
        $open = PollingUnit::where('campaign_id', $campaign->id)->whereIn('ward_id', $waiting->keys())->whereNotIn('id', $taken)
            ->with('ward.lga')->orderBy('code')->get()->groupBy('ward_id');

        $pairs = [];
        foreach ($waiting as $wardId => $agents) {
            foreach ($agents->zip($open->get($wardId, collect())) as [$agent, $unit]) {
                if ($agent && $unit) {
                    $pairs[] = [$agent, $unit];
                }
            }
        }

        if ($data['apply']) {
            DB::transaction(function () use ($pairs) {
                foreach ($pairs as [$agent, $unit]) {
                    $agent->update(['polling_unit_id' => $unit->id, 'lga_id' => $unit->ward->lga_id]);
                }
            });
            $this->audit->log($request->user(), 'roster.auto_assigned', null, ['agents' => count($pairs)], $request->ip());
        }

        return response()->json([
            'applied' => $data['apply'],
            'count' => count($pairs),
            'still_unassigned' => $waiting->flatten()->count() - count($pairs),
            'pairs' => collect($pairs)->take(200)->map(fn ($p) => ['agent' => $p[0]->code, 'name' => $p[0]->name, 'unit' => $p[1]->code, 'place' => "{$p[1]->ward->name} · {$p[1]->ward->lga->name}"]),
        ]);
    }

    public function importSupervisors(Request $request, SupervisorImporter $importer): JsonResponse
    {
        $campaign = $request->user()->campaign;
        $campaign->abortIfFrozen();
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);
        $summary = $importer->import($campaign, $request->file('file')->getRealPath());
        $this->audit->log($request->user(), 'supervisors.imported', null, collect($summary)->except('rejected')->all() + ['rejected' => count($summary['rejected'])], $request->ip());

        return response()->json($summary);
    }

    /** Fill one supervisor post, e.g. a ward that has nobody. */
    public function storeSupervisor(Request $request, SupervisorAssigner $assigner): JsonResponse
    {
        $campaign = $request->user()->campaign;
        $campaign->abortIfFrozen();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:20'],
            'level' => ['required', 'in:lga,ward'],
            'lga_id' => ['required_if:level,lga', 'nullable', 'integer'],
            'ward_id' => ['required_if:level,ward', 'nullable', 'integer'],
            'is_backup' => ['sometimes', 'boolean'],
        ]);
        $phone = Phone::normalize($data['phone']);
        abort_unless($phone, 422, 'Not a valid Nigerian mobile number.');
        $ward = $data['level'] === 'ward' ? $campaign->wards()->with('lga')->findOrFail($data['ward_id']) : null;
        $lga = $ward?->lga ?? $campaign->lgas()->findOrFail($data['lga_id']);

        $result = $assigner->assign($campaign, $data['name'], $phone, $data['level'], $lga, $ward, (bool) ($data['is_backup'] ?? false));
        if (is_string($result)) {
            abort(422, $result);
        }
        $this->audit->log($request->user(), 'supervisor.assigned', $result['user'], ['level' => $data['level'], 'lga' => $lga->code, 'ward' => $ward?->code, 'replaced_id' => $result['replaced']?->id], $request->ip());

        return response()->json(['id' => $result['user']->id, 'code' => $result['user']->code, 'replaced' => $result['replaced']?->code], 201);
    }
}
