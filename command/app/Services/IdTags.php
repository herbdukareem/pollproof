<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\CheckIn;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Printed ID tags for agents and supervisors. The QR code on a tag holds a link with the person's
 * secret tag code; anyone who scans it sees their photo, name and whether they are valid now.
 * Reissuing the code cancels every tag already printed for that person.
 */
final class IdTags
{
    public function disk(): Filesystem
    {
        return Storage::disk(config('pollproof.evidence_disk'));
    }

    public function token(User $u): string
    {
        if (! $u->badge_token) {
            $u->forceFill(['badge_token' => Str::random(32)])->save();
        }

        return $u->badge_token;
    }

    public function reissue(User $u): string
    {
        $u->forceFill(['badge_token' => Str::random(32)])->save();

        return $u->badge_token;
    }

    public function verifyUrl(User $u): string
    {
        return url('/tag/'.$this->token($u)); // /verify is the results queue
    }

    public function savePhoto(User $u, UploadedFile $file): void
    {
        $old = $u->photo_path;
        $path = $file->storeAs("campaigns/{$u->campaign_id}/people", $u->id.'-'.Str::random(8).'.'.($file->extension() ?: 'jpg'), config('pollproof.evidence_disk'));
        $u->forceFill(['photo_path' => $path])->save();
        if ($old && $old !== $path) {
            $this->disk()->delete($old);
        }
    }

    /** Their photo, or failing that the selfie from their latest accepted check-in. */
    public function photoPath(User $u): ?string
    {
        return $u->photo_path ?? CheckIn::where('user_id', $u->id)->whereNotNull('selfie_path')
            ->whereIn('status', [CheckIn::VERIFIED, CheckIn::APPROVED])->latest('id')->value('selfie_path');
    }

    /**
     * Whether the person behind a tag may act for the campaign right now.
     *
     * @return array{valid: bool, reason: string}
     */
    public function status(User $u): array
    {
        return match (true) {
            $u->deactivated_at !== null => ['valid' => false, 'reason' => 'This person has been deactivated. Do not accept this tag.'],
            ! $u->hasRole(...PeopleMover::MOVABLE) => ['valid' => false, 'reason' => 'This tag is not for a field agent or supervisor.'],
            $u->campaign->isDeclared() => ['valid' => false, 'reason' => 'The election has been declared. Field tags are no longer in use.'],
            $u->hasRole(Role::Agent) && ! $u->polling_unit_id && ! $u->is_reserve => ['valid' => false, 'reason' => 'This agent has no polling unit at the moment.'],
            $u->hasRole(Role::WardSupervisor) && ! $u->ward_id, $u->hasRole(Role::LgaSupervisor) && ! $u->lga_id => ['valid' => false, 'reason' => 'This supervisor has no post at the moment.'],
            default => ['valid' => true, 'reason' => 'Registered and active with the campaign.'],
        };
    }

    /** What a tag and the verification page show about a person. */
    public function card(User $u): array
    {
        $u->loadMissing(['pollingUnit.ward.lga', 'ward.lga', 'lga', 'campaign']);
        $ward = $u->pollingUnit?->ward ?? $u->ward;

        return [
            'id' => $u->id,
            'code' => $u->code,
            'name' => $u->name,
            'role' => $u->role->value,
            'role_label' => $u->hasRole(Role::Agent) && $u->is_reserve && ! $u->polling_unit_id ? 'Reserve Agent' : $u->role->label(),
            'unit' => $u->pollingUnit ? ['code' => $u->pollingUnit->code, 'name' => $u->pollingUnit->name] : null,
            'ward' => $ward?->name,
            'lga' => $ward?->lga?->name ?? $u->lga?->name,
            'campaign' => ['name' => $u->campaign->name, 'state' => $u->campaign->state_name, 'election_date' => $u->campaign->election_date?->toDateString()],
            'has_photo' => $this->photoPath($u) !== null,
        ];
    }

    public function signedPhotoUrl(User $u): ?string
    {
        return $this->photoPath($u) ? URL::temporarySignedRoute('id-tag.photo', now()->addMinutes(30), ['token' => $this->token($u)]) : null;
    }
}
