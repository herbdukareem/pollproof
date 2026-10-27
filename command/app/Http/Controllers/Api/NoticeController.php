<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Notice;
use App\Models\NotificationRule;
use App\Services\AlertNotifier;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NoticeController extends Controller
{
    /** The signed-in person's notifications, newest first. Used by the web bell and the agent app. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'unread' => Notice::where('user_id', $user->id)->whereNull('read_at')->count(),
            'data' => Notice::where('user_id', $user->id)->latest('id')->limit(40)->get(['id', 'alert_id', 'severity', 'title', 'body', 'url', 'escalation', 'test', 'read_at', 'created_at']),
        ]);
    }

    public function read(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['nullable', 'array'], 'ids.*' => ['integer']]);
        Notice::where('user_id', $request->user()->id)->whereNull('read_at')
            ->when($data['ids'] ?? null, fn ($q, $ids) => $q->whereIn('id', $ids))
            ->update(['read_at' => now()]);

        return $this->index($request);
    }

    public function rules(Request $request): JsonResponse
    {
        $campaign = $request->user()->campaign;
        NotificationRule::seedDefaults($campaign); // picks up rule types added after the campaign was created

        return response()->json([
            'roles' => collect(Role::cases())->reject(fn ($r) => $r === Role::Agent)->map(fn ($r) => ['value' => $r->value, 'label' => $r->label()])->values(),
            'sms_driver' => config('pollproof.sms_driver'),
            'mail_driver' => config('mail.default'),
            'data' => $campaign->notificationRules()->orderBy('id')->get()->map(fn ($r) => [
                'id' => $r->id, 'event' => $r->event, 'label' => $r->label(), 'recipients' => $r->recipients, 'channels' => $r->channels,
                'escalate_after_minutes' => $r->escalate_after_minutes, 'escalate_to' => $r->escalate_to ?? [], 'enabled' => $r->enabled,
            ]),
        ]);
    }

    public function updateRule(Request $request, NotificationRule $rule, AuditLogger $audit): JsonResponse
    {
        abort_unless($rule->campaign_id === $request->user()->campaign_id, 404);
        $roles = array_map(fn ($r) => $r->value, array_filter(Role::cases(), fn ($r) => $r !== Role::Agent));
        $data = $request->validate([
            'recipients' => ['sometimes', 'array'], 'recipients.*' => [Rule::in($roles)],
            'channels' => ['sometimes', 'array'], 'channels.*' => [Rule::in(['app', 'sms', 'email'])],
            'escalate_after_minutes' => ['sometimes', 'nullable', 'integer', 'between:1,1440'],
            'escalate_to' => ['sometimes', 'array'], 'escalate_to.*' => [Rule::in($roles)],
            'enabled' => ['sometimes', 'boolean'],
        ]);
        $rule->update($data);
        $audit->log($request->user(), 'notifications.rule_updated', $rule, $data, $request->ip());

        return $this->rules($request);
    }

    /** One sample of every rule to the person asking, on that rule's channels, to check wording and delivery. */
    public function test(Request $request, AlertNotifier $notifier): JsonResponse
    {
        $user = $request->user();
        $rules = $user->campaign->notificationRules()->where('enabled', true)->get();
        foreach ($rules as $rule) {
            $notifier->deliver(collect([$user]), $user->campaign_id, "Test: {$rule->label()}", 'This is a test of how PollProof tells you about this kind of problem. Nothing has happened.', 'low', null, $rule->channels, test: true);
        }

        return response()->json(['sent' => $rules->count()]);
    }
}
