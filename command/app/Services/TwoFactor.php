<?php

namespace App\Services;

use App\Models\User;
use App\Support\Totp;
use Illuminate\Support\Str;

/** Two-step sign-in for command-room staff: an authenticator-app code, or a one-time recovery code. */
final class TwoFactor
{
    /** Starts (or restarts) enrolment. Nothing is enforced until the person confirms a code from their app. */
    public function start(User $user): array
    {
        $secret = Totp::newSecret();
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null, 'two_factor_last_step' => null])->save();

        return ['secret' => $secret, 'uri' => Totp::uri($secret, $user->email ?? $user->code ?? (string) $user->id)];
    }

    /** @return list<string>|null recovery codes, or null if the code is wrong */
    public function confirm(User $user, string $code): ?array
    {
        if (! $user->two_factor_secret || ! ($step = Totp::verify($user->two_factor_secret, $code))) {
            return null;
        }
        $codes = $this->newRecoveryCodes();
        $user->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_last_step' => $step, 'two_factor_recovery_codes' => array_map(fn ($c) => hash('sha256', $c), $codes)])->save();

        return $codes;
    }

    /** Checks an app code or a recovery code; a recovery code works once. */
    public function check(User $user, string $code): bool
    {
        if (! $user->hasTwoFactor()) {
            return false;
        }
        $code = trim($code);
        if ($step = Totp::verify($user->two_factor_secret, $code, $user->two_factor_last_step)) {
            $user->forceFill(['two_factor_last_step' => $step])->save();

            return true;
        }

        $hash = hash('sha256', strtolower(str_replace(' ', '', $code)));
        $remaining = $user->two_factor_recovery_codes ?? [];
        foreach ($remaining as $i => $stored) {
            if (hash_equals($stored, $hash)) {
                unset($remaining[$i]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($remaining)])->save();

                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = $this->newRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => array_map(fn ($c) => hash('sha256', $c), $codes)])->save();

        return $codes;
    }

    public function disable(User $user): void
    {
        $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null, 'two_factor_last_step' => null])->save();
    }

    /** @return list<string> */
    private function newRecoveryCodes(): array
    {
        return collect(range(1, 8))->map(fn () => strtolower(Str::random(5).'-'.Str::random(5)))->all();
    }
}
