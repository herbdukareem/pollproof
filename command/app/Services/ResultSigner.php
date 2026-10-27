<?php

namespace App\Services;

/**
 * Builds the canonical payload an agent's phone signs, and verifies Ed25519 signatures.
 *
 * The phone and the server must produce byte-identical JSON:
 *  - keys sorted alphabetically at every level
 *  - integers as integers; coordinates as strings with exactly 7 decimals (or null)
 *  - captured_at exactly as the phone recorded it (ISO 8601, UTC)
 *  - no whitespace, slashes and unicode unescaped
 */
final class ResultSigner
{
    /**
     * @param  array<string,int>  $votes  votes keyed by party code
     */
    public static function canonical(
        string $pollingUnitCode,
        int $accredited,
        int $rejected,
        array $votes,
        ?string $photoSha256,
        ?float $latitude,
        ?float $longitude,
        string $capturedAt,
        ?int $supersedesId,
    ): string {
        ksort($votes);
        $payload = [
            'accredited' => $accredited,
            'captured_at' => $capturedAt,
            'latitude' => $latitude === null ? null : number_format($latitude, 7, '.', ''),
            'longitude' => $longitude === null ? null : number_format($longitude, 7, '.', ''),
            'photo_sha256' => $photoSha256,
            'polling_unit' => $pollingUnitCode,
            'rejected' => $rejected,
            'supersedes' => $supersedesId,
            'votes' => array_map('intval', $votes),
        ];

        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function hash(string $canonical): string
    {
        return hash('sha256', $canonical);
    }

    public static function verify(string $canonical, string $signatureB64, string $publicKeyB64): bool
    {
        $signature = base64_decode($signatureB64, true);
        $publicKey = base64_decode($publicKeyB64, true);
        if ($signature === false || $publicKey === false
            || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($signature, $canonical, $publicKey);
    }
}
