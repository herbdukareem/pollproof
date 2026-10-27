<?php

namespace Tests\Unit;

use App\Services\ResultSigner;
use PHPUnit\Framework\TestCase;

/**
 * The signing contract shared with the agent app. The same fixture is checked by the Flutter
 * tests (mobile/test/signing_test.dart), so the phone and the server can never drift apart.
 */
class SigningContractTest extends TestCase
{
    public function test_canonical_payloads_and_signatures_match_the_shared_fixture(): void
    {
        $fixture = json_decode(file_get_contents(__DIR__.'/../../../shared/signing-fixture.json'), true);
        foreach ($fixture['cases'] as $c) {
            $canonical = ResultSigner::canonical(
                $c['unit'], $c['accredited'], $c['rejected'], $c['votes'], $c['photo_sha256'],
                $c['latitude'] === null ? null : (float) $c['latitude'],
                $c['longitude'] === null ? null : (float) $c['longitude'],
                $c['captured_at'], $c['supersedes'],
            );
            $this->assertSame($c['canonical'], $canonical);
            $this->assertSame($c['sha256'], ResultSigner::hash($canonical));
            $this->assertTrue(ResultSigner::verify($canonical, $c['signature'], $fixture['public_key']));
        }
    }
}
