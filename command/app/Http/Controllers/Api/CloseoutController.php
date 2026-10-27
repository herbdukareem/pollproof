<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CheckIn;
use App\Models\CollationEntry;
use App\Models\Incident;
use App\Models\IrevEntry;
use App\Models\ResultSubmission;
use App\Services\AuditLogger;
use App\Services\TallyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** After the declaration: the declared result against our records, and what happens to the data. */
class CloseoutController extends Controller
{
    public const RETENTION = ['keep_until_cases_end', 'handover_then_delete', 'delete_now'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function show(Request $request, TallyService $tally): JsonResponse
    {
        $campaign = $request->user()->campaign;
        $t = $tally->summary($campaign);
        $parties = $campaign->parties()->get();
        $ours = collect($t['candidates'])->pluck('votes', 'party');
        $declared = $campaign->declared_votes ?? [];
        $openCases = $campaign->tribunalCases()->where('status', 'open')->count();

        $rows = $parties->map(fn ($p) => [
            'party' => $p->code,
            'name' => $p->name,
            'is_ours' => $p->is_ours,
            'declared' => $declared[$p->code] ?? null,
            'ours' => (int) ($ours[$p->code] ?? 0),
            'difference' => isset($declared[$p->code]) ? $declared[$p->code] - (int) ($ours[$p->code] ?? 0) : null,
        ])->sortByDesc(fn ($r) => $r['declared'] ?? $r['ours'])->values();

        $winner = $declared ? $rows->first() : null;
        $runnerUp = $declared ? $rows->get(1) : null;
        $ourParty = $parties->firstWhere('is_ours', true);
        $c = $campaign->id;

        return response()->json([
            'declared' => $campaign->isDeclared(),
            'declared_at' => $campaign->declared_at,
            'winner' => $winner ? ['party' => $winner['party'], 'name' => $winner['name'], 'is_ours' => $winner['is_ours'], 'margin' => $winner['declared'] - ($runnerUp['declared'] ?? 0)] : null,
            'rows' => $rows,
            'units' => $t['units'],
            'units_reported' => $t['units_reported'],
            'lga_count' => $t['lga_count'],
            'lgas_required' => $t['lgas_required'],
            'our_lgas_meeting_threshold' => collect($t['candidates'])->firstWhere('party', $ourParty?->code)['lgas_meeting_threshold'] ?? 0,
            'stored' => [
                'results' => ResultSubmission::where('campaign_id', $c)->count(),
                'photos' => ResultSubmission::where('campaign_id', $c)->whereNotNull('photo_path')->count()
                    + CollationEntry::where('campaign_id', $c)->whereNotNull('photo_path')->count()
                    + IrevEntry::where('campaign_id', $c)->whereNotNull('screenshot_path')->count(),
                'check_ins' => CheckIn::where('campaign_id', $c)->count(),
                'incidents' => Incident::where('campaign_id', $c)->count(),
                'audit_entries' => AuditLog::where('campaign_id', $c)->count(),
            ],
            'open_cases' => $openCases,
            'evidence_units' => $campaign->tribunalCases()->with('grounds')->get()->flatMap(fn ($k) => $k->grounds->flatMap(fn ($g) => $g->unit_ids ?? []))->unique()->count(),
            'retention' => ['choice' => $campaign->retention_choice, 'set_at' => $campaign->retention_set_at],
        ]);
    }

    /** Record the official declaration. From then on election-day records are read-only. */
    public function declare(Request $request, TallyService $tally): JsonResponse
    {
        $campaign = $request->user()->campaign;
        $codes = $campaign->parties()->pluck('code')->all();
        $data = $request->validate([
            'declared_at' => ['required', 'date', 'before_or_equal:now'],
            'votes' => ['required', 'array', 'min:1'],
            'votes.*' => ['integer', 'min:0'],
        ]);
        $votes = array_change_key_case($data['votes'], CASE_UPPER);
        if ($unknown = array_diff(array_keys($votes), $codes)) {
            abort(422, 'Unknown party code: '.implode(', ', $unknown));
        }
        $first = ! $campaign->isDeclared();
        $campaign->update(['declared_at' => $data['declared_at'], 'declared_votes' => array_map('intval', $votes)]);
        $this->audit->log($request->user(), $first ? 'closeout.declared' : 'closeout.declaration_corrected', $campaign, ['votes' => $votes, 'declared_at' => $data['declared_at']], $request->ip());

        return $this->show($request, $tally);
    }

    /** Both the DG and Ashlab confirm before any deletion; this records the campaign's choice. */
    public function retention(Request $request, TallyService $tally): JsonResponse
    {
        $campaign = $request->user()->campaign;
        $data = $request->validate(['choice' => ['required', Rule::in(self::RETENTION)]]);
        if ($data['choice'] === 'delete_now' && $campaign->tribunalCases()->where('status', 'open')->exists()) {
            abort(422, 'Deletion is blocked while a tribunal case is open.');
        }
        $campaign->update(['retention_choice' => $data['choice'], 'retention_set_by' => $request->user()->id, 'retention_set_at' => now()]);
        $this->audit->log($request->user(), 'closeout.retention_chosen', $campaign, $data, $request->ip());

        return $this->show($request, $tally);
    }
}
