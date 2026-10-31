import 'dart:async';
import 'dart:convert';

import 'package:flutter/foundation.dart';

import 'api.dart';
import 'outbox.dart';
import 'secrets.dart';
import 'signing.dart';
import 'store.dart';

/// Signed-in person, their assignment and the phone's signing key. One instance for the app.
class AppState extends ChangeNotifier {
  AppState._(this.store) {
    outbox = Outbox(store, () => api);
    outbox.addListener(notifyListeners);
  }

  static final AppState instance = AppState._(Store.create());

  static const defaultApiUrl = String.fromEnvironment(
    'API_URL',
    defaultValue: 'https://app.pollproof.online',
  );

  final Store store;
  late final Outbox outbox;
  Api api = Api(defaultApiUrl);
  Map<String, dynamic>? me;
  DeviceKey? key;
  int? deviceId;
  int unread = 0;
  bool ready = false;
  Timer? _poll;

  String get role => me?['role'] ?? '';
  bool get signedIn => api.token != null && me != null;
  Map<String, dynamic>? get unit =>
      me?['polling_unit'] as Map<String, dynamic>?;
  Map<String, dynamic> get rules =>
      (me?['rules'] as Map?)?.cast<String, dynamic>() ?? {};
  Map<String, dynamic>? get supervisor =>
      (me?['supervisor'] as Map?)?.cast<String, dynamic>();
  Map<String, dynamic> get today =>
      (me?['today'] as Map?)?.cast<String, dynamic>() ?? {};
  List<Map<String, dynamic>> get parties => [
    for (final p in (me?['parties'] as List? ?? []))
      Map<String, dynamic>.from(p),
  ];

  Future<void> start() async {
    api = Api(
      await store.read('api_url') ?? defaultApiUrl,
      token: await Secrets.read('token'),
    );
    final cached = await store.read('me');
    if (cached != null) me = jsonDecode(cached);
    final id = await store.read('device_id');
    deviceId = id == null ? null : int.tryParse(id);
    final seed = await Secrets.read('seed');
    if (seed != null) key = await DeviceKey.fromSeed(base64Decode(seed));
    await outbox.load();
    ready = true;
    notifyListeners();
    if (api.token != null) {
      unawaited(refresh());
      _startPolling();
    }
  }

  Future<void> setServer(String url) async {
    await store.write('api_url', url.trim());
    api.baseUrl = url.trim();
    notifyListeners();
  }

  Future<void> requestCode(String phone) =>
      api.post('auth/otp/request', {'phone': phone});

  Future<void> verifyCode(String phone, String code) async {
    final res = await api.post('auth/otp/verify', {
      'phone': phone,
      'code': code,
      'device_name': 'PollProof agent app',
    });
    api.token = res['token'];
    await Secrets.write('token', api.token);
    await refresh();
    await ensureDevice();
    _startPolling();
  }

  /// Fetches the profile, assignment and today's progress. Works offline from the cached copy.
  Future<void> refresh() async {
    try {
      me = await api.get('me');
      await store.write('me', jsonEncode(me));
      final n = await api.get('me/notices');
      unread = n['unread'] ?? 0;
      notifyListeners();
      unawaited(outbox.flush());
    } on ApiException catch (e) {
      if (e.status == 401) await signOut(force: true);
    } on NetworkException {
      // Keep the cached profile.
    }
  }

  /// The phone's signing key is made once and registered with the server.
  Future<void> ensureDevice() async {
    if (key == null) {
      final seed = DeviceKey.newSeed();
      await Secrets.write('seed', base64Encode(seed));
      key = await DeviceKey.fromSeed(seed);
    }
    if (deviceId == null) {
      final res = await api.post('devices', {
        'public_key': key!.publicKeyB64,
        'label': 'PollProof agent app',
      });
      deviceId = res['id'];
      await store.write('device_id', '$deviceId');
    }
    notifyListeners();
  }

  Future<bool> signOut({bool force = false}) async {
    if (!force && outbox.waiting.isNotEmpty) return false;
    try {
      if (!force) await api.post('auth/logout');
    } catch (_) {}
    _poll?.cancel();
    api.token = null;
    me = null;
    await Secrets.write('token', null);
    await store.delete('me');
    notifyListeners();
    return true;
  }

  void _startPolling() {
    _poll?.cancel();
    _poll = Timer.periodic(const Duration(minutes: 1), (_) => refresh());
  }
}
