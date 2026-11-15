<?php

namespace App\Services;

use App\Ai\Ai;
use App\Ai\HeardResult;
use App\Ai\ResultReader;
use App\Jobs\CheckResultVideo;
use App\Models\Election;
use App\Models\PollingUnit;
use App\Models\ResultSubmission;
use App\Models\VideoCheck;
use App\Models\VideoClip;
use Illuminate\Support\Facades\Storage;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\UserMessage;
use Throwable;

/**
 * Listens to result announcement videos: the sound is transcribed, the AI reads the announced
 * figures out of the transcript, and they are compared with the agent's signed result for the
 * same unit and race. A difference raises an alert and waits on the AI checks page for a person.
 *
 * The AI only ever suggests. Nothing it hears changes a result; a reviewer decides.
 */
class VideoChecker
{
    public function __construct(private readonly Ai $ai, private readonly AlertService $alerts) {}

    /** A result announcement video has arrived in full: check it in the background. */
    public function queue(VideoClip $video): ?VideoCheck
    {
        if (! $this->ai->enabled() || $video->kind !== 'result' || ! $video->isComplete()) {
            return null;
        }
        $check = VideoCheck::firstOrCreate(['video_clip_id' => $video->id], [
            'campaign_id' => $video->campaign_id,
            'election_id' => $video->election_id ?? ($video->pollingUnit ? Races::atUnit($video->pollingUnit)->first()?->id : null),
            'polling_unit_id' => $video->polling_unit_id,
            'status' => 'queued',
        ]);
        CheckResultVideo::dispatch($check->id);

        return $check;
    }

