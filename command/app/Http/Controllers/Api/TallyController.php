<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TallyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TallyController extends Controller
{
    public function __invoke(Request $request, TallyService $tally): JsonResponse
    {
        return response()->json($tally->summary($request->user()->campaign));
    }

    /** Every unit with GPS and its votes, for the map's ward view. */
    public function points(Request $request, TallyService $tally): JsonResponse
    {
        return response()->json($tally->points($request->user()->campaign));
    }
}
