import 'dart:io';

import 'package:path_provider/path_provider.dart';

import 'store.dart';

Store createStore() => _FileStore();

/// One file per key in the app's private documents folder. Writes go to a temp file first,
/// then rename, so a phone dying mid-write never leaves a half-written outbox.
class _FileStore implements Store {
  Directory? _dir;

  Future<File> _file(String key) async {
    _dir ??= Directory(
      '${(await getApplicationDocumentsDirectory()).path}/pollproof',
    )..createSync(recursive: true);
    return File(
      '${_dir!.path}/${key.replaceAll(RegExp(r'[^a-zA-Z0-9_.-]'), '_')}',
    );
  }

  @override
  Future<String?> read(String key) async {
    final f = await _file(key);
    return f.existsSync() ? f.readAsString() : null;
  }

  @override
  Future<void> write(String key, String value) async {
    final f = await _file(key);
    final tmp = File('${f.path}.tmp');
    await tmp.writeAsString(value, flush: true);
    await tmp.rename(f.path);
  }

  @override
  Future<void> delete(String key) async {
    final f = await _file(key);
    if (f.existsSync()) await f.delete();
  }
}
