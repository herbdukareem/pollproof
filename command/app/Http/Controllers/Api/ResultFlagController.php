<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ResultFlag;
use App\Models\ResultSubmission;
use App\Services\AlertService;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Flags on a result: a reviewer marks a concern with a comment, others discuss it, and someone clears it.
 * The signed result itself is never touched; each flag raises an alert so legal and the situation room see it.
 */
class ResultFlagController extends Controller
{
    public function __construct(private readonly AuditLogger $audit, private readonly AlertService $alerts) {}

    public function index(Request $request, ResultSubmission $result): JsonResponse
    {
        abort_unless($result->campaign_id === $request->user()->campaign_id, 404);

        return response()->json([
            'reasons' => collect(ResultFlag::REASONS)->map(fn ($r, $k) => ['value' => $k, 'label' => $r[0], 'severity' => $r[1]])->values(),
            'data' => ResultFlag::where('polling_unit_id', $result->polling_unit_id)->latest('id')->get()->map->present(),
        ]);
    }

    public function store(Request $request, ResultSubmission $result): JsonResponse
    {
        $user = $request->user();
        $user->campaign->abortIfDeclared();
        abort_unless($result->campaign_id === $user->campaign_id, 404);
        $data = $request->validate([
            'reason' => ['required', Rule::in(array_keys(ResultFlag::REASONS))],
            'note' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $flag = ResultFlag::create([
            'campaign_id' => $result->campaign_id,
            'result_submission_id' => $result->id,
            'polling_unit_id' => $result->polling_unit_id,
            'user_id' => $user->id,
            'reason' => $data['reason'],
            'note' => trim($data['note']),
            'status' => ResultFlag::OPEN,
        ]);
        $unit = $result->pollingUnit;
        $this->alerts->raise($result->campaign_id, 'result_flagged', ResultFlag::REASONS[$data['reason']][1], "result_flag:{$flag->id}",
            "{$unit->code}: result flagged, ".strtolower($flag->label()), "{$user->name}: {$flag->note}", $result, ['flag_id' => $flag->id]);
        $this->audit->log($user, 'result.flagged', $result, ['flag_id' => $flag->id, 'reason' => $data['reason'], 'note' => $flag->note], $request->ip());

        return response()->json($flag->present(), 201);
    }

    public function comment(Request $request, ResultFlag $flag): JsonResponse
    {
        $user = $request->user();
        abort_unless($flag->campaign_id === $user->campaign_id, 404);
        $data = $request->validate(['body' => ['required', 'string', 'max:1000']]);
        $flag->comments()->create(['user_id' => $user->id, 'body' => trim($data['body'])]);
        $flag->touch();
        $this->audit->log($user, 'result.flag_comment', $flag->result, ['flag_id' => $flag->id], $request->ip());

        return response()->json($flag->fresh()->present(), 201);
    }

    public function update(Request $request, ResultFlag $flag): JsonResponse
    {
        $user = $request->user();
        abort_unless($flag->campaign_id === $user->campaign_id, 404);
        $data = $request->validate([
            'status' => ['required', 'in:open,cleared'],
            'note' => [Rule::requiredIf($request->input('status') === 'cleared'), 'nullable', 'string', 'max:1000'],
        ]);
        $clearing = $data['status'] === ResultFlag::CLEARED;
        $flag->update([
            'status' => $data['status'],
            'cleared_by' => $clearing ? $user->id : null,
            'cleared_at' => $clearing ? now() : null,
            'clear_note' => $clearing ? trim($data['note']) : null,
        ]);
        if ($clearing) {
            $this->alerts->clear($flag->campaign_id, "result_flag:{$flag->id}");
        } else {
            $unit = $flag->pollingUnit;
            $this->alerts->raise($flag->campaign_id, 'result_flagged', ResultFlag::REASONS[$flag->reason][1], "result_flag:{$flag->id}",
                "{$unit->code}: flag reopened, ".strtolower($flag->label()), $flag->note, $flag->result, ['flag_id' => $flag->id, 'reopened' => now()->toIso8601String()]);
        }
        $this->audit->log($user, $clearing ? 'result.flag_cleared' : 'result.flag_reopened', $flag->result, ['flag_id' => $flag->id, 'note' => $data['note'] ?? null], $request->ip());

        return response()->json($flag->fresh()->present());
    }
}
