import 'dart:convert';

import '../json.dart';

/// One HTTP exchange, independent of the platform HTTP stack so the client can
/// be tested with a fake transport (see `transport_io.dart` for dart:io).
class HttpRequestData {
  HttpRequestData({required this.method, required this.uri, required this.headers, this.body});

  final String method;
  final Uri uri;
  final Map<String, String> headers;
  final List<int>? body;
}

class HttpResponseData {
  HttpResponseData(this.status, this.body, [Map<String, String>? headers]) : headers = headers ?? const {};

  final int status;
  final String body;

  /// Lower-cased header names.
  final Map<String, String> headers;
}

abstract class HttpTransport {
  Future<HttpResponseData> send(HttpRequestData request);
}

/// `{error: {code, message, fields}}` (or a transport failure, status 0).
class ApiException implements Exception {
  ApiException(this.status, this.code, this.message, {this.fields = const {}, this.details = const {}});

  final int status;
  final String code;
  final String message;
  final Map<String, List<String>> fields;
  final Json details;

  bool get isUnauthenticated => status == 401;
  bool get isNotFound => status == 404;
  bool get isNetwork => status == 0;

  /// First message per field for inline form errors.
  Map<String, String> get fieldErrors => {
        for (final entry in fields.entries)
          if (entry.value.isNotEmpty) entry.key: entry.value.first,
      };

  @override
  String toString() => 'ApiException($status, $code, $message)';
}

/// `{data, meta}`.
class ApiResponse<T> {
  ApiResponse(this.data, this.meta, {this.status = 200, this.headers = const {}});

  final T data;
  final Json meta;
  final int status;
  final Map<String, String> headers;

  int get page => meta.i('page') ?? meta.i('current_page') ?? 1;
  int get lastPage => meta.i('last_page') ?? 1;
  int? get total => meta.i('total');
  bool get hasMore => page < lastPage;
}

/// Parse a response body into the envelope; throws [ApiException] on an
/// error status or an unexpected shape. Pure, so it is unit tested.
ApiResponse<dynamic> parseEnvelope(int status, String body, {Map<String, String> headers = const {}}) {
  dynamic decoded;
  if (body.trim().isNotEmpty) {
    try {
      decoded = jsonDecode(body);
    } on FormatException {
      decoded = null;
    }
  }

  if (status < 200 || status >= 300) {
    final error = decoded is Map ? decoded['error'] : null;
    if (error is Map) {
      final map = error.cast<String, dynamic>();
      final fields = <String, List<String>>{};
      final rawFields = map['fields'];
      if (rawFields is Map) {
        rawFields.forEach((key, value) {
          if (value is List) {
            fields[key.toString()] = value.map((e) => e.toString()).toList();
          } else if (value != null) {
            fields[key.toString()] = [value.toString()];
          }
        });
      }
      final details = Map<String, dynamic>.from(map)..removeWhere((k, _) => k == 'code' || k == 'message' || k == 'fields');
      throw ApiException(status, map.str('code', 'http_error'), map.str('message', 'Something went wrong.'), fields: fields, details: details);
    }
    // Laravel's own bodies ({message, errors}) or empty bodies.
    final raw = decoded is Map ? decoded.cast<String, dynamic>() : <String, dynamic>{};
    final code = switch (status) {
      401 => 'unauthenticated',
      403 => 'forbidden',
      404 => 'not_found',
      422 => 'validation_failed',
      429 => 'too_many_requests',
      _ => 'http_error',
    };
    final fields = <String, List<String>>{};
    final errors = raw['errors'];
    if (errors is Map) {
      errors.forEach((k, v) => fields[k.toString()] = v is List ? v.map((e) => e.toString()).toList() : [v.toString()]);
    }
    throw ApiException(status, code, raw.str('message', 'Something went wrong.'), fields: fields);
  }

  if (status == 204 || decoded == null) {
    return ApiResponse<dynamic>(null, const {}, status: status, headers: headers);
  }
  if (decoded is! Map || !decoded.containsKey('data')) {
    throw ApiException(status, 'invalid_response', 'The server returned an unexpected response.');
  }
  final meta = decoded['meta'];
  return ApiResponse<dynamic>(decoded['data'], meta is Map ? meta.cast<String, dynamic>() : <String, dynamic>{}, status: status, headers: headers);
}

/// Encode query parameters, dropping nulls and empty strings.
String buildQuery(Map<String, dynamic>? query) {
  if (query == null || query.isEmpty) return '';
  final parts = <String>[];
  query.forEach((key, value) {
    if (value == null) return;
    final text = value is bool ? (value ? '1' : '0') : value.toString();
    if (text.isEmpty) return;
    parts.add('${Uri.encodeQueryComponent(key)}=${Uri.encodeQueryComponent(text)}');
  });
  return parts.isEmpty ? '' : '?${parts.join('&')}';
}
/// A file part for [ApiClient.multipart] (e.g. a proof photo).
class FilePart {
  const FilePart({required this.field, required this.filename, required this.bytes, this.contentType = 'image/png'});

