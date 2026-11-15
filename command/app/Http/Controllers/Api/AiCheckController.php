<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\CheckResultVideo;
use App\Models\ResultFlag;
use App\Models\VideoCheck;
use App\Services\AlertService;
use App\Services\AuditLogger;
use App\Services\VideoChecker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The AI checks page: announcement videos whose figures, as the AI heard them, differ from the
 * agent's signed result, and the rest of the checks for reference. A person decides each one:
 * confirm (the result is flagged for the reviewers and legal) or dismiss (the AI misheard).
 */
class AiCheckController extends Controller
{
    /** The page's tabs and what each shows. */
    private const VIEWS = ['review', 'unclear', 'no_result', 'match', 'reviewed', 'pending', 'failed', 'all'];

    public function __construct(private readonly AlertService $alerts, private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'view' => ['nullable', 'in:'.implode(',', self::VIEWS)],
            'lga_id' => ['nullable', 'integer'],
            'election' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:60'],
            'check' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);
        $base = fn () => VideoCheck::where('campaign_id', $user->campaign_id)
            ->when($data['lga_id'] ?? null, fn ($q, $v) => $q->whereHas('pollingUnit.ward', fn ($w) => $w->where('lga_id', $v)))
            ->when($data['election'] ?? null, fn ($q, $v) => $q->where('election_id', $v))
            ->when($data['search'] ?? null, fn ($q, $s) => $q->whereHas('pollingUnit', fn ($u) => $u->whereLike('code', "%{$s}%")->orWhereLike('name', "%{$s}%")));
        $view = $data['view'] ?? 'review';
        $page = $this->view($base(), $view)
            ->when($data['check'] ?? null, fn ($q, $id) => $q->orderByRaw('id = ? desc', [$id]))
            ->with(['video.user', 'video.pollingUnit', 'video.ward', 'video.lga', 'video.election', 'video.incident', 'election.parties', 'pollingUnit.ward.lga', 'result.votes.party', 'reviewer'])
            ->latest('id')
            ->paginate((int) ($data['per_page'] ?? 20));
        $page->getCollection()->transform(fn (VideoCheck $c) => $this->present($c));

