import 'dart:convert';
import 'dart:io';

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

/// A server that keeps the bytes it has been sent, as VideoClipController does.
class FakeVideoServer {
  final received = <int>[];
  int size = 0;
  bool online = true;
  int piecesBeforeDrop = 1 << 30;
  final log = <String>[];

  Future<http.Response> handle(http.Request req) async {
    if (!online) throw http.ClientException('no network');
    final path = req.url.path;
    log.add('${req.method} $path');
    if (path.endsWith('/videos')) {
      size = (jsonDecode(req.body) as Map)['size'];
      return http.Response(
        jsonEncode({'id': 7, 'received': received.length, 'complete': false}),
        201,
      );
    }
    if (path.endsWith('/chunk')) {
      if (piecesBeforeDrop-- <= 0) {
        online = false;
        throw http.ClientException('signal lost');
      }
      final offset = int.parse(req.headers['upload-offset']!);
      if (offset != received.length) {
        return http.Response(jsonEncode({'received': received.length}), 409);
      }
      received.addAll(req.bodyBytes);
      return http.Response(
        jsonEncode({
          'id': 7,
          'received': received.length,
          'complete': received.length >= size,
        }),
        200,
      );
    }
    return http.Response(jsonEncode({'id': 99}), 201);
  }
}

void main() {
  late Directory dir;
  setUp(() => dir = Directory.systemTemp.createTempSync('pp-video'));
  tearDown(() => dir.deleteSync(recursive: true));

  test('a video goes in pieces, pauses when the signal drops, resumes where it stopped and is then removed from the phone', () async {
    final bytes = List<int>.generate(700 * 1024, (i) => i % 251);
    final file = File('${dir.path}/clip.mp4')..writeAsBytesSync(bytes);
    final server = FakeVideoServer()..piecesBeforeDrop = 1;
    final outbox = Outbox(
      MemoryStore(),
      () => Api(
        'https://api.test',
        token: 't',
        client: MockClient(server.handle),
      ),
    );
    await outbox.load();

    final item = await outbox.add(
      kind: 'video',
      path: 'videos',
      title: 'Video',
      fields: {'kind': 'result', 'size': bytes.length},
      meta: {'file': file.path, 'size': bytes.length},
    );
    await outbox.flush();
    // The first piece went; then the signal dropped. The rest waits on the phone.
    expect(outbox.byId(item.id)!.status, OutboxStatus.waiting);
    expect(outbox.online, isFalse);
    expect(server.received.length, 256 * 1024);
    expect(file.existsSync(), isTrue);

    server
      ..online = true
      ..piecesBeforeDrop = 1 << 30;
    await outbox.flush();
    expect(outbox.byId(item.id)!.status, OutboxStatus.sent);
    expect(server.received, bytes);
    expect(file.existsSync(), isFalse);
    // Described once; the second attempt carried on from the server's offset.
    expect(server.log.where((l) => l.endsWith('/videos')).length, 1);
  });

  test('a result added while a video is uploading goes first', () async {
    final bytes = List<int>.generate(3 * 1024 * 1024, (i) => i % 7);
    final file = File('${dir.path}/long.mp4')..writeAsBytesSync(bytes);
    final server = FakeVideoServer();
    late Outbox outbox;
    var addedResult = false;
    outbox = Outbox(
      MemoryStore(),
      () => Api(
        'https://api.test',
        token: 't',
        client: MockClient((req) async {
          if (req.url.path.endsWith('/chunk') && !addedResult) {
            addedResult = true;
            await outbox.add(
              kind: 'result',
              path: 'results',
              title: 'Result',
              fields: {'accredited': 1},
            );
          }
          return server.handle(req);
        }),
      ),
    );
    await outbox.load();
    final video = await outbox.add(
      kind: 'video',
      path: 'videos',
      title: 'Video',
      fields: {'size': bytes.length},
      meta: {'file': file.path, 'size': bytes.length},
    );
    await outbox.flush();
    await outbox.flush();

    final firstResult = server.log.indexOf('POST /api/v1/results');
    final lastPiece = server.log.lastIndexWhere((l) => l.endsWith('/chunk'));
    expect(firstResult, greaterThan(0));
    expect(firstResult, lessThan(lastPiece));
    expect(outbox.byId(video.id)!.status, OutboxStatus.sent);
    expect(server.received.length, bytes.length);
  });
}
