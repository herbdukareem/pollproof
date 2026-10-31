<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'badge_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'is_reserve' => 'boolean',
            'is_backup' => 'boolean',
            'must_change_password' => 'boolean',
            'deactivated_at' => 'datetime',
            'last_active_at' => 'datetime',
            'invited_at' => 'datetime',
            'practice_passed_at' => 'datetime',
            'trained_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'unavailable_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function pollingUnit(): BelongsTo
    {
        return $this->belongsTo(PollingUnit::class);
    }

    public function ward(): BelongsTo
    {
        return $this->belongsTo(Ward::class);
    }

    public function lga(): BelongsTo
    {
        return $this->belongsTo(Lga::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function checkIns(): HasMany
    {
        return $this->hasMany(CheckIn::class);
    }

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null;
    }

    /** Staff in a campaign that requires two-step sign-in, who haven't set it up yet. */
    public function needsTwoFactorSetup(): bool
    {
        return ! $this->role->isField() && ! $this->hasTwoFactor() && (bool) $this->campaign?->require_two_factor;
    }

    public function isActive(): bool
    {
        return $this->deactivated_at === null;
    }

    /**
     * Furthest onboarding step reached by a field user, from 0 (nothing) to 5 (confirmed for election day).
     * Steps: invited, app installed (a signing key is registered), practice result sent, trained, confirmed.
     */
    public function onboardingStep(): int
    {
        // Load with withCount('devices') when listing many users.
        $hasDevice = $this->devices_count !== null ? $this->devices_count > 0 : $this->devices()->exists();
        $steps = [
            $this->invited_at !== null || $hasDevice,
            $hasDevice,
            $this->practice_passed_at !== null,
            $this->trained_at !== null,
            $this->confirmed_at !== null && $this->unavailable_at === null,
        ];
        $n = 0;
        while ($n < count($steps) && $steps[$n]) {
            $n++;
        }

        return $n;
    }

    public const ONBOARDING_STEPS = ['Not invited', 'Invited', 'App installed', 'Practice sent', 'Trained', 'Confirmed'];

    public function hasRole(Role ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }
}
