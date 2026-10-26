import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:pollproof_agent/core/api.dart';
import 'package:pollproof_agent/core/outbox.dart';
import 'package:pollproof_agent/core/store.dart';

class MemoryStore implements Store {
  final data = <String, String>{};
  @override
  Future<String?> read(String key) async => data[key];
  @override
  Future<void> write(String key, String value) async => data[key] = value;
  @override
  Future<void> delete(String key) async => data.remove(key);
}

void main() {
  late MemoryStore store;
  late bool online;
  late List<String> calls;
  late Outbox outbox;

  setUp(() {
    store = MemoryStore();
    online = false;
    calls = [];
    final client = MockClient((req) async {
      if (!online) throw http.ClientException('no network');
      calls.add('${req.method} ${req.url.path}');
      if (req.url.path.endsWith('/results')) {
        return http.Response(jsonEncode({'id': 41}), 201);
      }
      if (req.url.path.endsWith('/check-ins')) {
        return http.Response(
          jsonEncode({
            'message': "You're 410 m from PU004.",
            'status': 'outside_area',
          }),
          422,
        );
      }
      return http.Response(jsonEncode({'ok': true}), 200);
    });
    outbox = Outbox(
      store,
      () => Api('https://api.test', token: 't', client: client),
    );
  });

  test('items wait offline, survive a restart, then go in order with the photo after its result', () async {
    await outbox.load();
    final result = await outbox.add(
      kind: 'result',
      path: 'results',
      title: 'Result',
      fields: {'accredited': 448},
    );
    await outbox.add(
      kind: 'photo',
      path: 'results/{after.id}/photo',
      title: 'Photo',
      after: result.id,
      files: [
        {
          'field': 'photo',
          'filename': 'ec8a.jpg',
          'blob': await outbox.saveBlob(utf8.encode('jpeg') as dynamic),
        },
      ],
    );
    await outbox.flush();
    expect(outbox.waiting.length, 2);
    expect(outbox.online, isFalse);

    // The phone restarts: a new outbox reads the same storage.
    final client = MockClient((req) async {
      calls.add('${req.method} ${req.url.path}');
      return http.Response(
        jsonEncode(
          req.url.path.endsWith('/results') ? {'id': 41} : {'ok': true},
        ),
        201,
      );
    });
    final again = Outbox(
      store,
      () => Api('https://api.test', token: 't', client: client),
    );
    await again.load();
    expect(again.waiting.length, 2);
    await again.flush();
    expect(again.waiting, isEmpty);
    expect(calls, ['POST /api/v1/results', 'POST /api/v1/results/41/photo']);
    expect(
      store.data.keys.where((k) => k.startsWith('blob_')),
      isEmpty,
      reason: 'photo bytes are removed once uploaded',
    );
  });

  test(
    'a refused item is kept with the reason and does not block the rest',
    () async {
      online = true;
      await outbox.load();
      await outbox.add(kind: 'check_in', path: 'check-ins', title: 'Check-in');
      await outbox.add(kind: 'incident', path: 'incidents', title: 'Incident');
      await outbox.flush();
      expect(outbox.refused.single.error, "You're 410 m from PU004.");
      expect(outbox.sent.single.title, 'Incident');
    },
  );

  test('a photo whose result was refused is not sent', () async {
    online = true;
    await outbox.load();
    final ci = await outbox.add(
      kind: 'check_in',
      path: 'check-ins',
      title: 'Check-in',
    );
    await outbox.add(
      kind: 'photo',
      path: 'x/{after.id}',
      title: 'Follows the check-in',
      after: ci.id,
    );
    await outbox.flush();
    await outbox.flush();
    expect(outbox.refused.length, 2);
    expect(calls, ['POST /api/v1/check-ins']);
  });
}
