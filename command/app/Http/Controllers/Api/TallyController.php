<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Races;
use App\Services\TallyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TallyController extends Controller
{
    /** One race's running totals (?election=id, or the primary race). */
    public function __invoke(Request $request, TallyService $tally): JsonResponse
    {
        return response()->json($tally->summary(Races::fromRequest($request)));
    }

    /** Every unit in the race with GPS and its votes, for the map's ward view. */
    public function points(Request $request, TallyService $tally): JsonResponse
    {
        return response()->json($tally->points(Races::fromRequest($request)));
    }

    /** Every race at a glance, for the "All races" board. */
    public function board(Request $request, TallyService $tally): JsonResponse
    {
        return response()->json(['races' => $tally->board($request->user()->campaign)]);
    }
}
