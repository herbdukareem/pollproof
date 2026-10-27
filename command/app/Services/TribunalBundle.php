<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\IrevEntry;
use App\Models\PollingUnit;
use App\Models\ResultSubmission;
use App\Models\TribunalCase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * The reply bundle for counsel: the grounds and our answers, the full evidence for every unit
 * the answers rely on (signed results, EC8A photos, IReV captures), and the audit log, with a
 * SHA-256 manifest so anyone can check nothing was changed after export.
 */
final class TribunalBundle
{
    public function __construct(private readonly EvidenceBuilder $evidence) {}

    /** @return string path to a temporary ZIP file */
    public function build(TribunalCase $case): string
    {
        $case->load('grounds.owner', 'tasks');
        $disk = Storage::disk(config('pollproof.evidence_disk'));
        $path = tempnam(sys_get_temp_dir(), 'bundle').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $manifest = [];
        $add = function (string $name, string $contents) use ($zip, &$manifest) {
            $zip->addFromString($name, $contents);
            $manifest[$name] = hash('sha256', $contents);
        };

        $add('grounds.md', $this->grounds($case));

        $unitIds = $case->grounds->flatMap(fn ($g) => $g->unit_ids ?? [])->unique()->values();
        $units = PollingUnit::where('campaign_id', $case->campaign_id)->whereIn('id', $unitIds)->orderBy('code')->get();
        foreach ($units as $unit) {
            $dir = 'units/'.Str::slug($unit->code);
            $add("{$dir}/evidence.json", json_encode($this->evidence->build($unit), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            foreach (ResultSubmission::where('polling_unit_id', $unit->id)->whereNotNull('photo_path')->orderBy('id')->get() as $r) {
                if ($disk->exists($r->photo_path)) {
                    $add("{$dir}/ec8a-result-{$r->id}.".pathinfo($r->photo_path, PATHINFO_EXTENSION), $disk->get($r->photo_path));
                }
            }
            foreach (IrevEntry::where('polling_unit_id', $unit->id)->whereNotNull('screenshot_path')->orderBy('id')->get() as $e) {
                if ($disk->exists($e->screenshot_path)) {
                    $add("{$dir}/irev-{$e->id}.".pathinfo($e->screenshot_path, PATHINFO_EXTENSION), $disk->get($e->screenshot_path));
                }
            }
        }

        $add('audit-log.csv', $this->auditCsv($case->campaign_id));
        $zip->addFromString('MANIFEST.sha256', collect($manifest)->map(fn ($h, $f) => "{$h}  {$f}")->implode("\n")."\n");
        $zip->addFromString('README.txt', "PollProof reply bundle: {$case->title}\nExported ".now()->toIso8601String()."\n\n"
            ."grounds.md         the petition's grounds and our answers\n"
            ."units/<code>/      evidence for each polling unit cited: signed results, EC8A photos, IReV captures\n"
            ."audit-log.csv      every action in PollProof, hash-chained (each row's hash covers the previous one)\n"
            ."MANIFEST.sha256    SHA-256 of every file above; check with: sha256sum -c MANIFEST.sha256\n");
        $zip->close();

        return $path;
    }

    private function grounds(TribunalCase $case): string
    {
        $lines = ["# {$case->title}", '', "Petitioner: {$case->petitioner}  ", 'Our role: '.ucfirst($case->our_role), ''];
        foreach ($case->milestones ?? [] as $m) {
            $lines[] = "- {$m['date']}: {$m['label']}";
        }
        foreach ($case->grounds as $i => $g) {
            $codes = PollingUnit::whereIn('id', $g->unit_ids ?? [])->orderBy('code')->pluck('code')->implode(', ');
            $lines = array_merge($lines, ['', '## Ground '.($i + 1).": {$g->label}", '', "**Claim.** {$g->claim}", '',
                '**Our answer.** '.($g->answer ?: '_Not written yet._'), '',
                '**Evidence.** '.($g->evidence_note ?: '–').($codes ? " Units: {$codes}." : ''), '',
                'Status: '.($g->status === 'ready' ? 'answer ready' : 'in progress').($g->owner ? " · owner {$g->owner->name}" : '')]);
        }

        return implode("\n", $lines)."\n";
    }

    private function auditCsv(int $campaignId): string
    {
        $out = fopen('php://temp', 'w+');
        fputcsv($out, ['id', 'created_at', 'user_id', 'action', 'subject_type', 'subject_id', 'meta', 'ip', 'prev_hash', 'hash']);
        AuditLog::where('campaign_id', $campaignId)->orderBy('id')->chunk(1000, function ($rows) use ($out) {
            foreach ($rows as $r) {
                fputcsv($out, [$r->id, $r->created_at?->toIso8601String(), $r->user_id, $r->action, $r->subject_type, $r->subject_id, json_encode($r->meta), $r->ip, $r->prev_hash, $r->hash]);
            }
        });
        rewind($out);

        return stream_get_contents($out);
    }
}
