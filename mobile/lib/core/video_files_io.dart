import 'dart:io';
import 'dart:isolate';

import 'package:crypto/crypto.dart' as hash;
import 'package:path_provider/path_provider.dart';

const supported = true;

Future<Directory> _dir() async {
  final d = Directory(
    '${(await getApplicationDocumentsDirectory()).path}/pollproof/videos',
  );
  if (!d.existsSync()) d.createSync(recursive: true);
  return d;
}

Future<String> keep(String recordedPath, String id) async {
  final src = File(recordedPath);
  final ext = recordedPath.contains('.')
      ? recordedPath.substring(recordedPath.lastIndexOf('.'))
      : '.mp4';
  final dest = '${(await _dir()).path}/$id$ext';
  try {
    await src.rename(dest);
  } on FileSystemException {
    // The recording may be on another volume (the cache); copy, then remove the original.
    await src.copy(dest);
    await src.delete();
  }
  return dest;
}

Future<int> size(String path) => File(path).length();

Future<bool> exists(String path) => File(path).exists();

Future<String> sha256(String path) => Isolate.run(() async {
  final digest = await hash.sha256.bind(File(path).openRead()).first;
  return digest.toString();
});

Future<List<int>> read(String path, int offset, int length) async {
  final f = await File(path).open();
  try {
    await f.setPosition(offset);
    return await f.read(length);
  } finally {
    await f.close();
  }
}

Future<void> delete(String path) async {
  final f = File(path);
  if (f.existsSync()) await f.delete();
}

Future<int> totalKept() async {
  var total = 0;
  await for (final e in (await _dir()).list()) {
    if (e is File) total += await e.length();
  }
  return total;
}
