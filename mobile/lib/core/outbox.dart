import 'dart:async';
import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:uuid/uuid.dart';

import 'api.dart';
import 'store.dart';

enum OutboxStatus { waiting, sent, refused }

/// One thing to send to the command room. Saved on the phone first, sent when there's a connection.
class OutboxItem {
  OutboxItem({
    required this.id,
    required this.kind,
    required this.path,
    required this.title,
    this.fields = const {},
    this.files = const [],
    this.after,
    this.status = OutboxStatus.waiting,
    this.attempts = 0,
    this.error,
    this.response,
    DateTime? createdAt,
    this.sentAt,
    this.lastTry,
    this.meta = const {},
  }) : createdAt = createdAt ?? DateTime.now();

  final String id;
  final String kind; // check_in, result, photo, incident, confirm
  String path; // may contain {after.id}
  final String title;
  final Map<String, dynamic> fields;
  final List<Map<String, String>> files; // {field, filename, blob}
  final String? after; // id of an item that must be sent first
  OutboxStatus status;
  int attempts;
  String? error;
  Map<String, dynamic>? response;
  final DateTime createdAt;
  DateTime? sentAt;
  DateTime? lastTry;
  final Map<String, dynamic> meta;

  Map<String, dynamic> toJson() => {
    'id': id,
    'kind': kind,
    'path': path,
    'title': title,
    'fields': fields,
    'files': files,
    'after': after,
    'status': status.name,
    'attempts': attempts,
    'error': error,
    'response': response,
    'created_at': createdAt.toIso8601String(),
    'sent_at': sentAt?.toIso8601String(),
    'last_try': lastTry?.toIso8601String(),
    'meta': meta,
  };

  factory OutboxItem.fromJson(Map<String, dynamic> j) => OutboxItem(
    id: j['id'],
    kind: j['kind'],
    path: j['path'],
    title: j['title'],
    fields: Map<String, dynamic>.from(j['fields'] ?? {}),
    files: [
      for (final f in (j['files'] as List? ?? [])) Map<String, String>.from(f),
    ],
    after: j['after'],
    status: OutboxStatus.values.byName(j['status']),
    attempts: j['attempts'] ?? 0,
    error: j['error'],
    response: (j['response'] as Map?)?.cast<String, dynamic>(),
    createdAt: DateTime.parse(j['created_at']),
    sentAt: j['sent_at'] == null ? null : DateTime.parse(j['sent_at']),
    lastTry: j['last_try'] == null ? null : DateTime.parse(j['last_try']),
    meta: Map<String, dynamic>.from(j['meta'] ?? {}),
  );
}

/// Everything the agent does is written here before it is sent, so nothing is lost when the
/// network drops or the phone restarts. Items go in order; a refused item doesn't block the rest.
class Outbox extends ChangeNotifier {
  Outbox(this._store, this._api);

  final Store _store;
  final Api Function() _api;
  final _items = <OutboxItem>[];
  Timer? _timer;
  bool _running = false;
  bool online = true;
  DateTime? lastAttempt;

  List<OutboxItem> get items => List.unmodifiable(_items);
  List<OutboxItem> get waiting =>
      _items.where((i) => i.status == OutboxStatus.waiting).toList();
  List<OutboxItem> get refused =>
      _items.where((i) => i.status == OutboxStatus.refused).toList();
  List<OutboxItem> get sent => _items
      .where((i) => i.status == OutboxStatus.sent)
      .toList()
      .reversed
      .toList();
  bool get busy => _running;

  Future<void> load() async {
    final raw = await _store.read('outbox');
    if (raw != null) {
      _items
        ..clear()
        ..addAll([
          for (final j in jsonDecode(raw) as List) OutboxItem.fromJson(j),
        ]);
    }
    _timer ??= Timer.periodic(const Duration(seconds: 20), (_) => flush());
    notifyListeners();
  }

  Future<void> _save() =>
      _store.write('outbox', jsonEncode([for (final i in _items) i.toJson()]));

  Future<String> saveBlob(Uint8List bytes) async {
    final key = 'blob_${const Uuid().v4()}';
    await _store.write(key, base64Encode(bytes));
    return key;
  }

  Future<Uint8List?> blob(String key) async {
    final raw = await _store.read(key);
    return raw == null ? null : base64Decode(raw);
  }

  Future<OutboxItem> add({
    required String kind,
    required String path,
    required String title,
    Map<String, dynamic> fields = const {},
    List<Map<String, String>> files = const [],
    String? after,
    Map<String, dynamic> meta = const {},
  }) async {
    final item = OutboxItem(
      id: const Uuid().v4(),
      kind: kind,
      path: path,
      title: title,
      fields: fields,
      files: files,
      after: after,
      meta: meta,
    );
    _items.add(item);
    await _save();
    notifyListeners();
    unawaited(flush());
    return item;
  }

  OutboxItem? byId(String id) => _items.where((i) => i.id == id).firstOrNull;

  Future<void>? _current;
  bool _again = false;

  /// Sends everything that can go now. Safe to call at any time: a call made while a send is
  /// running waits for it and then sends anything added meanwhile.
  Future<void> flush() {
    if (_running) {
      _again = true;
      return _current!;
    }
    if (waiting.isEmpty) return Future.value();
    return _current = _run();
  }

  Future<void> _run() async {
    _running = true;
    notifyListeners();
    try {
      for (final item in waiting) {
        final dep = item.after == null ? null : byId(item.after!);
        if (dep != null && dep.status != OutboxStatus.sent) {
          if (dep.status == OutboxStatus.refused) {
            item
              ..status = OutboxStatus.refused
              ..error = 'Not sent because "${dep.title}" was refused.';
          }
          continue;
        }
        final path = item.path.replaceAll(
          '{after.id}',
          '${dep?.response?['id'] ?? ''}',
        );
        item
          ..attempts += 1
          ..lastTry = DateTime.now();
        lastAttempt = DateTime.now();
        try {
          final files = <UploadFile>[];
          for (final f in item.files) {
            final bytes = await blob(f['blob']!);
            if (bytes != null) {
              files.add(UploadFile(f['field']!, f['filename']!, bytes));
            }
          }
          final res = files.isEmpty
              ? await _api().post(path, item.fields)
              : await _api().multipart(path, item.fields, files);
          item
            ..status = OutboxStatus.sent
            ..sentAt = DateTime.now()
            ..error = null
            ..response = res is Map<String, dynamic> ? res : {'data': res};
          online = true;
          for (final f in item.files) {
            await _store.delete(f['blob']!);
          }
        } on NetworkException {
          online = false;
          item.error = 'No connection';
          break; // no point trying the rest right now
        } on ApiException catch (e) {
          online = true;
          if (e.retryable) {
            item.error = 'Server busy, will retry';
            break;
          }
          item
            ..status = OutboxStatus.refused
            ..error = e.first
            ..response = e.body;
        }
        await _save();
        notifyListeners();
      }
    } finally {
      _running = false;
      await _save();
      notifyListeners();
    }
    if (_again) {
      _again = false;
      if (online) await flush();
    }
  }

  /// After a refused item is dealt with (e.g. a check-in sent again for review), it can be dropped.
  Future<void> dismiss(String id) async {
    _items.removeWhere((i) => i.id == id);
    await _save();
    notifyListeners();
  }

  Future<void> clearSent() async {
    _items.removeWhere(
      (i) =>
          i.status == OutboxStatus.sent &&
          DateTime.now().difference(i.sentAt ?? i.createdAt).inDays > 7,
    );
    await _save();
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }
}
