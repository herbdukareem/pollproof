<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\User;
use App\Services\AttendanceConfirmer;
use App\Services\AuditLogger;
use App\Services\ResultSigner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The agent app's onboarding: practice run, training quiz and the eve-of-election confirmation.
 * Each step is recorded on the agent so the command room can see who is ready.
 */
class OnboardingController extends Controller
{
    /** The unit code practice results are signed for, so a practice signature can never count as a real result. */
    public const PRACTICE_UNIT = 'PRACTICE';

    public function __construct(private readonly AuditLogger $audit) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user()->loadCount('devices');

        return response()->json([
            'step' => $user->onboardingStep(),
            'step_label' => User::ONBOARDING_STEPS[$user->onboardingStep()],
            'practice_passed_at' => $user->practice_passed_at,
            'quiz_score' => $user->quiz_score,
            'quiz_pass_mark' => config('pollproof.quiz_pass_mark'),
            'trained_at' => $user->trained_at,
            'confirmed_at' => $user->confirmed_at,
            'unavailable_at' => $user->unavailable_at,
            'election_date' => $user->campaign->election_date->toDateString(),
            'practice_unit' => self::PRACTICE_UNIT,
            'quiz' => collect(config('pollproof_quiz'))->map(fn ($q) => collect($q)->only(['id', 'question', 'options'])),
        ]);
    }

    /** A practice result, signed exactly like a real one but for the unit code PRACTICE. Nothing is counted. */
    public function practice(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'device_id' => ['required', 'integer'],
            'signature' => ['required', 'string', 'max:200'],
            'captured_at' => ['required', 'string', 'max:40'],
            'accredited' => ['required', 'integer', 'min:0'],
            'rejected' => ['required', 'integer', 'min:0'],
            'votes' => ['required', 'array', 'min:1'],
            'votes.*' => ['integer', 'min:0'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'photo_sha256' => ['nullable', 'string', 'size:64'],
        ]);
        $device = Device::where('id', $data['device_id'])->where('user_id', $user->id)->whereNull('revoked_at')->first();
        abort_unless($device, 422, 'This device is not registered to you.');

        $canonical = ResultSigner::canonical(self::PRACTICE_UNIT, $data['accredited'], $data['rejected'], $data['votes'], $data['photo_sha256'] ?? null,
            isset($data['latitude']) ? (float) $data['latitude'] : null, isset($data['longitude']) ? (float) $data['longitude'] : null, $data['captured_at'], null);
        if (! ResultSigner::verify($canonical, $data['signature'], $device->public_key)) {
            return response()->json(['passed' => false, 'message' => 'The signature did not check out. Update the app and try again.'], 422);
        }

        $user->update(['practice_passed_at' => now()]);
        $this->audit->log($user, 'onboarding.practice', null, [], $request->ip());

        return response()->json(['passed' => true, 'fingerprint' => strtoupper(substr(ResultSigner::hash($canonical), 0, 8))]);
    }

    public function quiz(Request $request): JsonResponse
    {
        $user = $request->user();
        $questions = collect(config('pollproof_quiz'));
        $data = $request->validate(['answers' => ['required', 'array'], 'answers.*' => ['string', 'max:2']]);

        $marked = $questions->map(fn ($q) => [
            'id' => $q['id'],
            'correct' => ($data['answers'][$q['id']] ?? null) === $q['answer'],
            'answer' => $q['answer'],
            'explain' => $q['explain'],
        ]);
        $score = $marked->where('correct', true)->count();
        $passed = $score >= config('pollproof.quiz_pass_mark');
        $user->update(['quiz_score' => max($score, (int) $user->quiz_score)] + ($passed && ! $user->trained_at ? ['trained_at' => now()] : []));
        $this->audit->log($user, 'onboarding.quiz', null, ['score' => $score, 'passed' => $passed], $request->ip());

        return response()->json(['score' => $score, 'out_of' => $questions->count(), 'passed' => $passed, 'questions' => $marked->values()]);
    }

    /** "I'm ready for tomorrow" or "I can't make it". A no tells the agent's supervisors to find a reserve. */
    public function confirm(Request $request, AttendanceConfirmer $confirmer): JsonResponse
    {
        $data = $request->validate(['available' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:255']]);

        return response()->json($confirmer->confirm($request->user(), $data['available'], $data['note'] ?? null, $request->ip()));
    }
}
