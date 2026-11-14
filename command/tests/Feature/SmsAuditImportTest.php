<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\ResultSubmission;
use App\Services\SmsResultParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsElection;
use Tests\TestCase;

class SmsAuditImportTest extends TestCase
{
    use BuildsElection, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildElection();
        config(['pollproof.sms_webhook_token' => 'hook-secret']);
    }

    public function test_sms_format_is_parsed(): void
    {
        $this->assertSame(
            ['unit' => '004', 'accredited' => 448, 'rejected' => 6, 'votes' => ['A' => 214, 'B' => 187, 'C' => 22, 'D' => 9]],
            SmsResultParser::parse('r 004 AC448 A214 B187 C22 D9 X6'),
        );
        $this->assertNull(SmsResultParser::parse('hello'));
        $this->assertNull(SmsResultParser::parse('R 004 A214'));
    }

    public function test_an_agent_can_send_the_result_by_sms(): void
    {
        $agent = $this->agentFor('PU1');
        $this->postJson('/api/v1/sms/inbound', ['from' => $agent->phone, 'text' => 'R PU1 AC448 A214 B187 C22 X6'])->assertUnauthorized();

        $reply = $this->withHeader('X-Webhook-Token', 'hook-secret')
            ->postJson('/api/v1/sms/inbound', ['from' => $agent->phone, 'text' => 'R PU1 AC448 A214 B187 C22 X6'])
            ->assertOk()->json('reply');

        $this->assertMatchesRegularExpression('/^OK PU1 total 429 ref [0-9A-F]{8}$/', $reply);
        $result = ResultSubmission::sole();
        $this->assertSame('sms', $result->channel);
        $this->assertFalse($result->signature_valid);

        $this->withHeader('X-Webhook-Token', 'hook-secret')
            ->postJson('/api/v1/sms/inbound', ['from' => $agent->phone, 'text' => 'R PU2 AC10 A1'])
            ->assertJsonPath('reply', 'Unit PU2 is not your assigned polling unit.');
    }

    public function test_audit_log_is_chained_and_detects_tampering(): void
    {
        $this->submitResult('PU1', ['A' => 10], 20);
        $this->submitResult('PU2', ['A' => 12], 20);
        $this->actingAsUser($this->staff(Role::Legal));

        $this->getJson('/api/v1/audit-logs/verify')->assertOk()->assertJsonPath('valid', true);

        $entry = AuditLog::where('campaign_id', $this->campaign->id)->orderBy('id')->first();
        DB::table('audit_logs')->where('id', $entry->id)->update(['meta' => json_encode(['payload_hash' => 'forged'])]);

        $this->getJson('/api/v1/audit-logs/verify')->assertJsonPath('valid', false)->assertJsonPath('broken_at', $entry->id);
    }

    public function test_structure_csv_imports_and_reimports_safely(): void
    {
        $csv = "lga_code,lga_name,ward_code,ward_name,pu_code,pu_name,latitude,longitude,registered_voters\n"
            ."L9,New LGA,W1,Ward One,PU900,School A,9.1,6.1,500\n"
            ."L9,New LGA,W1,Ward One,PU901,School B,,,400\n"
            ."L9,New LGA,W2,Ward Two,PU902,Market,9.2,6.2,\n";
        $this->actingAsUser($this->staff(Role::Dg));

        $this->post('/api/v1/structure/import', ['file' => UploadedFile::fake()->createWithContent('units.csv', $csv)], ['Accept' => 'application/json'])
            ->assertOk()->assertJson(['lgas' => 1, 'wards' => 2, 'units' => 3, 'missing_gps' => 1]);
        $this->post('/api/v1/structure/import', ['file' => UploadedFile::fake()->createWithContent('units.csv', $csv)], ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertSame(9, $this->campaign->pollingUnits()->count());
    }

    public function test_the_unit_template_lists_every_unit_and_uploads_back_unchanged(): void
    {
        $this->actingAsUser($this->staff(Role::Dg));
        $this->units['PU1']->update(['registered_voters' => 750]);

        $csv = $this->get('/api/v1/setup/templates/units')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->streamedContent();
        $lines = preg_split('/\r?\n/', trim($csv));
        // Excel's byte-order mark, then the header the importer asks for.
        $this->assertSame("\xEF\xBB\xBFlga_code,lga_name,ward_code,ward_name,pu_code,pu_name,latitude,longitude,registered_voters", $lines[0]);
        $this->assertCount(7, $lines);
        // Codes are written so Excel keeps them as text.
        $this->assertStringStartsWith('"=""L1""","LGA L1","=""W1""","Ward of L1","=""PU1""","Unit 1",9.6', $lines[1]);
        $this->assertStringEndsWith(',750', $lines[1]);

        // Saved as it came and uploaded again: the same six units, nothing duplicated or wiped.
        $this->post('/api/v1/structure/import', ['file' => UploadedFile::fake()->createWithContent('polling-unit-template.csv', $csv)], ['Accept' => 'application/json'])
            ->assertOk()->assertJson(['lgas' => 3, 'wards' => 3, 'units' => 6, 'missing_gps' => 0]);
        $this->assertSame(6, $this->campaign->pollingUnits()->count());
        $this->assertSame('PU1', $this->units['PU1']->fresh()->code);
        $this->assertSame(750, $this->units['PU1']->fresh()->registered_voters);
    }

    public function test_the_unit_template_is_just_the_header_before_the_first_import(): void
    {
        $this->campaign->pollingUnits()->delete();
        $this->actingAsUser($this->staff(Role::Dg));
        $csv = $this->get('/api/v1/setup/templates/units')->assertOk()->streamedContent();
        $this->assertSame("\xEF\xBB\xBFlga_code,lga_name,ward_code,ward_name,pu_code,pu_name,latitude,longitude,registered_voters", trim($csv));
    }
}
