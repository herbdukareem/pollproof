import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';

import 'package:http/http.dart' as http;

/// A request the server answered and refused (validation, permissions). Retrying won't help.
class ApiException implements Exception {
  ApiException(this.status, this.message, [this.errors = const {}, this.body]);
  final int status;
  final String message;
  final Map<String, dynamic> errors;
  final Map<String, dynamic>? body;

  String get first {
    for (final v in errors.values) {
      if (v is List && v.isNotEmpty) return v.first.toString();
    }
    return message;
  }

  /// 408, 429 and 5xx are worth trying again later.
  bool get retryable => status == 408 || status == 429 || status >= 500;

  @override
  String toString() => first;
}

/// No answer at all: no data, no signal, or the server is down. The outbox retries these.
class NetworkException implements Exception {
  NetworkException(this.cause);
  final Object cause;
  @override
  String toString() => 'No connection';
}

class UploadFile {
  UploadFile(this.field, this.filename, this.bytes);
  final String field;
  final String filename;
  final Uint8List bytes;
}

class Api {
  Api(this.baseUrl, {this.token, http.Client? client})
    : _client = client ?? http.Client();

  final http.Client _client;

  String baseUrl;
  String? token;
  static const timeout = Duration(seconds: 25);

  Uri _uri(String path, [Map<String, String>? query]) => Uri.parse(
    '${baseUrl.replaceAll(RegExp(r'/+$'), '')}/api/v1/${path.replaceAll(RegExp(r'^/'), '')}',
  ).replace(queryParameters: query);

  Map<String, String> get _headers => {
    'Accept': 'application/json',
    if (token != null) 'Authorization': 'Bearer $token',
  };

  Future<dynamic> get(String path, [Map<String, String>? query]) =>
      _send(() => _client.get(_uri(path, query), headers: _headers));

  Future<dynamic> post(String path, [Map<String, dynamic>? body]) => _send(
    () => _client.post(
      _uri(path),
      headers: {..._headers, 'Content-Type': 'application/json'},
      body: jsonEncode(body ?? {}),
    ),
  );

  Future<dynamic> patch(String path, Map<String, dynamic> body) => _send(
    () => _client.patch(
      _uri(path),
      headers: {..._headers, 'Content-Type': 'application/json'},
      body: jsonEncode(body),
    ),
  );

  /// Multipart form: nested values like votes[A] are flattened PHP-style.
  Future<dynamic> multipart(
    String path,
    Map<String, dynamic> fields,
    List<UploadFile> files,
  ) => _send(() async {
    final req = http.MultipartRequest('POST', _uri(path))
      ..headers.addAll(_headers);
    void add(String key, dynamic v) {
      if (v == null) return;
      if (v is Map) {
        v.forEach((k, x) => add('$key[$k]', x));
      } else if (v is bool) {
        req.fields[key] = v ? '1' : '0';
      } else {
        req.fields[key] = v.toString();
      }
    }

    fields.forEach(add);
    for (final f in files) {
      req.files.add(
        http.MultipartFile.fromBytes(f.field, f.bytes, filename: f.filename),
      );
    }
    return http.Response.fromStream(await _client.send(req));
  });

  Future<dynamic> _send(Future<http.Response> Function() call) async {
    http.Response res;
    try {
      res = await call().timeout(timeout);
    } on TimeoutException catch (e) {
      throw NetworkException(e);
    } on http.ClientException catch (e) {
      throw NetworkException(e);
    } catch (e) {
      // SocketException and friends on mobile; failed fetch on web.
      throw NetworkException(e);
    }
    dynamic data;
    try {
      data = res.body.isEmpty ? null : jsonDecode(res.body);
    } catch (_) {
      data = null;
    }
    if (res.statusCode >= 200 && res.statusCode < 300) return data;
    final map = data is Map<String, dynamic> ? data : <String, dynamic>{};
    throw ApiException(
      res.statusCode,
      (map['message'] ?? 'Request failed (${res.statusCode})').toString(),
      (map['errors'] as Map?)?.cast<String, dynamic>() ?? {},
      map,
    );
  }
}
