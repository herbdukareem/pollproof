<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\CollationEntry;
use App\Models\Lga;
use App\Models\Ward;
use App\Services\AuditLogger;
use App\Services\Reconciler;
use App\Support\ListSort;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CollationEntryController extends Controller
{
    public function __construct(private readonly Reconciler $reconciler, private readonly AuditLogger $audit) {}

    /** Record declared EC8B/EC8C/EC8D figures and compare them with our units straight away. */
    public function store(Request $request): JsonResponse
    {
        $request->user()->campaign->abortIfDeclared();
        $user = $request->user();
        $data = $request->validate([
            'level' => ['required', 'in:ward,lga,state'],
            'scope_id' => ['required_unless:level,state', 'nullable', 'integer'],
            'votes' => ['required', 'array', 'min:1'],
            'votes.*' => ['integer', 'min:0'],
            'accredited' => ['nullable', 'integer', 'min:0'],
            'rejected' => ['nullable', 'integer', 'min:0'],
            'announced_at' => ['required', 'date'],
            'photo' => ['nullable', 'image', 'max:10240'],
        ]);

        $scopeId = $data['level'] === 'state' ? null : (int) $data['scope_id'];
        if ($data['level'] === 'ward') {
            $ward = Ward::where('campaign_id', $user->campaign_id)->findOrFail($scopeId);
            abort_if($user->hasRole(Role::CollationAgent) && $user->ward_id !== $ward->id, 403, 'This is not your collation centre.');
        } elseif ($data['level'] === 'lga') {
            Lga::where('campaign_id', $user->campaign_id)->findOrFail($scopeId);
            abort_if($user->hasRole(Role::CollationAgent) && $user->lga_id !== $scopeId, 403, 'This is not your collation centre.');
        } else {
            abort_if($user->hasRole(Role::CollationAgent), 403, 'State figures are entered by the command room.');
        }

        $known = $user->campaign->parties()->pluck('code')->all();
        $votes = array_change_key_case($data['votes'], CASE_UPPER);
        $unknown = array_diff(array_keys($votes), $known);
        abort_if($unknown, 422, 'Unknown party code: '.implode(', ', $unknown));

        $rejected = (int) ($data['rejected'] ?? 0);
        $entry = CollationEntry::create([
            'campaign_id' => $user->campaign_id,
            'level' => $data['level'],
            'scope_id' => $scopeId,
            'entered_by' => $user->id,
            'votes' => array_map('intval', $votes),
            'accredited' => $data['accredited'] ?? null,
            'rejected' => $rejected,
            'total_cast' => array_sum($votes) + $rejected,
            'photo_path' => $request->file('photo')?->store("campaigns/{$user->campaign_id}/collation", config('pollproof.evidence_disk')),
            'announced_at' => $data['announced_at'],
        ]);

        $comparison = $this->reconciler->checkCollation($entry);
        $this->audit->log($user, 'collation.entered', $entry, ['level' => $entry->level, 'differences' => $comparison['differences']], $request->ip());

        return response()->json(['id' => $entry->id, 'comparison' => $comparison], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $campaignId = $request->user()->campaign_id;
        $rank = fn (array $c) => $c['differences'] ? ($c['coverage_complete'] ? 0 : 1) : ($c['coverage_complete'] ? 3 : 2);
        $sorts = [
            'latest' => [fn ($r) => $r['announced_at'], 'desc'],
            'scope' => [fn ($r) => $r['comparison']['scope'], 'asc'],
            'ours' => [fn ($r) => $r['comparison']['our_total_cast'], 'desc'],
            'declared' => [fn ($r) => $r['comparison']['declared_total_cast'], 'desc'],
            'diff' => [fn ($r) => array_sum(array_map('abs', $r['comparison']['differences'])), 'desc'],
            'status' => [fn ($r) => $rank($r['comparison']), 'asc'],
        ];
        $data = $request->validate(['level' => ['nullable', 'in:ward,lga,state']] + ListSort::rules($sorts));
        $entries = CollationEntry::where('campaign_id', $campaignId)
            ->when($data['level'] ?? null, fn ($q, $l) => $q->where('level', $l))
            ->latest('announced_at')
            ->get();

        // Only the latest entry per scope is checked; earlier ones are history.
        $latest = $entries->unique(fn ($e) => $e->level.':'.$e->scope_id)->values();

        return response()->json(['data' => ListSort::apply($latest->map(fn ($e) => [
            'id' => $e->id,
            'announced_at' => $e->announced_at,
            'comparison' => $this->reconciler->compareCollation($e),
        ]), $data, $sorts)]);
    }
}