  final String field;
  final String filename;
  final List<int> bytes;
  final String contentType;
}

/// Build a `multipart/form-data` body (no `http` package; project rule).
/// Returns the content-type header value and the body bytes. Pure, tested.
({String contentType, List<int> body}) encodeMultipart(Map<String, String> fields, List<FilePart> files, {String? boundary}) {
  final b = boundary ?? 'nawy-${DateTime.now().microsecondsSinceEpoch}';
  final out = <int>[];
  void line(String text) => out.addAll(utf8.encode('$text\r\n'));
  fields.forEach((name, value) {
    line('--$b');
    line('Content-Disposition: form-data; name="$name"');
    line('');
    line(value);
  });
  for (final file in files) {
    line('--$b');
    line('Content-Disposition: form-data; name="${file.field}"; filename="${file.filename}"');
    line('Content-Type: ${file.contentType}');
    line('');
    out.addAll(file.bytes);
    out.addAll(utf8.encode('\r\n'));
  }
  line('--$b--');
  return (contentType: 'multipart/form-data; boundary=$b', body: out);
}

/// Typed client for `/api/v1/{market}/…` with a Sanctum bearer token.
class ApiClient {
  ApiClient({required this.baseUrl, required this.transport, this.onUnauthenticated});

  final String baseUrl;
  final HttpTransport transport;

  /// Called when a signed-in request returns 401 (token revoked/expired).
  final void Function()? onUnauthenticated;

  String? market;
  String? token;

  Uri uri(String path, {Map<String, dynamic>? query, bool global = false}) {
    final root = baseUrl.endsWith('/') ? baseUrl.substring(0, baseUrl.length - 1) : baseUrl;
    final clean = path.startsWith('/') ? path.substring(1) : path;
    final prefix = global || market == null ? root : '$root/$market';
    return Uri.parse('$prefix/$clean${buildQuery(query)}');
  }

  Future<ApiResponse<dynamic>> _send(String method, String path, {Map<String, dynamic>? query, List<int>? body, String? contentType, bool global = false}) async {
    final headers = <String, String>{
      'Accept': 'application/json',
      'Content-Type': ?contentType,
      if (token != null) 'Authorization': 'Bearer $token',
    };
    HttpResponseData response;
    try {
      response = await transport.send(HttpRequestData(method: method, uri: uri(path, query: query, global: global), headers: headers, body: body));
    } on ApiException {
      rethrow;
    } catch (e) {
      throw ApiException(0, 'network_error', 'Check your connection and try again.');
    }
    try {
      return parseEnvelope(response.status, response.body, headers: response.headers);
    } on ApiException catch (e) {
      if (e.isUnauthenticated && token != null) onUnauthenticated?.call();
      rethrow;
    }
  }

  Future<ApiResponse<dynamic>> request(String method, String path, {Map<String, dynamic>? query, Object? body, bool global = false}) =>
      _send(method, path, query: query, body: body == null ? null : utf8.encode(jsonEncode(body)), contentType: body == null ? null : 'application/json', global: global);

  Future<ApiResponse<dynamic>> get(String path, {Map<String, dynamic>? query, bool global = false}) => request('GET', path, query: query, global: global);
  Future<ApiResponse<dynamic>> post(String path, {Object? body}) => request('POST', path, body: body ?? const <String, dynamic>{});
  Future<ApiResponse<dynamic>> patch(String path, {Object? body}) => request('PATCH', path, body: body ?? const <String, dynamic>{});
  Future<ApiResponse<dynamic>> delete(String path) => request('DELETE', path);

  /// POST `multipart/form-data` (proof photo / signature files).
  Future<ApiResponse<dynamic>> multipart(String path, {Map<String, String> fields = const {}, List<FilePart> files = const []}) {
    final encoded = encodeMultipart(fields, files);
    return _send('POST', path, body: encoded.body, contentType: encoded.contentType);
  }

  /// `data` as an object (empty map when it is not one).
  Future<Json> getObject(String path, {Map<String, dynamic>? query}) async => asJson((await get(path, query: query)).data);

  /// `data` as a list of objects.
  Future<ApiResponse<List<Json>>> getList(String path, {Map<String, dynamic>? query}) async {
    final res = await get(path, query: query);
    return ApiResponse(asJsonList(res.data), res.meta, status: res.status, headers: res.headers);
  }
}

String enc(String segment) => Uri.encodeComponent(segment);
