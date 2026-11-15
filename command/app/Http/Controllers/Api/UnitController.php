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
use App\Services\Races;
use App\Services\Reconciler;
use App\Services\TallyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UnitController extends Controller
{
    /** The race's units with their reporting status for the race, most urgent first. */
    public function index(Request $request, Reconciler $reconciler): JsonResponse
    {
        $user = $request->user();
        $race = Races::fromRequest($request);
        $rid = $race->id;
        // This race's current result, and review flags on it.
        $result = fn ($extra = null) => fn ($r) => $r->where('election_id', $rid)->when($extra, $extra);
        $openFlag = fn ($f) => $f->where('status', ResultFlag::OPEN)->whereHas('result', fn ($r) => $r->where('election_id', $rid));
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
        $scope = fn () => $race->units()
            ->when($data['ward_id'] ?? null, fn ($q, $w) => $q->where('ward_id', $w))
            ->when($data['lga_id'] ?? null, fn ($q, $l) => $q->whereHas('ward', fn ($w) => $w->where('lga_id', $l)));
        $counts = [
            'all' => $scope()->count(),
            'reported' => $scope()->whereHas('currentResults', $result())->count(),
            'awaiting' => $scope()->whereDoesntHave('currentResults', $result())->count(),
            'no_agent' => $scope()->whereDoesntHave('agent')->count(),
            'flagged' => $scope()->whereHas('currentResults', $result(fn ($r) => $r->whereJsonContains('flags', 'overvote')))->count(),
            'review_flagged' => $scope()->whereHas('resultFlags', $openFlag)->count(),
        ];

        $units = $race->units()
            ->with(['ward.lga', 'currentResults' => $result(), 'currentResults.votes.party', 'agent:id,name,code,polling_unit_id'])
            ->when($data['ward_id'] ?? null, fn ($q, $w) => $q->where('ward_id', $w))
            ->when($data['lga_id'] ?? null, fn ($q, $l) => $q->whereHas('ward', fn ($w) => $w->where('lga_id', $l)))
            ->when($data['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->whereLike('code', "%{$s}%")->orWhereLike('name', "%{$s}%")))
            ->when(($data['status'] ?? null) === 'reported', fn ($q) => $q->whereHas('currentResults', $result()))
            ->when(($data['status'] ?? null) === 'awaiting', fn ($q) => $q->whereDoesntHave('currentResults', $result()))
            ->when(($data['status'] ?? null) === 'no_agent', fn ($q) => $q->whereDoesntHave('agent'))
            ->when(($data['status'] ?? null) === 'flagged', fn ($q) => $q->whereHas('currentResults', $result(fn ($r) => $r->whereJsonContains('flags', 'overvote'))))
            ->when(($data['status'] ?? null) === 'review_flagged', fn ($q) => $q->whereHas('resultFlags', $openFlag))
            ->select('polling_units.*')
            ->addSelect([
                // The newest result received, by its id: phones' clocks can't reorder it.
                'result_seq' => ResultSubmission::select('id')->whereColumn('polling_unit_id', 'polling_units.id')->where('election_id', $rid)->where('is_current', true)->limit(1),
                'agent_code' => User::select('code')->whereColumn('polling_unit_id', 'polling_units.id')->where('role', Role::Agent)->whereNull('deactivated_at')->limit(1),
            ])
            ->tap(fn ($q) => $this->moreFilters($q, $data, $user->campaign_id, $rid))
            ->withCount(['resultFlags as open_flags' => $openFlag])
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
        $irev = IrevEntry::whereIn('polling_unit_id', $units->pluck('id'))->where('election_id', $rid)->with('pollingUnit.currentResults.votes.party')
            ->orderByDesc('id')->get()->unique('polling_unit_id')
            ->mapWithKeys(fn (IrevEntry $e) => [$e->polling_unit_id => $reconciler->compareIrev($e)['status']]);

        $units->getCollection()->transform(fn (PollingUnit $u) => $this->row($u, $u->currentResultFor($rid), $checkedIn, $irev, $showPhones));

        return response()->json($units->toArray() + ['counts' => $counts, 'election' => TallyService::race($race), 'parties' => $race->parties()->get(['code', 'name', 'is_ours'])]);
    }

    private function row(PollingUnit $u, ?ResultSubmission $r, $checkedIn, $irev, bool $showPhones): array
    {
        return [
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
            'result' => $r ? [
                'id' => $r->id,
                'status' => $r->status,
                'total_cast' => $r->total_cast,
                'accredited' => $r->accredited,
                'rejected' => $r->rejected,
                'votes' => $r->votes->mapWithKeys(fn ($v) => [$v->party->code => $v->votes]),
                'flags' => $r->flags ?? [],
                'captured_at' => $r->captured_at,
            ] : null,
        ];
    }

    /**
     * Everything the legal team needs for one unit: our signed record and its chain of corrections,
     * the IReV comparison, the check-in, incidents and the collation sheets that cover it.
     */
    public function evidence(Request $request, PollingUnit $unit, EvidenceBuilder $evidence, AuditLogger $audit): JsonResponse
    {
        $user = $request->user();
        abort_unless($unit->campaign_id === $user->campaign_id, 404);
        $pack = $evidence->build($unit, Races::fromRequest($request, allowAll: true));
        $audit->log($user, 'evidence.viewed', $unit, [], $request->ip());

        return response()->json($pack);
    }

    /** The filters under the search box: agent attendance, result review, IReV and how the result came in. */
    private function moreFilters($q, array $data, int $campaignId, int $raceId): void
    {
        $present = fn ($c) => $c->where('campaign_id', $campaignId)->whereIn('status', [CheckIn::VERIFIED, CheckIn::APPROVED]);
        $openAlert = fn (string $type) => fn ($w) => $w->select(DB::raw(1))->from('alerts')
            ->where('alerts.campaign_id', $campaignId)->where('alerts.status', '!=', 'resolved')
            ->whereRaw('alerts.dedupe_key = CONCAT(?, polling_units.id, ?)', ["{$type}:unit:", ":race:{$raceId}"]);
        $result = fn ($extra = null) => fn ($r) => $r->where('election_id', $raceId)->when($extra, $extra);

        match ($data['agent'] ?? null) {
            'present' => $q->whereHas('agent')->whereHas('checkIns', $present),
            'absent' => $q->whereHas('agent')->whereDoesntHave('checkIns', $present),
            'none' => $q->whereDoesntHave('agent'),
            default => null,
        };
        match ($data['result'] ?? null) {
            'verified' => $q->whereHas('currentResults', $result(fn ($r) => $r->where('status', ResultSubmission::APPROVED))),
            'pending' => $q->whereHas('currentResults', $result(fn ($r) => $r->where('status', ResultSubmission::PENDING_REVIEW))),
            'sent_back' => $q->whereHas('currentResults', $result(fn ($r) => $r->where('status', ResultSubmission::SENT_BACK))),
            'none' => $q->whereDoesntHave('currentResults', $result()),
            default => null,
        };
        match ($data['irev'] ?? null) {
            'differs' => $q->whereExists($openAlert('irev_mismatch')),
            'missing' => $q->whereExists($openAlert('irev_missing')),
            'match' => $q->whereHas('irevEntries', $result())->whereNotExists($openAlert('irev_mismatch'))->whereNotExists($openAlert('irev_missing')),
            'unchecked' => $q->whereDoesntHave('irevEntries', $result()),
            default => null,
        };
        if ($data['channel'] ?? null) {
            $q->whereHas('currentResults', $result(fn ($r) => $r->where('channel', $data['channel'])));
        }
    }
}
