<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\IdTags;
use App\Services\PeopleMover;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Printed ID tags and the public page their QR code opens. */
class IdTagController extends Controller
{
    public function __construct(private readonly IdTags $tags, private readonly AuditLogger $audit) {}

    /** Public: what anyone scanning a tag sees. Only the tag's secret code finds the person. */
    public function verify(string $token): JsonResponse
    {
        $person = strlen($token) >= 20 ? User::where('badge_token', $token)->first() : null;
        if (! $person) {
            return response()->json(['found' => false, 'valid' => false, 'reason' => 'This is not a current PollProof tag. It may be a copy, or it was cancelled when a new tag was issued.'], 404);
        }

        return response()->json(['found' => true, 'checked_at' => now()->toIso8601String(), 'photo_url' => $this->tags->signedPhotoUrl($person)]
            + $this->tags->status($person) + $this->tags->card($person));
    }

    /** Public, by a short-lived signed link from verify(). */
    public function publicPhoto(string $token): StreamedResponse
    {
        $person = User::where('badge_token', $token)->firstOrFail();

        return $this->photoResponse($person);
    }

    /** For signed-in staff: a person's photo. */
    public function photo(Request $request, User $person): StreamedResponse
    {
        abort_unless($person->campaign_id === $request->user()->campaign_id, 404);

        return $this->photoResponse($person);
    }

    public function uploadPhoto(Request $request, User $person): JsonResponse
    {
        abort_unless($person->campaign_id === $request->user()->campaign_id && $person->hasRole(...PeopleMover::MOVABLE), 404);
        $request->validate(['photo' => ['required', 'image', 'max:5120']]);
        $this->tags->savePhoto($person, $request->file('photo'));
        $this->audit->log($request->user(), 'person.photo_updated', $person, [], $request->ip());

        return response()->json(['id' => $person->id, 'has_photo' => true]);
    }

    /** Cancel every tag printed for this person; the next print has a new QR code. */
    public function reissue(Request $request, User $person): JsonResponse
    {
        abort_unless($person->campaign_id === $request->user()->campaign_id && $person->hasRole(...PeopleMover::MOVABLE), 404);
        $this->tags->reissue($person);
        $this->audit->log($request->user(), 'person.tag_reissued', $person, [], $request->ip());

        return response()->json(['id' => $person->id, 'verify_url' => $this->tags->verifyUrl($person)]);
    }

    /** Tags to print: chosen people, or everyone in an LGA or ward, agents or supervisors. */
    public function print(Request $request): JsonResponse
    {
        $user = $request->user();
        // ids as 1,2,3 (or ids[]=1&ids[]=2)
        if (is_string($request->input('ids'))) {
            $request->merge(['ids' => array_values(array_filter(explode(',', $request->input('ids')), 'strlen'))]);
        }
        $data = $request->validate([
            'ids' => ['nullable', 'array', 'max:500'], 'ids.*' => ['integer'],
            'role' => ['nullable', 'in:agents,supervisors'],
            'lga_id' => ['nullable', 'integer'], 'ward_id' => ['nullable', 'integer'],
        ]);
        $roles = ($data['role'] ?? 'agents') === 'supervisors' ? [Role::WardSupervisor, Role::LgaSupervisor] : [Role::Agent];
        $people = User::where('campaign_id', $user->campaign_id)->whereNull('deactivated_at')
            ->when($data['ids'] ?? null, fn ($q, $ids) => $q->whereIn('id', $ids)->whereIn('role', array_map(fn ($r) => $r->value, PeopleMover::MOVABLE)),
                fn ($q) => $q->whereIn('role', array_map(fn ($r) => $r->value, $roles))
                    ->when($data['ward_id'] ?? null, fn ($w, $id) => $w->where(fn ($x) => $x->where('ward_id', $id)->orWhereHas('pollingUnit', fn ($u) => $u->where('ward_id', $id))))
                    ->when($data['lga_id'] ?? null, fn ($w, $id) => $w->where(fn ($x) => $x->where('lga_id', $id)->orWhereHas('ward', fn ($y) => $y->where('lga_id', $id))->orWhereHas('pollingUnit.ward', fn ($y) => $y->where('lga_id', $id)))))
            ->with(['pollingUnit.ward.lga', 'ward.lga', 'lga', 'campaign'])
            ->orderBy('code')->limit(500)->get();
        $this->audit->log($user, 'id_tags.printed', null, ['people' => $people->count()], $request->ip());

        return response()->json(['data' => $people->map(fn (User $p) => $this->tags->card($p) + [
            'verify_url' => $this->tags->verifyUrl($p),
            'photo_url' => $this->tags->signedPhotoUrl($p),
        ])]);
    }

    private function photoResponse(User $person): StreamedResponse
    {
        $path = $this->tags->photoPath($person);
        abort_unless($path && $this->tags->disk()->exists($path), 404);

        return $this->tags->disk()->response($path, null, ['Cache-Control' => 'private, max-age=600']);
    }
}
