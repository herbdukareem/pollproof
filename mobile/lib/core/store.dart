import 'store_io.dart' if (dart.library.js_interop) 'store_web.dart' as impl;

/// Plain local storage for the outbox and cached data. Secrets (token, signing seed) go in
/// flutter_secure_storage instead, see [Secrets].
abstract class Store {
  Future<String?> read(String key);
  Future<void> write(String key, String value);
  Future<void> delete(String key);

  static Store create() => impl.createStore();
}
