<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    public function password(Request $request, AuditLogger $audit): JsonResponse
    {
        $user = $request->user();
        abort_if($user->role->isField(), 403, 'Field staff sign in with a code, not a password.');
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
        ]);
        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Your current password is not right.']);
        }
        if (Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => 'Choose a password you have not used here before.']);
        }
        $user->update(['password' => $data['password'], 'must_change_password' => false]);
        // Other devices signed in as this person are signed out.
        $user->tokens()->when($user->currentAccessToken()?->id ?? null, fn ($q, $id) => $q->where('id', '!=', $id))->delete();
        $audit->log($user, 'auth.password_changed', null, [], $request->ip());

        return response()->json(['message' => 'Password changed.']);
    }
}
