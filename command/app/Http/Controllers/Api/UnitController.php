<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\CheckIn;
use App\Models\IrevEntry;
use App\Models\PollingUnit;
use App\Models\ResultFlag;
use App\Models\ResultSubmission;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\EvidenceBuilder;
use App\Services\Reconciler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UnitController extends Controller
{
    /** Units with their reporting status, most urgent first. */
    public function index(Request $request, Reconciler $reconciler): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'lga_id' => ['nullable', 'integer'],
            'ward_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:reported,awaiting,no_agent,flagged,review_flagged'],
            'search' => ['nullable', 'string', 'max:50'],
            'agent' => ['nullable', 'in:present,absent,none'],
            'result' => ['nullable', 'in:verified,pending,sent_back,none'],
            'irev' => ['nullable', 'in:match,differs,missing,unchecked'],
            'channel' => ['nullable', 'in:app,sms,ussd'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:200'],
            'sort' => ['nullable', 'in:result,code,agent,flags'],
            'dir' => ['nullable', 'in:asc,desc'],
        ]);
        $sort = $data['sort'] ?? 'result';
        $dir = $data['dir'] ?? (in_array($sort, ['result', 'flags'], true) ? 'desc' : 'asc');

        // Counts for the status chips, within the chosen LGA or ward but across every status.
        $scope = fn () => PollingUnit::where('polling_units.campaign_id', $user->campaign_id)
            ->when($data['ward_id'] ?? null, fn ($q, $w) => $q->where('ward_id', $w))
            ->when($data['lga_id'] ?? null, fn ($q, $l) => $q->whereHas('ward', fn ($w) => $w->where('lga_id', $l)));
        $counts = [
            'all' => $scope()->count(),
            'reported' => $scope()->whereHas('currentResult')->count(),
            'awaiting' => $scope()->whereDoesntHave('currentResult')->count(),
            'no_agent' => $scope()->whereDoesntHave('agent')->count(),
            'flagged' => $scope()->whereHas('currentResult', fn ($r) => $r->whereJsonContains('flags', 'overvote'))->count(),
            'review_flagged' => $scope()->whereHas('resultFlags', fn ($f) => $f->where('status', ResultFlag::OPEN))->count(),
        ];

        $units = PollingUnit::where('polling_units.campaign_id', $user->campaign_id)
            ->with(['ward.lga', 'currentResult.votes.party', 'agent:id,name,code,polling_unit_id'])
            ->when($data['ward_id'] ?? null, fn ($q, $w) => $q->where('ward_id', $w))
            ->when($data['lga_id'] ?? null, fn ($q, $l) => $q->whereHas('ward', fn ($w) => $w->where('lga_id', $l)))
            ->when($data['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->whereLike('code', "%{$s}%")->orWhereLike('name', "%{$s}%")))
            ->when(($data['status'] ?? null) === 'reported', fn ($q) => $q->whereHas('currentResult'))
            ->when(($data['status'] ?? null) === 'awaiting', fn ($q) => $q->whereDoesntHave('currentResult'))
            ->when(($data['status'] ?? null) === 'no_agent', fn ($q) => $q->whereDoesntHave('agent'))
            ->when(($data['status'] ?? null) === 'flagged', fn ($q) => $q->whereHas('currentResult', fn ($r) => $r->whereJsonContains('flags', 'overvote')))
            ->when(($data['status'] ?? null) === 'review_flagged', fn ($q) => $q->whereHas('resultFlags', fn ($f) => $f->where('status', ResultFlag::OPEN)))
            ->select('polling_units.*')
            ->addSelect([
                // The newest result received, by its id: phones' clocks can't reorder it.
                'result_seq' => ResultSubmission::select('id')->whereColumn('polling_unit_id', 'polling_units.id')->where('is_current', true)->limit(1),
                'agent_code' => User::select('code')->whereColumn('polling_unit_id', 'polling_units.id')->where('role', Role::Agent)->whereNull('deactivated_at')->limit(1),
            ])
            ->tap(fn ($q) => $this->moreFilters($q, $data, $user->campaign_id))
            ->withCount(['resultFlags as open_flags' => fn ($f) => $f->where('status', ResultFlag::OPEN)])
            // Latest result first unless a column header asks otherwise; blanks always go last.
            ->when($sort === 'result', fn ($q) => $q->orderByRaw('result_seq is null')->orderBy('result_seq', $dir))
            ->when($sort === 'agent', fn ($q) => $q->orderByRaw('agent_code is null')->orderBy('agent_code', $dir))
            ->when($sort === 'flags', fn ($q) => $q->orderBy('open_flags', $dir))
            ->orderBy('code', $sort === 'code' ? $dir : 'asc')
            ->paginate((int) ($data['per_page'] ?? 50));

        $checkedIn = CheckIn::where('campaign_id', $user->campaign_id)
            ->whereIn('polling_unit_id', $units->pluck('id'))
            ->whereIn('status', [CheckIn::VERIFIED, CheckIn::APPROVED])
            ->pluck('polling_unit_id')->flip();
        $showPhones = $user->role->canSeePhones();
        $irev = IrevEntry::whereIn('polling_unit_id', $units->pluck('id'))->with('pollingUnit.currentResult.votes.party')
            ->orderByDesc('id')->get()->unique('polling_unit_id')
            ->mapWithKeys(fn (IrevEntry $e) => [$e->polling_unit_id => $reconciler->compareIrev($e)['status']]);

        $units->getCollection()->transform(fn (PollingUnit $u) => [
            'id' => $u->id,
            'code' => $u->code,
            'name' => $u->name,
            'ward' => $u->ward->name,
            'lga' => $u->ward->lga->name,
            'has_gps' => $u->latitude !== null,
            'agent' => $u->agent ? ['id' => $u->agent->id, 'code' => $u->agent->code, 'name' => $u->agent->name] + ($showPhones ? ['phone' => $u->agent->phone] : []) : null,
            'agent_checked_in' => $checkedIn->has($u->id),
            'irev' => $irev[$u->id] ?? null,
            'open_flags' => (int) $u->open_flags,
            'result' => $u->currentResult ? [
                'id' => $u->currentResult->id,
                'status' => $u->currentResult->status,
                'total_cast' => $u->currentResult->total_cast,
                'accredited' => $u->currentResult->accredited,
                'rejected' => $u->currentResult->rejected,
                'votes' => $u->currentResult->votes->mapWithKeys(fn ($v) => [$v->party->code => $v->votes]),
                'flags' => $u->currentResult->flags ?? [],
                'captured_at' => $u->currentResult->captured_at,
            ] : null,
        ]);

        return response()->json($units->toArray() + ['counts' => $counts]);
    }

    /**
     * Everything the legal team needs for one unit: our signed record and its chain of corrections,
     * the IReV comparison, the check-in, incidents and the collation sheets that cover it.
     */
    public function evidence(Request $request, PollingUnit $unit, EvidenceBuilder $evidence, AuditLogger $audit): JsonResponse
    {
        $user = $request->user();
        abort_unless($unit->campaign_id === $user->campaign_id, 404);
        $pack = $evidence->build($unit);
        $audit->log($user, 'evidence.viewed', $unit, [], $request->ip());

        return response()->json($pack);
    }

    /** The filters under the search box: agent attendance, result review, IReV and how the result came in. */
    private function moreFilters($q, array $data, int $campaignId): void
    {
        $present = fn ($c) => $c->where('campaign_id', $campaignId)->whereIn('status', [CheckIn::VERIFIED, CheckIn::APPROVED]);
        $openAlert = fn (string $type) => fn ($w) => $w->select(DB::raw(1))->from('alerts')
            ->where('alerts.campaign_id', $campaignId)->where('alerts.status', '!=', 'resolved')
            ->whereRaw('alerts.dedupe_key = CONCAT(?, polling_units.id)', ["{$type}:unit:"]);

        match ($data['agent'] ?? null) {
            'present' => $q->whereHas('agent')->whereHas('checkIns', $present),
            'absent' => $q->whereHas('agent')->whereDoesntHave('checkIns', $present),
            'none' => $q->whereDoesntHave('agent'),
            default => null,
        };
        match ($data['result'] ?? null) {
            'verified' => $q->whereHas('currentResult', fn ($r) => $r->where('status', ResultSubmission::APPROVED)),
            'pending' => $q->whereHas('currentResult', fn ($r) => $r->where('status', ResultSubmission::PENDING_REVIEW)),
            'sent_back' => $q->whereHas('currentResult', fn ($r) => $r->where('status', ResultSubmission::SENT_BACK)),
            'none' => $q->whereDoesntHave('currentResult'),
            default => null,
        };
        match ($data['irev'] ?? null) {
            'differs' => $q->whereExists($openAlert('irev_mismatch')),
            'missing' => $q->whereExists($openAlert('irev_missing')),
            'match' => $q->whereHas('irevEntries')->whereNotExists($openAlert('irev_mismatch'))->whereNotExists($openAlert('irev_missing')),
            'unchecked' => $q->whereDoesntHave('irevEntries'),
            default => null,
        };
        if ($data['channel'] ?? null) {
            $q->whereHas('currentResult', fn ($r) => $r->where('channel', $data['channel']));
        }
    }
}