    public function run(VideoCheck $check): void
    {
        $check->update(['status' => 'working', 'attempts' => $check->attempts + 1, 'error' => null]);
        $video = $check->video;
        $race = $check->election;
        $temp = [];
        try {
            $audio = $this->audioFor($video, $temp);
            if ($audio === null) {
                $check->update(['status' => 'skipped', 'error' => 'The video is over 25 MB and ffmpeg is not installed to take the sound out of it.']);

                return;
            }
            $transcript = trim((string) $this->ai->transcriber()->chat(new UserMessage([
                new TextContent($this->vocabulary($race)),
                new AudioContent($audio, SourceType::URL),
            ]))->message()->getContent());

            $heard = $transcript === '' ? null : app(ResultReader::class)->forRace($race, $check->pollingUnit)
                ->structured(new UserMessage("Transcript of the video:\n\n{$transcript}"), HeardResult::class, 2);

            $check->update([
                'status' => 'done',
                'transcript' => $transcript,
                'heard' => $heard ? $this->normalise($heard, $race) : ['is_result_announcement' => false, 'votes' => [], 'confidence' => 'low', 'notes' => 'No speech was heard in the video.'],
                'models' => $this->ai->models(),
                'checked_at' => now(),
            ]);
            $this->compare($check->fresh());
        } catch (Throwable $e) {
            report($e);
            $check->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 480)]);
        } finally {
            foreach ($temp as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * Compares what was heard with the current signed result. Runs after each check, and again
     * whenever the unit's result for the race is sent or corrected.
     */
    public function compare(VideoCheck $check): void
    {
        $heard = $check->heard;
        if (! $heard || $check->status !== 'done') {
            return;
        }
        $result = $check->polling_unit_id && $check->election_id
            ? ResultSubmission::where('polling_unit_id', $check->polling_unit_id)->where('election_id', $check->election_id)->where('is_current', true)->with('votes.party')->first()
            : null;
        $heardVotes = collect($heard['votes'] ?? [])->pluck('votes', 'party')->all();
        $any = $heardVotes || isset($heard['accredited']) || isset($heard['rejected']);

        $differences = [];
        if ($result) {
            $signed = $result->votesByParty();
            foreach ($heardVotes as $party => $votes) {
                if (($signed[$party] ?? null) !== $votes) {
                    $differences[$party] = ['heard' => $votes, 'signed' => $signed[$party] ?? null];
                }
            }
            foreach (['accredited', 'rejected'] as $figure) {
                if (isset($heard[$figure]) && (int) $heard[$figure] !== (int) $result->{$figure}) {
                    $differences[$figure] = ['heard' => (int) $heard[$figure], 'signed' => (int) $result->{$figure}];
                }
            }
        }
        $outcome = match (true) {
            ! ($heard['is_result_announcement'] ?? false) || ! $any => 'unclear',
            ! $result => 'no_result',
            $differences !== [] => 'differs',
            default => 'match',
        };
        $check->update(['outcome' => $outcome, 'differences' => $differences ?: null, 'result_submission_id' => $result?->id]);

        $key = "ai_mismatch:video:{$check->video_clip_id}";
        if ($outcome === 'differs' && $check->review === 'open') {
            $unit = $check->pollingUnit;
            $said = collect($differences)->map(fn ($d, $k) => ucfirst((string) $k)." heard {$d['heard']}, signed ".($d['signed'] ?? 'none'))->implode('; ');
            $this->alerts->raise($check->campaign_id, 'ai_mismatch', 'medium', $key,
                ($unit?->code ?? 'A unit').': announcement video differs from the signed result',
                "{$said}. Check the video on the AI checks page.", $result, ['check_id' => $check->id], $check->election);
        } else {
            $this->alerts->clear($check->campaign_id, $key);
        }
    }

    /** A unit's result for a race changed: compare its announcement videos again. */
    public function resultChanged(PollingUnit $unit, Election $race): void
    {
        VideoCheck::where('polling_unit_id', $unit->id)->where('election_id', $race->id)->where('status', 'done')->get()
            ->each(fn (VideoCheck $c) => $this->compare($c));
    }

    /**
     * A local audio file to send: the sound alone when ffmpeg is available (small and quick),
     * otherwise the video itself if OpenAI will take it.
     *
     * @param  list<string>  $temp  files to delete afterwards
     */
    private function audioFor(VideoClip $video, array &$temp): ?string
    {
        $disk = Storage::disk(config('pollproof.evidence_disk'));
        try {
            $path = $disk->path($video->path);
        } catch (Throwable) {
            $path = tempnam(sys_get_temp_dir(), 'ppv').'.'.$video->extension();
            file_put_contents($path, $disk->readStream($video->path));
            $temp[] = $path;
        }

        $mp3 = tempnam(sys_get_temp_dir(), 'ppa').'.mp3';
        $temp[] = $mp3;
        if ($this->ffmpeg($path, $mp3)) {
            return $mp3;
        }

        return filesize($path) <= config('pollproof.ai.max_upload_bytes') ? $path : null;
    }

    private function ffmpeg(string $in, string $out): bool
    {
        if (! function_exists('exec')) {
            return false;
        }
        $bin = (string) config('pollproof.ai.ffmpeg');
        $cmd = escapeshellarg($bin).' -nostdin -loglevel error -y -i '.escapeshellarg($in).' -vn -ac 1 -ar 16000 -b:a 32k '.escapeshellarg($out).' 2>&1';
        try {
            @exec($cmd, $output, $code);
        } catch (Throwable) {
            return false;
        }

        return $code === 0 && is_file($out) && filesize($out) > 0;
    }

    /** Words the transcriber should expect: what makes numbers and party names come out right. */
    private function vocabulary(?Election $race): string
    {
        $parties = $race?->parties()->get()->map(fn ($p) => trim("{$p->code} {$p->name} ".($p->pivot->candidate ?? '')))->implode(', ');

        return "A Nigerian polling unit result being announced by the presiding officer. Accredited voters, rejected ballots, total valid votes, total votes cast. Parties: {$parties}.";
    }

    /** @return array<string, mixed> */
    private function normalise(HeardResult $heard, ?Election $race): array
    {
        $codes = $race ? $race->parties()->pluck('code')->map(fn ($c) => strtoupper($c))->all() : [];
        $votes = collect($heard->votes)
            ->map(fn ($v) => ['party' => strtoupper(trim($v->party)), 'votes' => max(0, (int) $v->votes)])
            ->filter(fn ($v) => $v['party'] !== '' && (! $codes || in_array($v['party'], $codes, true)))
            ->unique('party')->values()->all();

        return [
            'is_result_announcement' => $heard->is_result_announcement,
            'accredited' => $heard->accredited,
            'rejected' => $heard->rejected,
            'total_valid' => $heard->total_valid,
            'total_cast' => $heard->total_cast,
            'votes' => $votes,
            'confidence' => in_array($heard->confidence, ['high', 'medium', 'low'], true) ? $heard->confidence : 'low',
            'unit_heard' => $heard->unit_heard,
            'notes' => $heard->notes,
        ];
    }
}
