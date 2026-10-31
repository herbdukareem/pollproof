<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\BuildsElection;
use Tests\TestCase;

/** Download a template that lists every post, fill it in, upload it back. */
class UploadTemplateTest extends TestCase
{
    use BuildsElection, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildElection();
        $this->actingAsUser($this->staff(Role::Dg));
    }

    /** @return list<array<string,string>> */
    private function rows(string $csv): array
    {
        $lines = array_map('str_getcsv', explode("\n", trim(preg_replace('/^\xEF\xBB\xBF/', '', $csv))));
        $head = array_shift($lines);

        return array_map(fn ($l) => array_combine($head, $l), $lines);
    }

    private function upload(string $endpoint, array $rows): array
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, array_keys($rows[0]));
        foreach ($rows as $r) {
            fputcsv($out, $r);
        }
        rewind($out);

        return $this->post($endpoint, ['file' => UploadedFile::fake()->createWithContent('filled.csv', stream_get_contents($out))], ['Accept' => 'application/json'])->assertOk()->json();
    }

    public function test_the_supervisor_template_lists_every_lga_and_ward_and_uploads_back(): void
    {
        $csv = $this->get('/api/v1/setup/templates/supervisors')->assertOk()->streamedContent();
        $rows = $this->rows($csv);
        $wardCount = $this->campaign->wards()->count();
        $lgaCount = $this->campaign->lgas()->count();
        $this->assertCount($lgaCount * 2 + $wardCount, $rows, 'each LGA (supervisor and backup) and each ward');
        $this->assertStringStartsWith('="', $rows[0]['lga_code'], 'codes kept as text for Excel');

        // Fill one ward and one LGA; Excel has dropped the leading zeros from the codes.
        $ward = collect($rows)->firstWhere('level', 'ward');
        $lga = collect($rows)->firstWhere('level', 'lga');
        foreach ($rows as &$r) {
            $r['lga_code'] = ltrim(trim($r['lga_code'], '="'), '0');
            if ($r === $ward || ($r['level'] === 'ward' && $r['ward_name'] === $ward['ward_name'])) {
                $r['name'] = 'Kabiru Musa';
                $r['phone'] = '8034445555'; // leading 0 dropped too
            }
            if ($r['level'] === 'lga' && $r['backup'] === 'no' && $r['lga_name'] === $lga['lga_name']) {
                $r['name'] = 'Hauwa Ali';
                $r['phone'] = '08034446666';
            }
        }
        unset($r);
        $summary = $this->upload('/api/v1/supervisors/import', $rows);
        $this->assertSame([1, 1, 0], [$summary['ward'], $summary['lga'], count($summary['rejected'])]);
        $this->assertSame(count($rows) - 2, $summary['blank']);
        $this->assertSame(Role::WardSupervisor, User::where('phone', '08034445555')->sole()->role);
    }

    public function test_the_roster_template_lists_every_unit_and_uploads_back(): void
    {
        $this->agentFor('PU1', ['name' => 'Existing Agent', 'phone' => '08030000001']);
        $rows = $this->rows($this->get('/api/v1/setup/templates/agents')->assertOk()->streamedContent());
        $this->assertCount(count($this->units), $rows);
        $pu1 = collect($rows)->firstWhere('pu_name', $this->units['PU1']->name);
        $this->assertSame(['Existing Agent', '="08030000001"'], [$pu1['name'], $pu1['phone']]);

        foreach ($rows as &$r) {
            if ($r['pu_name'] === $this->units['PU2']->name) {
                $r['name'] = 'New Agent';
                $r['phone'] = '08031230000';
                $r['pu_code'] = '1/1/2001'; // Excel turned the code into a date; the unit is found by ward and name
            }
        }
        unset($r);
        $summary = $this->upload('/api/v1/roster/import', $rows);
        $this->assertSame([], $summary['rejected']);
        $this->assertSame($this->units['PU2']->id, User::where('phone', '08031230000')->sole()->polling_unit_id);
        $this->assertSame(count($rows) - 2, $summary['blank']);
    }

    public function test_an_agent_in_the_supervisor_upload_gets_a_clear_reason(): void
    {
        $this->agentFor('PU1', ['phone' => '08030000009']);
        $ward = $this->wards['L1'];
        $summary = $this->upload('/api/v1/supervisors/import', [['level' => 'ward', 'lga_code' => $ward->lga->code, 'ward_code' => $ward->code, 'name' => 'Agent One', 'phone' => '08030000009', 'backup' => '']]);
        $this->assertStringContainsString('agent AG-PU1 at PU1', $summary['rejected'][0]['reason']);
    }
}
