<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Election;
use App\Models\Lga;
use App\Models\Party;
use App\Models\Ward;
use App\Services\AuditLogger;
use App\Services\TallyService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The races a campaign is fighting. Everyone in the command room can list them (for the race
 * switcher); the DG sets them up.
 */
class ElectionController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** Every race, for the switcher and the Races setup page. */
    public function index(Request $request): JsonResponse
    {
        $campaign = $request->user()->campaign;
        $races = $campaign->elections()->with(['areas', 'parties'])->withCount('results')->get();

        return response()->json([
            'races' => $races->map(fn (Election $e) => $this->present($e))->values(),
            'current_date' => $campaign->currentElectionDate(),
            'primary_id' => $campaign->primaryElection()?->id,
            'types' => collect(Election::TYPES)->map(fn ($t, $key) => ['value' => $key] + $t)->sortBy('order')->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $campaign = $request->user()->campaign;
        $data = $this->validated($request);
        $race = DB::transaction(function () use ($campaign, $data) {
            $race = Election::create($this->attributes($data) + [
                'campaign_id' => $campaign->id,
                'sort_order' => (int) ($campaign->elections()->reorder()->max('sort_order') ?? -1) + 1,
            ]);
            $this->syncAreaAndBallot($race, $data);

            return $race;
        });
        $this->audit->log($request->user(), 'race.created', $race, ['name' => $race->name, 'type' => $race->type], $request->ip());

        return response()->json($this->present($race->fresh(['areas', 'parties'])->loadCount('results')), 201);
    }

    public function update(Request $request, Election $election): JsonResponse
    {
        abort_unless($election->campaign_id === $request->user()->campaign_id, 404);
        $data = $this->validated($request, $election);
        $hasResults = $election->results()->exists();
        if ($hasResults && $data['type'] !== $election->type) {
            throw ValidationException::withMessages(['type' => 'Results are already recorded for this race, so its type can no longer change.']);
        }
        DB::transaction(function () use ($election, $data) {
            $election->update($this->attributes($data));
            $this->syncAreaAndBallot($election, $data);
        });
        $this->audit->log($request->user(), 'race.updated', $election, ['name' => $election->name], $request->ip());

        return response()->json($this->present($election->fresh(['areas', 'parties'])->loadCount('results')));
    }

    public function destroy(Request $request, Election $election): JsonResponse
    {
        abort_unless($election->campaign_id === $request->user()->campaign_id, 404);
        abort_if($election->results()->exists(), 422, 'This race already has results recorded, so it cannot be removed.');
        abort_if(Election::where('campaign_id', $election->campaign_id)->count() === 1, 422, 'A campaign needs at least one race.');
        $this->audit->log($request->user(), 'race.deleted', $election, ['name' => $election->name], $request->ip());
        $photos = $election->parties->pluck('pivot.photo_path')->filter()->all();
        $election->delete();
        $this->disk()->delete($photos);

        return response()->json(['deleted' => true]);
    }

    /** Upload or replace a candidate's photo in this race (JPEG, PNG or WebP, up to 5 MB). */
    public function uploadPhoto(Request $request, Election $election, Party $party): JsonResponse
    {
        $onBallot = $this->onBallot($request, $election, $party);
        $request->validate(['photo' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:5120']], ['photo.max' => 'Use a photo under 5 MB.']);
        $file = $request->file('photo');
        $path = $file->storeAs("campaigns/{$election->campaign_id}/candidates", "{$election->id}-{$party->id}-".Str::random(8).'.'.($file->extension() ?: 'jpg'), config('pollproof.evidence_disk'));
        $old = $onBallot->pivot->photo_path;
        $election->parties()->updateExistingPivot($party->id, ['photo_path' => $path]);
        if ($old) {
            $this->disk()->delete($old);
        }
        $this->audit->log($request->user(), 'race.candidate_photo_updated', $election, ['party' => $party->code], $request->ip());

        return response()->json($this->present($election->fresh(['areas', 'parties'])->loadCount('results')));
    }

    public function removePhoto(Request $request, Election $election, Party $party): JsonResponse
    {
        $onBallot = $this->onBallot($request, $election, $party);
        if ($old = $onBallot->pivot->photo_path) {
            $election->parties()->updateExistingPivot($party->id, ['photo_path' => null]);
            $this->disk()->delete($old);
            $this->audit->log($request->user(), 'race.candidate_photo_removed', $election, ['party' => $party->code], $request->ip());
        }

        return response()->json($this->present($election->fresh(['areas', 'parties'])->loadCount('results')));
    }

    /** The photo itself, for anyone in the command room. */
    public function photo(Request $request, Election $election, Party $party): StreamedResponse
    {
        $path = $this->onBallot($request, $election, $party)->pivot->photo_path;
        abort_unless($path && $this->disk()->exists($path), 404);

        return $this->disk()->response($path, null, ['Cache-Control' => 'private, max-age=86400']);
    }

    /** The party as it stands on this race's ballot, with its candidate; 404 for anything else. */
    private function onBallot(Request $request, Election $election, Party $party): Party
    {
        abort_unless($election->campaign_id === $request->user()->campaign_id, 404);

        return $election->parties()->whereKey($party->id)->first() ?? abort(404);
    }

    private function disk(): Filesystem
    {
        return Storage::disk(config('pollproof.evidence_disk'));
    }

    private function validated(Request $request, ?Election $race = null): array
    {
        $campaignId = $request->user()->campaign_id;
        if (is_string($request->input('code'))) {
            $request->merge(['code' => strtoupper(trim($request->input('code')))]);
        }
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(Election::TYPES))],
            'name' => ['required', 'string', 'max:80'],
            'code' => ['required', 'string', 'max:12', 'regex:/^[A-Z0-9][A-Z0-9-]*$/',
                Rule::unique('elections', 'code')->where('campaign_id', $campaignId)->ignore($race?->id)],
            'election_date' => ['required', 'date'],
            'is_rerun' => ['boolean'],
            'announce' => ['boolean'],
            'lga_ids' => ['array'],
            'lga_ids.*' => ['integer', Rule::exists('lgas', 'id')->where('campaign_id', $campaignId)],
            'ward_ids' => ['array'],
            'ward_ids.*' => ['integer', Rule::exists('wards', 'id')->where('campaign_id', $campaignId)],
            'parties' => ['required', 'array', 'min:2'],
            'parties.*.id' => ['required', 'integer', Rule::exists('parties', 'id')->where('campaign_id', $campaignId)],
            'parties.*.candidate' => ['nullable', 'string', 'max:120'],
        ], [
            'code.regex' => 'Use capital letters, numbers and dashes only, e.g. SEN-E.',
            'parties.min' => 'Put at least two parties on the ballot.',
        ]);

        // Where each kind of race is fought.
        $area = Election::TYPES[$data['type']]['area'];
        $lgas = array_values(array_unique($data['lga_ids'] ?? []));
        $wards = array_values(array_unique($data['ward_ids'] ?? []));
        $fail = fn (string $m) => throw ValidationException::withMessages(['area' => $m]);
        match ($area) {
            'state' => [$lgas, $wards] = [[], []],
            'lgas' => $lgas ?: $fail('Choose the LGAs in this race.'),
            'lgas_or_wards' => ($lgas || $wards) ?: $fail('Choose the LGAs or wards in this constituency.'),
            'one_lga' => count($lgas) === 1 && ! $wards ?: $fail('Choose the one LGA this race is for.'),
            'one_ward' => count($wards) === 1 && ! $lgas ?: $fail('Choose the one ward this race is for.'),
        };
        if ($area !== 'lgas_or_wards' && $area !== 'one_ward') {
            $wards = [];
        }
        // A ward inside an LGA that is already in the race adds nothing.
        $wards = Ward::whereIn('id', $wards)->whereNotIn('lga_id', $lgas)->pluck('id')->all();
        $data['lga_ids'] = $lgas;
        $data['ward_ids'] = $wards;
        $parties = collect($data['parties']);
        if (! Party::whereIn('id', $parties->pluck('id'))->where('is_ours', true)->exists()) {
            throw ValidationException::withMessages(['parties' => 'Our party must be on the ballot.']);
        }

        return $data;
    }

    private function attributes(array $data): array
    {
        return [
            'type' => $data['type'],
            'name' => trim($data['name']),
            'code' => $data['code'],
            'election_date' => $data['election_date'],
            'is_rerun' => (bool) ($data['is_rerun'] ?? false),
            'announce' => (bool) ($data['announce'] ?? true),
        ];
    }

    private function syncAreaAndBallot(Election $race, array $data): void
    {
        $race->areas()->delete();
        foreach ($data['lga_ids'] as $lga) {
            $race->areas()->create(['lga_id' => $lga, 'ward_id' => null]);
        }
        foreach (Ward::whereIn('id', $data['ward_ids'])->get(['id', 'lga_id']) as $ward) {
            $race->areas()->create(['lga_id' => $ward->lga_id, 'ward_id' => $ward->id]);
        }

        $ballot = [];
        foreach (array_values($data['parties']) as $i => $p) {
            $ballot[$p['id']] = ['candidate' => trim((string) ($p['candidate'] ?? '')) ?: null, 'sort_order' => $i];
        }
        // Parties with votes recorded in this race stay on its ballot.
        $voted = DB::table('result_votes')->join('result_submissions as r', 'r.id', '=', 'result_votes.result_submission_id')
            ->where('r.election_id', $race->id)->distinct()->pluck('result_votes.party_id');
        $missing = $voted->diff(array_keys($ballot));
        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages(['parties' => Party::whereIn('id', $missing)->pluck('code')->implode(', ').' already have votes in this race and must stay on the ballot.']);
        }
        // A party taken off the ballot takes its candidate's photo with it.
        $dropped = $race->parties()->whereNotIn('parties.id', array_keys($ballot))->get()->pluck('pivot.photo_path')->filter()->all();
        $race->parties()->sync($ballot);
        $this->disk()->delete($dropped);
    }

    private function present(Election $e): array
    {
        return TallyService::race($e) + [
            'announce' => $e->announce,
            'sort_order' => $e->sort_order,
            'has_results' => ($e->results_count ?? 0) > 0,
            'lga_ids' => $e->areas->whereNull('ward_id')->pluck('lga_id')->values(),
            'ward_ids' => $e->areas->whereNotNull('ward_id')->pluck('ward_id')->values(),
            'parties' => $e->parties->map(fn ($p) => ['id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'is_ours' => $p->is_ours, 'candidate' => $p->pivot->candidate, 'photo' => $e->candidatePhotoUrl($p), 'logo' => $p->logo_url])->values(),
            'lga_names' => Lga::whereIn('id', $e->areas->pluck('lga_id')->unique())->orderBy('code')->pluck('name')->values(),
        ];
    }
}
