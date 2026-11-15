<?php

namespace App\Ai\Tools;

use App\Enums\Role;
use App\Models\Election;
use App\Models\Lga;
use App\Models\PollingUnit;
use App\Models\User;
use App\Models\Ward;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Builder;
use NeuronAI\Tools\Tool;

/**
 * A tool Ask PollProof can use to look something up. Every tool works for one signed-in person:
 * it sees their campaign only, and a ward or LGA supervisor sees only their own area, exactly as
 * in the rest of the command room. Tools read; none of them changes anything.
 */
abstract class PollProofTool extends Tool
{
    public function __construct(protected User $user) {}

    protected function campaignId(): int
    {
        return $this->user->campaign_id;
    }

    /** Polling units this person may see. */
    protected function units(): Builder
    {
        return PollingUnit::where('polling_units.campaign_id', $this->campaignId())
            ->when($this->user->role === Role::WardSupervisor, fn ($q) => $q->where('ward_id', $this->user->ward_id))
            ->when($this->user->role === Role::LgaSupervisor, fn ($q) => $q->whereHas('ward', fn ($w) => $w->where('lga_id', $this->user->lga_id)));
    }

    /** A race by code or name, or the campaign's main race on the current election day. */
    protected function race(?string $name): ?Election
    {
        $campaign = $this->user->campaign;
        if (! $name) {
            return $campaign->primaryElection();
        }
        $name = trim($name);

        return Election::where('campaign_id', $campaign->id)
            ->where(fn ($q) => $q->where('code', strtoupper($name))->orWhereLike('name', "%{$name}%"))
            ->orderByRaw('code = ? desc', [strtoupper($name)])->first();
    }

    protected function lga(?string $name): ?Lga
    {
        return $name ? Lga::where('campaign_id', $this->campaignId())
            ->where(fn ($q) => $q->whereLike('name', trim($name))->orWhere('code', trim($name)))->first()
            ?? Lga::where('campaign_id', $this->campaignId())->whereLike('name', '%'.trim($name).'%')->first() : null;
    }

    protected function ward(?string $name, ?Lga $lga): ?Ward
    {
        return $name ? Ward::where('campaign_id', $this->campaignId())->when($lga, fn ($q) => $q->where('lga_id', $lga->id))
            ->where(fn ($q) => $q->whereLike('name', '%'.trim($name).'%')->orWhere('code', trim($name)))->first() : null;
    }

    protected function phone(?string $phone): ?string
    {
        return $this->user->role->canSeePhones() ? $phone : Phone::masked($phone);
    }

    protected function notFound(string $what): array
    {
        return ['found' => false, 'message' => "No {$what} matches that in this campaign".($this->user->role->isField() || in_array($this->user->role, [Role::WardSupervisor, Role::LgaSupervisor], true) ? ' or in your area' : '').'.'];
    }
}
