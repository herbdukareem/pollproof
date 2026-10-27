<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    /** The phone generates an Ed25519 key pair on first launch and registers the public half here. */
    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'public_key' => ['required', 'string', 'max:64', function ($attr, $value, $fail) {
                $raw = base64_decode($value, true);
                if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                    $fail('The public key must be a base64-encoded Ed25519 key.');
                }
            }],
            'label' => ['nullable', 'string', 'max:100'],
        ]);
        $device = $request->user()->devices()->firstOrCreate(['public_key' => $data['public_key']], ['label' => $data['label'] ?? null]);
        $audit->log($request->user(), 'device.registered', $device, [], $request->ip());

        return response()->json(['id' => $device->id, 'public_key' => $device->public_key], 201);
    }
}
