import 'video_files_io.dart'
    if (dart.library.js_interop) 'video_files_web.dart'
    as impl;

/// Recorded videos kept on the phone until the command room has every byte. They live in the
/// app's private documents folder (not the cache, which Android may clear), and are deleted
/// only once the server confirms the upload is complete.
abstract class VideoFiles {
  static bool get supported => impl.supported;

  /// Moves a fresh recording into the keep folder; returns its new path.
  static Future<String> keep(String recordedPath, String id) =>
      impl.keep(recordedPath, id);

  static Future<int> size(String path) => impl.size(path);
  static Future<bool> exists(String path) => impl.exists(path);

  /// SHA-256 of the whole file, read in a background isolate so the screen stays responsive.
  static Future<String> sha256(String path) => impl.sha256(path);

  /// [length] bytes from [offset], or fewer at the end of the file.
  static Future<List<int>> read(String path, int offset, int length) =>
      impl.read(path, offset, length);

  static Future<void> delete(String path) => impl.delete(path);

  /// Bytes taken by videos still waiting on the phone.
  static Future<int> totalKept() => impl.totalKept();
}
