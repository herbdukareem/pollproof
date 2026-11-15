<?php

namespace App\Ai\Tools;

use App\Enums\Role;
use App\Models\CheckIn;
use App\Models\ResultSubmission;
use App\Models\User;
use App\Models\VideoClip;
use App\Support\Phone;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

class AgentLookup extends PollProofTool
{
    protected string $name = 'agent_lookup';

    protected ?string $description = "A field person (agent, collation agent or supervisor) by code, name or phone: their unit or area, onboarding (invited, app installed, practice, trained, confirmed), whether their phone is registered, today's check-ins with distance and accuracy, results sent and videos. Use it to diagnose why someone can't check in, sign in or send.";

    protected function properties(): array
    {
        return [new ToolProperty('person', PropertyType::STRING, 'Agent code (AG-00127), name or phone number.', true)];
    }

    public function __invoke(string $person): array
    {
        $q = trim($person);
        $phone = Phone::normalize($q);
        $field = [Role::Agent, Role::CollationAgent, Role::WardSupervisor, Role::LgaSupervisor];
        $visibleUnits = $this->units()->select('polling_units.id');
        $people = User::where('campaign_id', $this->campaignId())->whereIn('role', $field)
            ->where(fn ($w) => $w->where('code', strtoupper($q))->orWhereLike('name', "%{$q}%")->when($phone && strlen($phone) >= 10, fn ($x) => $x->orWhere('phone', $phone)))
            ->when(in_array($this->user->role, [Role::WardSupervisor, Role::LgaSupervisor], true), fn ($w) => $w->where(fn ($x) => $x->whereIn('polling_unit_id', $visibleUnits)
                ->orWhere('ward_id', $this->user->ward_id ?? 0)->orWhere('lga_id', $this->user->lga_id ?? 0)))
            ->with(['pollingUnit.ward.lga', 'ward.lga', 'lga'])->withCount('devices')->take(5)->get();
        if ($people->isEmpty()) {
            return $this->notFound('person');
        }

        return ['people' => $people->map(fn (User $a) => [
            'code' => $a->code, 'name' => $a->name, 'role' => $a->role->label(), 'phone' => $this->phone($a->phone),
            'active' => $a->isActive(), 'reserve' => (bool) $a->is_reserve, 'said_unavailable' => $a->unavailable_at !== null,
            'unit' => $a->pollingUnit ? "{$a->pollingUnit->code} {$a->pollingUnit->name}, {$a->pollingUnit->ward->name}, {$a->pollingUnit->ward->lga->name}" : null,
            'area' => $a->pollingUnit ? null : trim(($a->ward?->name ? "{$a->ward->name} ward, " : '').($a->ward?->lga?->name ?? $a->lga?->name ?? ''), ', '),
            'onboarding' => User::ONBOARDING_STEPS[$a->onboardingStep()], 'quiz_score' => $a->quiz_score,
            'phone_registered_for_signing' => $a->devices_count > 0,
            'check_ins_today' => CheckIn::where('user_id', $a->id)->today()->latest('id')->take(5)->get()->map(fn ($c) => [
                'status' => $c->status, 'counts' => $c->counts(), 'distance_m' => $c->distance_m, 'accuracy_m' => $c->accuracy_m, 'channel' => $c->channel, 'at' => $c->captured_at?->toIso8601String(),
            ])->all(),
            'results_sent' => ResultSubmission::where('user_id', $a->id)->where('is_current', true)->with('election:id,name')->get()->map(fn ($r) => "{$r->election?->name}: {$r->status} by {$r->channel}")->all(),
            'videos_sent' => VideoClip::where('user_id', $a->id)->whereNotNull('completed_at')->count(),
            'videos_still_uploading' => VideoClip::where('user_id', $a->id)->whereNull('completed_at')->count(),
        ])->all(), 'rules' => [
            'check_in_radius_m' => $this->user->campaign->geofence_radius_m, 'max_gps_accuracy_m' => $this->user->campaign->max_gps_accuracy_m,
        ]];
    }
}
