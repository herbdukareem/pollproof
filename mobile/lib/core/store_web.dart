import 'package:shared_preferences/shared_preferences.dart';

import 'store.dart';

Store createStore() => _PrefsStore();

/// Browser storage, used for the web build (demos and testing).
class _PrefsStore implements Store {
  Future<SharedPreferences> get _p => SharedPreferences.getInstance();

  @override
  Future<String?> read(String key) async => (await _p).getString('pp.$key');

  @override
  Future<void> write(String key, String value) async =>
      (await _p).setString('pp.$key', value);

  @override
  Future<void> delete(String key) async => (await _p).remove('pp.$key');
}
