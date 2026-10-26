<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\StructureImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StructureController extends Controller
{
    public function import(Request $request, StructureImporter $importer, AuditLogger $audit): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:20480']]);
        $summary = $importer->import($request->user()->campaign, $request->file('file')->getRealPath());
        $audit->log($request->user(), 'structure.imported', null, $summary, $request->ip());

        return response()->json($summary);
    }
}
