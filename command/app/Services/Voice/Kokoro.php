<?php

namespace App\Services\Voice;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Turns a sentence into speech with the Kokoro TTS server, keeping each sentence's WAV on disk
 * so it is synthesised once however many screens play it.
 */
final class Kokoro
{
    public const DIR = 'voice';

    public function enabled(): bool
    {
        return (bool) config('pollproof.voice.enabled');
    }

    /** Where this sentence's audio is (or will be) kept on the local disk. */
    public function path(string $text): string
    {
        $c = config('pollproof.voice');

        return self::DIR.'/'.sha1($c['voice'].'|'.$c['speed'].'|'.$text).'.wav';
    }

    /**
     * The sentence's audio on the local disk, synthesised now if it isn't there yet. Two screens
     * asking at once wait for the same synthesis rather than starting two.
     *
     * @throws RuntimeException when Kokoro doesn't answer with audio
     */
    public function audio(string $text): string
    {
        $path = $this->path($text);
        $disk = Storage::disk('local');
        if ($disk->exists($path)) {
            return $path;
        }

        $timeout = (int) config('pollproof.voice.timeout', 120);

        return Cache::lock('voice:'.basename($path), $timeout + 10)->block($timeout + 5, function () use ($disk, $path, $text) {
            if (! $disk->exists($path)) {
                $disk->put($path, $this->synthesise($text));
            }

            return $path;
        });
    }

    /** @throws RuntimeException */
    public function synthesise(string $text): string
    {
        $c = config('pollproof.voice');
        $socket = trim((string) ($c['socket'] ?? ''));

        $response = Http::withOptions($socket !== '' ? ['curl' => [CURLOPT_UNIX_SOCKET_PATH => $socket]] : [])
            ->timeout((int) ($c['timeout'] ?? 120))
            ->post($c['url'], ['text' => $text, 'voice' => $c['voice'], 'speed' => (float) $c['speed']]);

        $body = $response->body();
        if (! $response->successful() || strlen($body) < 44 || ! str_starts_with($body, 'RIFF')) {
            throw new RuntimeException('Kokoro did not return audio (HTTP '.$response->status().').');
        }

        return $body;
    }

    /** Delete audio not played for a while; sentences that come back are synthesised again. */
    public function prune(int $days = 3): int
    {
        $disk = Storage::disk('local');
        $cutoff = now()->subDays($days)->getTimestamp();
        $gone = 0;
        foreach ($disk->files(self::DIR) as $file) {
            if ($disk->lastModified($file) < $cutoff) {
                $disk->delete($file);
                $gone++;
            }
        }

        return $gone;
    }
}
