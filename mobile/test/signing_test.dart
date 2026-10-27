import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:pollproof_agent/core/signing.dart';

/// Same fixture as command/tests/Unit/SigningContractTest.php: the phone and the server must agree byte for byte.
void main() {
  final fixture = jsonDecode(
    File('../shared/signing-fixture.json').readAsStringSync(),
  ) as Map<String, dynamic>;

  test(
    'canonical payload, hash and Ed25519 signature match the server',
    () async {
      final seedHex = fixture['seed_hex'] as String;
      final seed = [
        for (var i = 0; i < seedHex.length; i += 2)
          int.parse(seedHex.substring(i, i + 2), radix: 16),
      ];
      final key = await DeviceKey.fromSeed(seed);
      expect(key.publicKeyB64, fixture['public_key']);

      for (final c in fixture['cases'] as List) {
        final canonical = Canonical.payload(
          unit: c['unit'],
          accredited: c['accredited'],
          rejected: c['rejected'],
          votes: Map<String, int>.from(c['votes']),
          photoSha256: c['photo_sha256'],
          latitude: c['latitude'],
          longitude: c['longitude'],
          capturedAt: c['captured_at'],
          supersedes: c['supersedes'],
        );
        expect(canonical, c['canonical']);
        expect(Canonical.sha256Hex(utf8.encode(canonical)), c['sha256']);
        expect(await key.sign(canonical), c['signature']);
      }
    },
  );

  test('coordinates and timestamps are formatted as the server expects', () {
    expect(Canonical.coord(9.61390004), '9.6139000');
    expect(Canonical.coord(-0.1), '-0.1000000');
    expect(
      Canonical.timestamp(DateTime.utc(2027, 2, 6, 15, 41, 0, 123)),
      '2027-02-06T15:41:00Z',
    );
  });
}
