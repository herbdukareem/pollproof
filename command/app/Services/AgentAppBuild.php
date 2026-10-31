<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The Android agent app, as built by GitHub Actions and published on the repository's
 * "agent-app" release (pollproof-agent.apk plus version.json). The server keeps its own copy
 * in storage/app/agent-app, so signed-in staff download it from this domain.
 */
final class AgentAppBuild
{
    public const APK = 'pollproof-agent.apk';

    public function dir(): string
    {
        return storage_path('app/agent-app');
    }

    public function apkPath(): string
    {
        return $this->dir().'/'.self::APK;
    }

    public function releaseUrl(string $file = self::APK): string
    {
        return rtrim((string) config('pollproof.agent_app.release_url'), '/').'/'.$file;
    }

    /** @return array{version: string, build: int|string, built_at: ?string, size: int, sha256: string, commit?: string}|null the copy on this server */
    public function local(): ?array
    {
        $meta = $this->dir().'/version.json';
        if (! is_file($this->apkPath()) || ! is_file($meta)) {
            return null;
        }

        return json_decode((string) file_get_contents($meta), true) ?: null;
    }

    /**
     * Download the latest published build to this server, checking its SHA-256.
     *
     * @return array the new version.json
     */
    public function fetch(): array
    {
        $meta = Http::timeout(30)->retry(2, 1000)->get($this->releaseUrl('version.json'))->throw()->json();
        if (! is_array($meta) || empty($meta['sha256'])) {
            throw new RuntimeException('The published version.json is missing or has no checksum.');
        }
        if (($local = $this->local()) && $local['sha256'] === $meta['sha256']) {
            return $local; // already the latest
        }

        File::ensureDirectoryExists($this->dir());
        $tmp = $this->dir().'/download.tmp';
        Http::timeout(600)->sink($tmp)->get($this->releaseUrl())->throw();
        if (! hash_equals($meta['sha256'], hash_file('sha256', $tmp))) {
            @unlink($tmp);
            throw new RuntimeException('The downloaded APK does not match the published checksum. Nothing was replaced.');
        }
        rename($tmp, $this->apkPath());
        file_put_contents($this->dir().'/version.json', json_encode($meta, JSON_PRETTY_PRINT));

        return $meta;
    }
}
