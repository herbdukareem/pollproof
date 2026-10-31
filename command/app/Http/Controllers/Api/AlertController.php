<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\NotificationRule;
use App\Services\AlertNotifier;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlertController extends Controller
{
    /**
     * Alerts for the command room, newest first by default.
     * Besides the page it returns counts for each filter (status, severity, type), each counted
     * with the other filters applied, so the filter chips show what choosing them would give.
     */
    public function index(Request $request, AlertNotifier $notifier): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:open,acknowledged,resolved,all'],
            'severity' => ['nullable', 'in:high,medium,low'],
            'type' => ['nullable', 'string', 'max:40'],
            'search' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', 'in:latest,severity'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $status = $data['status'] ?? 'open';
        $campaign = $request->user()->campaign_id;

        $filter = function (array $skip = []) use ($data, $status, $campaign): Builder {
            return Alert::query()->where('campaign_id', $campaign)
                ->when(! in_array('status', $skip) && $status !== 'all', fn ($q) => $status === 'open' ? $q->where('status', '!=', 'resolved') : $q->where('status', $status))
                ->when(! in_array('severity', $skip) ? $data['severity'] ?? null : null, fn ($q, $v) => $q->where('severity', $v))
                ->when(! in_array('type', $skip) ? $data['type'] ?? null : null, fn ($q, $v) => $q->where('type', $v))
                ->when($data['search'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('title', 'like', "%{$v}%")->orWhere('detail', 'like', "%{$v}%")));
        };

        $page = $filter()
            ->with(['subject', 'resolver:id,name'])
            ->when(($data['sort'] ?? 'latest') === 'severity', fn ($q) => $q->orderByRaw("CASE severity WHEN 'high' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END"))
            ->latest('id')
            ->paginate((int) ($data['per_page'] ?? 25));

        $page->getCollection()->transform(fn (Alert $a) => [
            'id' => $a->id,
            'type' => $a->type,
            'label' => NotificationRule::DEFAULTS[$a->type][0] ?? ucfirst(str_replace('_', ' ', $a->type)),
            'severity' => $a->severity,
            'title' => $a->title,
            'detail' => $a->detail,
            'status' => $a->status,
            'link' => $notifier->link($a),
            'created_at' => $a->created_at,
            'updated_at' => $a->updated_at,
            'resolved_at' => $a->resolved_at,
            'resolved_by' => $a->resolver?->name,
        ]);

        $statusCounts = $filter(['status'])->selectRaw("SUM(status != 'resolved') open_count, SUM(status = 'resolved') resolved_count, COUNT(*) all_count")->first();
        $severity = $filter(['severity'])->selectRaw('severity, COUNT(*) c')->groupBy('severity')->pluck('c', 'severity');
        $types = $filter(['type'])->selectRaw('type, COUNT(*) c')->groupBy('type')->orderByDesc('c')->pluck('c', 'type');

        return response()->json($page->toArray() + ['counts' => [
            'status' => ['open' => (int) $statusCounts->open_count, 'resolved' => (int) $statusCounts->resolved_count, 'all' => (int) $statusCounts->all_count],
            'severity' => ['high' => (int) ($severity['high'] ?? 0), 'medium' => (int) ($severity['medium'] ?? 0), 'low' => (int) ($severity['low'] ?? 0)],
            'types' => $types->map(fn ($c, $t) => ['type' => $t, 'label' => NotificationRule::DEFAULTS[$t][0] ?? ucfirst(str_replace('_', ' ', $t)), 'count' => (int) $c])->values(),
        ]]);
    }

    public function update(Request $request, Alert $alert, AuditLogger $audit): JsonResponse
    {
        $user = $request->user();
        abort_unless($alert->campaign_id === $user->campaign_id, 404);
        $data = $request->validate(['status' => ['required', 'in:acknowledged,resolved']]);
        $alert->update([
            'status' => $data['status'],
            'resolved_by' => $data['status'] === 'resolved' ? $user->id : null,
            'resolved_at' => $data['status'] === 'resolved' ? now() : null,
        ]);
        $audit->log($user, 'alert.'.$data['status'], $alert, [], $request->ip());

        return response()->json($alert);
    }
}
