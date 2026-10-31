<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Services\AgentAppBuild;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/** The agent app for signed-in staff: version, download, a QR link for a phone, and updating the server's copy. */
class AgentAppController extends Controller
{
    public function __construct(private readonly AgentAppBuild $build) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'build' => $this->build->local(),
            'download_url' => url('/api/v1/agent-app/download'),
            // For the QR code: a phone that isn't signed in can still download for the next hour.
            'phone_url' => URL::temporarySignedRoute('agent-app.phone', now()->addHour()),
            'release_url' => $this->build->releaseUrl(),
            'can_update' => $request->user()->hasRole(Role::Dg, Role::SituationRoom),
        ]);
    }

    public function download(): BinaryFileResponse|RedirectResponse
    {
        return $this->file();
    }

    /** Reached from the QR code, by a signed link that expires. */
    public function phone(): BinaryFileResponse|RedirectResponse
    {
        return $this->file();
    }

    /** Fetch the latest GitHub build to this server. */
    public function update(Request $request, AuditLogger $audit): JsonResponse
    {
        try {
            $meta = $this->build->fetch();
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Could not get the latest build: '.$e->getMessage()], 502);
        }
        $audit->log($request->user(), 'agent_app.updated', null, ['version' => $meta['version'] ?? null, 'build' => $meta['build'] ?? null], $request->ip());

        return response()->json(['build' => $meta]);
    }

    private function file(): BinaryFileResponse|RedirectResponse
    {
        $meta = $this->build->local();
        if (! $meta) {
            return redirect()->away($this->build->releaseUrl()); // no copy here yet: the published build
        }
        $name = 'pollproof-agent-'.($meta['version'] ?? 'latest').'.apk';

        return response()->download($this->build->apkPath(), $name, ['Content-Type' => 'application/vnd.android.package-archive']);
    }
}
