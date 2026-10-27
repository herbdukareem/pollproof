<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\StructureImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StructureController extends Controller
{
    /** LGAs with their wards and unit counts, for pickers in the command room. */
    public function index(Request $request): JsonResponse
    {
        $campaign = $request->user()->campaign;
        $lgas = $campaign->lgas()->orderBy('code')
            ->with(['wards' => fn ($q) => $q->orderBy('code')->withCount('pollingUnits')])
            ->get();

        return response()->json([
            'campaign' => $campaign->only(['id', 'name', 'state_name', 'election_date']),
            'parties' => $campaign->parties()->get(['id', 'code', 'name', 'is_ours']),
            'lgas' => $lgas->map(fn ($l) => [
                'id' => $l->id, 'code' => $l->code, 'name' => $l->name,
                'wards' => $l->wards->map(fn ($w) => ['id' => $w->id, 'code' => $w->code, 'name' => $w->name, 'units' => $w->polling_units_count]),
            ]),
        ]);
    }

    public function import(Request $request, StructureImporter $importer, AuditLogger $audit): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:20480']]);
        $summary = $importer->import($request->user()->campaign, $request->file('file')->getRealPath());
        $audit->log($request->user(), 'structure.imported', null, $summary, $request->ip());

        return response()->json($summary);
    }
}
