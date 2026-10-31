<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\PollingUnit;
use App\Models\User;
use App\Services\Csv;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Upload templates that already list the campaign's LGAs, wards and polling units, with whoever
 * holds each post now. Fill in or change the name and phone, leave a row empty to skip it, upload.
 * Codes and phones are written so Excel keeps them as text.
 */
class TemplateController extends Controller
{
    public function supervisors(Request $request): StreamedResponse
    {
        $campaign = $request->user()->campaign;
        $people = User::where('campaign_id', $campaign->id)->whereIn('role', [Role::LgaSupervisor, Role::WardSupervisor])->whereNull('deactivated_at')->get();
        $lgaSup = fn ($lgaId, $backup) => $people->first(fn ($u) => $u->role === Role::LgaSupervisor && $u->lga_id === $lgaId && (bool) $u->is_backup === $backup);
        $wardSup = $people->where('role', Role::WardSupervisor)->whereNotNull('ward_id')->keyBy('ward_id');
        $lgas = $campaign->lgas()->orderBy('code')->with(['wards' => fn ($q) => $q->orderBy('code')->withCount('pollingUnits')])->get();

        return $this->csv('supervisor-template', ['level', 'lga_code', 'lga_name', 'ward_code', 'ward_name', 'units', 'name', 'phone', 'backup'], function ($put) use ($lgas, $lgaSup, $wardSup) {
            foreach ($lgas as $lga) {
                foreach ([false, true] as $backup) {
                    $u = $lgaSup($lga->id, $backup);
                    $put(['lga', Csv::text($lga->code), $lga->name, '', '', $lga->wards->sum('polling_units_count'), $u?->name, Csv::text($u?->phone), $backup ? 'yes' : 'no']);
                }
                foreach ($lga->wards as $ward) {
                    $u = $wardSup->get($ward->id);
                    $put(['ward', Csv::text($lga->code), $lga->name, Csv::text($ward->code), $ward->name, $ward->polling_units_count, $u?->name, Csv::text($u?->phone), '']);
                }
            }
        });
    }

    public function agents(Request $request): StreamedResponse
    {
        $campaign = $request->user()->campaign;
        $units = PollingUnit::where('campaign_id', $campaign->id)->with(['ward.lga', 'agent'])->get()
            ->sortBy(fn ($u) => "{$u->ward->lga->code}/{$u->ward->code}/{$u->code}", SORT_NATURAL);
        $reserves = User::where('campaign_id', $campaign->id)->where('role', Role::Agent)->whereNull('deactivated_at')
            ->whereNull('polling_unit_id')->with('ward.lga')->orderBy('code')->get();

        return $this->csv('agent-roster-template', ['lga_code', 'lga_name', 'ward_code', 'ward_name', 'pu_code', 'pu_name', 'name', 'phone', 'reserve', 'code'], function ($put) use ($units, $reserves) {
            foreach ($units as $u) {
                $a = $u->agent?->deactivated_at ? null : $u->agent;
                $put([Csv::text($u->ward->lga->code), $u->ward->lga->name, Csv::text($u->ward->code), $u->ward->name, Csv::text($u->code), $u->name, $a?->name, Csv::text($a?->phone), '', $a?->code]);
            }
            // Reserves and agents without a unit, so the whole roster is in one file.
            foreach ($reserves as $r) {
                $put([Csv::text($r->ward?->lga->code), $r->ward?->lga->name, Csv::text($r->ward?->code), $r->ward?->name, '', '', $r->name, Csv::text($r->phone), $r->is_reserve ? 'yes' : '', $r->code]);
            }
        });
    }

    private function csv(string $name, array $header, callable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // so Excel reads names with accents correctly
            fputcsv($out, $header);
            $rows(fn (array $row) => fputcsv($out, $row));
            fclose($out);
        }, $name.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
