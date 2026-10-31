<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AgentActivity;
use App\Services\AuditLogger;
use App\Services\StipendService;
use App\Support\ListSort;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StipendController extends Controller
{
    public function index(Request $request, StipendService $stipends): JsonResponse
    {
        $sorts = [
            'latest' => [fn ($r) => $r['activity'], 'desc'],
            'code' => [fn ($r) => $r['agent_code'], 'asc'],
            'unit' => [fn ($r) => $r['polling_unit'], 'asc'],
            'checked' => [fn ($r) => $r['checked_in_at'], 'desc'],
            'result' => [fn ($r) => $r['result_at'], 'desc'],
            'status' => [fn ($r) => $r['eligible'] ? 'Eligible' : $r['reason'], 'asc'],
        ];
        $data = $request->validate(ListSort::rules($sorts));
        $activity = app(AgentActivity::class)->sequence($request->user()->campaign_id);
        $rows = collect($stipends->eligibility($request->user()->campaign))->map(fn ($r) => $r + ['activity' => $activity->get($r['agent_id'])]);

        return response()->json([
            'eligible' => $rows->where('eligible', true)->count(),
            'on_hold' => $rows->where('eligible', false)->count(),
            'hold_reasons' => $rows->where('eligible', false)->countBy('reason'),
            'agents' => ListSort::apply($rows, $data, $sorts),
        ]);
    }

    /** CSV of eligible agents for the campaign's own bank payout. Every export is logged. */
    public function export(Request $request, StipendService $stipends, AuditLogger $audit): StreamedResponse
    {
        $user = $request->user();
        $rows = collect($stipends->eligibility($user->campaign))->where('eligible', true)->values();
        $audit->log($user, 'stipends.exported', null, ['agents' => $rows->count()], $request->ip());

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['agent_code', 'name', 'polling_unit']);
            foreach ($rows as $r) {
                fputcsv($out, [$r['agent_code'], $r['name'], $r['polling_unit']]);
            }
            fclose($out);
        }, 'stipend-eligible-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }
}