        return response()->json($page->toArray() + [
            'counts' => collect(self::VIEWS)->mapWithKeys(fn ($v) => [$v => $this->view($base(), $v)->count()]),
            'enabled' => (bool) config('pollproof.ai.enabled'),
        ]);
    }

    public function review(Request $request, VideoCheck $check): JsonResponse
    {
        $user = $request->user();
        abort_unless($check->campaign_id === $user->campaign_id, 404);
        $data = $request->validate([
            'action' => ['required', 'in:confirm,dismiss,reopen'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        if ($data['action'] === 'confirm') {
            $result = $check->result;
            if (! $result || $check->outcome !== 'differs') {
                throw ValidationException::withMessages(['action' => 'Only a check that differs from a signed result can be confirmed.']);
            }
            $user->campaign->abortIfDeclared();
            $said = collect($check->differences ?? [])->map(fn ($d, $k) => "{$k}: heard {$d['heard']}, signed ".($d['signed'] ?? 'none'))->implode('; ');
            $note = trim('The announcement video differs from this result ('.$said.'). '.($data['note'] ?? ''));
            $flag = ResultFlag::create([
                'campaign_id' => $result->campaign_id, 'result_submission_id' => $result->id, 'polling_unit_id' => $result->polling_unit_id,
                'user_id' => $user->id, 'reason' => 'figures_differ', 'note' => mb_substr($note, 0, 1000), 'status' => ResultFlag::OPEN,
            ]);
            $this->alerts->raise($result->campaign_id, 'result_flagged', ResultFlag::REASONS['figures_differ'][1], "result_flag:{$flag->id}",
                "{$result->pollingUnit->code}: result flagged, announcement video differs", "{$user->name}: {$flag->note}", $result, ['flag_id' => $flag->id], $result->election);
        }
        $check->update([
            'review' => $data['action'] === 'reopen' ? 'open' : ($data['action'] === 'confirm' ? 'confirmed' : 'dismissed'),
            'reviewed_by' => $data['action'] === 'reopen' ? null : $user->id,
            'reviewed_at' => $data['action'] === 'reopen' ? null : now(),
            'review_note' => $data['note'] ?? null,
        ]);
        // The AI alert is settled either way; reopening raises it again if it still differs.
        $this->alerts->clear($check->campaign_id, "ai_mismatch:video:{$check->video_clip_id}");
        if ($data['action'] === 'reopen') {
            app(VideoChecker::class)->compare($check->fresh());
        }
        $this->audit->log($user, "ai_check.{$data['action']}", $check, ['note' => $data['note'] ?? null], $request->ip());

        return response()->json($this->present($check->fresh(['video.user', 'video.pollingUnit', 'video.ward', 'video.lga', 'video.election', 'election.parties', 'pollingUnit.ward.lga', 'result.votes.party', 'reviewer'])));
    }

    public function rerun(Request $request, VideoCheck $check): JsonResponse
    {
        abort_unless($check->campaign_id === $request->user()->campaign_id, 404);
        abort_unless(config('pollproof.ai.enabled'), 422, 'AI is off: set OPENAI_API_KEY on the server.');
        $check->update(['status' => 'queued', 'error' => null]);
        CheckResultVideo::dispatch($check->id);
        $this->audit->log($request->user(), 'ai_check.rerun', $check, [], $request->ip());

        return response()->json(['status' => 'queued']);
    }

    private function view(Builder $q, string $view): Builder
    {
        return match ($view) {
            'review' => $q->where('status', 'done')->where('outcome', 'differs')->where('review', 'open'),
            'unclear', 'no_result', 'match' => $q->where('status', 'done')->where('outcome', $view)->where('review', 'open'),
            'reviewed' => $q->where('review', '!=', 'open'),
            'pending' => $q->whereIn('status', ['queued', 'working']),
            'failed' => $q->whereIn('status', ['failed', 'skipped']),
            default => $q,
        };
    }

    private function present(VideoCheck $c): array
    {
        $result = $c->result;
        $unit = $c->pollingUnit;

        return [
            'id' => $c->id,
            'status' => $c->status,
            'outcome' => $c->outcome,
            'outcome_label' => VideoCheck::OUTCOMES[$c->outcome] ?? null,
            'error' => $c->error,
            'transcript' => $c->transcript,
            'heard' => $c->heard,
            'differences' => $c->differences ?? (object) [],
            'checked_at' => $c->checked_at,
            'review' => $c->review,
            'reviewed_by' => $c->reviewer?->name,
            'reviewed_at' => $c->reviewed_at,
            'review_note' => $c->review_note,
            'video' => $c->video?->present(),
            'polling_unit' => $unit ? ['id' => $unit->id, 'code' => $unit->code, 'name' => $unit->name, 'ward' => $unit->ward?->name, 'lga' => $unit->ward?->lga?->name] : null,
            'election' => $c->election?->only(['id', 'name', 'code', 'type']),
            'ballot' => $c->election?->parties->map(fn ($p) => ['code' => $p->code, 'name' => $p->name, 'is_ours' => $p->is_ours, 'candidate' => $p->pivot->candidate])->values() ?? [],
            'result' => $result ? [
                'id' => $result->id, 'status' => $result->status, 'fingerprint' => $result->fingerprint(), 'channel' => $result->channel,
                'accredited' => $result->accredited, 'rejected' => $result->rejected, 'total_cast' => $result->total_cast,
                'votes' => $result->votes->mapWithKeys(fn ($v) => [$v->party->code => $v->votes]), 'captured_at' => $result->captured_at,
            ] : null,
        ];
    }
}
