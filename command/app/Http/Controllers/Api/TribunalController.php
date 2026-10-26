<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\PollingUnit;
use App\Models\TribunalCase;
use App\Models\TribunalGround;
use App\Models\TribunalTask;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\TribunalBundle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** The legal team's workspace for a petition: grounds and our answers, tasks, and the reply bundle. */
class TribunalController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(TribunalCase::where('campaign_id', $request->user()->campaign_id)
            ->withCount(['grounds', 'grounds as ready_count' => fn ($q) => $q->where('status', 'ready'), 'tasks as open_tasks_count' => fn ($q) => $q->whereNull('done_at')])
            ->orderByRaw("status = 'closed'")->latest('id')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $case = TribunalCase::create($this->validateCase($request) + ['campaign_id' => $request->user()->campaign_id]);
        $this->audit->log($request->user(), 'tribunal.case_created', $case, ['title' => $case->title], $request->ip());

        return $this->show($request, $case)->setStatusCode(201);
    }

    public function show(Request $request, TribunalCase $case): JsonResponse
    {
        $this->own($request, $case);
        $case->load(['grounds.owner:id,name', 'tasks.owner:id,name']);
        $unitIds = $case->grounds->flatMap(fn ($g) => $g->unit_ids ?? [])->unique();
        $units = PollingUnit::whereIn('id', $unitIds)->with('ward.lga')->get()->keyBy('id');
        $staff = User::where('campaign_id', $case->campaign_id)->whereIn('role', [Role::Dg, Role::Legal, Role::SituationRoom, Role::DataOfficer, Role::Verifier])
            ->whereNull('deactivated_at')->orderBy('name')->get(['id', 'name', 'role']);

        return response()->json([
            'case' => $case->only(['id', 'title', 'petitioner', 'our_role', 'status', 'milestones', 'created_at']),
            'grounds' => $case->grounds->map(fn ($g) => [
                'id' => $g->id, 'label' => $g->label, 'claim' => $g->claim, 'answer' => $g->answer, 'evidence_note' => $g->evidence_note,
                'status' => $g->status, 'owner' => $g->owner?->only(['id', 'name']),
                'units' => collect($g->unit_ids ?? [])->map(fn ($id) => $units->get($id))->filter()->map(fn ($u) => ['id' => $u->id, 'code' => $u->code, 'place' => "{$u->ward->name} · {$u->ward->lga->name}"])->values(),
            ]),
            'tasks' => $case->tasks->map(fn ($t) => ['id' => $t->id, 'title' => $t->title, 'owner' => $t->owner?->only(['id', 'name']), 'due_on' => $t->due_on?->toDateString(), 'done_at' => $t->done_at]),
            'staff' => $staff->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'role' => $u->role->label()]),
        ]);
    }

    public function update(Request $request, TribunalCase $case): JsonResponse
    {
        $this->own($request, $case);
        $data = $this->validateCase($request, true);
        $case->update($data);
        $this->audit->log($request->user(), 'tribunal.case_updated', $case, collect($data)->except('milestones')->all(), $request->ip());

        return $this->show($request, $case);
    }

    public function storeGround(Request $request, TribunalCase $case): JsonResponse
    {
        $this->own($request, $case);
        $data = $this->validateGround($request, $case);
        $case->grounds()->create($data + ['sort_order' => $case->grounds()->max('sort_order') + 1]);
        $this->audit->log($request->user(), 'tribunal.ground_added', $case, ['label' => $data['label']], $request->ip());

        return $this->show($request, $case)->setStatusCode(201);
    }

    public function updateGround(Request $request, TribunalGround $ground): JsonResponse
    {
        $case = TribunalCase::findOrFail($ground->tribunal_case_id);
        $this->own($request, $case);
        $data = $this->validateGround($request, $case, true);
        $ground->update($data);
        $this->audit->log($request->user(), 'tribunal.ground_updated', $ground, collect($data)->only(['status', 'label'])->all(), $request->ip());

        return $this->show($request, $case);
    }

    public function storeTask(Request $request, TribunalCase $case): JsonResponse
    {
        $this->own($request, $case);
        $data = $request->validate(['title' => ['required', 'string', 'max:200'], 'owner_id' => ['nullable', 'integer', $this->staffRule($case)], 'due_on' => ['nullable', 'date']]);
        $case->tasks()->create($data);

        return $this->show($request, $case)->setStatusCode(201);
    }

    public function updateTask(Request $request, TribunalTask $task): JsonResponse
    {
        $case = TribunalCase::findOrFail($task->tribunal_case_id);
        $this->own($request, $case);
        $data = $request->validate(['done' => ['sometimes', 'boolean'], 'title' => ['sometimes', 'string', 'max:200'], 'owner_id' => ['sometimes', 'nullable', 'integer', $this->staffRule($case)], 'due_on' => ['sometimes', 'nullable', 'date']]);
        if (array_key_exists('done', $data)) {
            $data += $data['done'] ? ['done_at' => now(), 'done_by' => $request->user()->id] : ['done_at' => null, 'done_by' => null];
            unset($data['done']);
        }
        $task->update($data);

        return $this->show($request, $case);
    }

    public function bundle(Request $request, TribunalCase $case, TribunalBundle $bundle): BinaryFileResponse
    {
        $this->own($request, $case);
        $path = $bundle->build($case);
        $this->audit->log($request->user(), 'tribunal.bundle_exported', $case, ['sha256' => hash_file('sha256', $path)], $request->ip());

        return response()->download($path, 'reply-bundle-'.Str::slug($case->title).'-'.now()->format('Ymd-Hi').'.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    private function own(Request $request, TribunalCase $case): void
    {
        abort_unless($case->campaign_id === $request->user()->campaign_id, 404);
    }

    private function validateCase(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$req, 'string', 'max:200'],
            'petitioner' => [$req, 'string', 'max:120'],
            'our_role' => ['sometimes', Rule::in(['respondent', 'petitioner'])],
            'status' => ['sometimes', Rule::in(['open', 'closed'])],
            'milestones' => ['sometimes', 'nullable', 'array', 'max:20'],
            'milestones.*.date' => ['required', 'string', 'max:40'],
            'milestones.*.label' => ['required', 'string', 'max:120'],
        ]);
    }

    private function validateGround(Request $request, TribunalCase $case, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';
        $data = $request->validate([
            'label' => [$req, 'string', 'max:60'],
            'claim' => [$req, 'string', 'max:2000'],
            'answer' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'evidence_note' => ['sometimes', 'nullable', 'string', 'max:255'],
            'unit_codes' => ['sometimes', 'array', 'max:500'],
            'unit_codes.*' => ['string', 'max:30'],
            'owner_id' => ['sometimes', 'nullable', 'integer', $this->staffRule($case)],
            'status' => ['sometimes', Rule::in(['in_progress', 'ready'])],
        ]);
        if (isset($data['unit_codes'])) {
            $codes = array_map(fn ($c) => strtoupper(trim($c)), $data['unit_codes']);
            $units = PollingUnit::where('campaign_id', $case->campaign_id)->whereIn('code', $codes)->pluck('id', 'code');
            if ($missing = array_diff($codes, $units->keys()->all())) {
                abort(422, 'Unknown polling unit: '.implode(', ', $missing));
            }
            $data['unit_ids'] = $units->values()->all();
            unset($data['unit_codes']);
        }

        return $data;
    }

    private function staffRule(TribunalCase $case): Exists
    {
        return Rule::exists('users', 'id')->where('campaign_id', $case->campaign_id);
    }
}
