import 'dart:convert';
import 'dart:typed_data';

import 'package:crypto/crypto.dart' as hash;
import 'package:cryptography/cryptography.dart';

/// The signing contract with the server (ResultSigner on the Laravel side). Changing anything here
/// must keep `shared/signing-fixture.json` passing in both test suites.
class Canonical {
  /// Coordinates are signed and sent as strings with exactly 7 decimals.
  static String? coord(double? v) => v?.toStringAsFixed(7);

  /// UTC to the second, the exact string that is signed.
  static String timestamp(DateTime t) {
    final u = t.toUtc();
    String two(int n) => n.toString().padLeft(2, '0');
    return '${u.year.toString().padLeft(4, '0')}-${two(u.month)}-${two(u.day)}T${two(u.hour)}:${two(u.minute)}:${two(u.second)}Z';
  }

  static String payload({
    required String unit,
    required int accredited,
    required int rejected,
    required Map<String, int> votes,
    String? photoSha256,
    String? latitude,
    String? longitude,
    required String capturedAt,
    int? supersedes,
  }) {
    final sortedVotes = Map.fromEntries(
      votes.entries.toList()..sort((a, b) => a.key.compareTo(b.key)),
    );
    // Keys in alphabetical order; jsonEncode keeps insertion order.
    return jsonEncode({
      'accredited': accredited,
      'captured_at': capturedAt,
      'latitude': latitude,
      'longitude': longitude,
      'photo_sha256': photoSha256,
      'polling_unit': unit,
      'rejected': rejected,
      'supersedes': supersedes,
      'votes': sortedVotes,
    });
  }

  static String sha256Hex(List<int> bytes) =>
      hash.sha256.convert(bytes).toString();

  /// First 8 hex characters of the payload hash, upper case: the reference read back to the agent.
  static String fingerprint(String canonical) =>
      sha256Hex(utf8.encode(canonical)).substring(0, 8).toUpperCase();
}

/// The phone's Ed25519 key. The 32-byte seed is the only secret; it stays in secure storage.
class DeviceKey {
  DeviceKey._(this._pair, this.publicKeyB64);

  final SimpleKeyPair _pair;
  final String publicKeyB64;
  static final _algo = Ed25519();

  static Future<DeviceKey> fromSeed(List<int> seed) async {
    final pair = await _algo.newKeyPairFromSeed(seed);
    final pub = await pair.extractPublicKey();
    return DeviceKey._(pair, base64Encode(pub.bytes));
  }

  static List<int> newSeed() => SecretKeyData.random(length: 32).bytes;

  Future<String> sign(String message) async {
    final sig = await _algo.sign(
      Uint8List.fromList(utf8.encode(message)),
      keyPair: _pair,
    );
    return base64Encode(sig.bytes);
  }
}
