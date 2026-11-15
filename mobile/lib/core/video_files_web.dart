// The web build (for demos) has no file system to keep videos in.
const supported = false;

Never _no() => throw UnsupportedError('Video recording needs the Android app.');

Future<String> keep(String recordedPath, String id) async => _no();
Future<int> size(String path) async => _no();
Future<bool> exists(String path) async => false;
Future<String> sha256(String path) async => _no();
Future<List<int>> read(String path, int offset, int length) async => _no();
Future<void> delete(String path) async {}
Future<int> totalKept() async => 0;
