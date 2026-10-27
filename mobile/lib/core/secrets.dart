import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// The sign-in token and the device signing seed. Kept in the Android Keystore-backed secure storage.
class Secrets {
  static const _s = FlutterSecureStorage();

  static Future<String?> read(String key) => _s.read(key: key);
  static Future<void> write(String key, String? value) =>
      value == null ? _s.delete(key: key) : _s.write(key: key, value: value);
  static Future<void> clear() => _s.deleteAll();
}
