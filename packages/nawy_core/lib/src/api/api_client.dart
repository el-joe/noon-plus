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
  HttpResponseData(this.status, this.body, [Map<String, String>? headers, this.bytes]) : headers = headers ?? const {};

  final int status;
  final String body;

  /// The raw body (file downloads such as a payslip PDF); null when the
  /// transport only kept the text.
  final List<int>? bytes;

  /// Lower-cased header names.
  final Map<String, String> headers;
}

abstract class HttpTransport {
  Future<HttpResponseData> send(HttpRequestData request);
}

/// `{error: {code, message, fields}}` (or a transport failure, status 0).
class ApiException implements Exception {
  ApiException(this.status, this.code, this.message, {this.fields = const {}, this.details = const {}, this.retryAfter, this.language});

  final int status;
  final String code;
  final String message;
  final Map<String, List<String>> fields;
  final Json details;

  /// From the `Retry-After` header of a 429 (null when absent).
  final Duration? retryAfter;

  /// `Content-Language` of the error body (`ar` / `en`): [message] is
  /// already translated by the API into this language.
  final String? language;

  bool get isUnauthenticated => status == 401;
  bool get isNotFound => status == 404;
  bool get isNetwork => status == 0;
  bool get isRateLimited => status == 429;

  /// 403 refusals that mean this token can no longer use the app: a token of
  /// another app (`wrong_token`, `no_workspace`) or a suspended account.
  bool get isAuthRejected => status == 403 && authRejectedCodes.contains(code);

  /// 403 `forbidden`: the signed-in staff role may not do this (QC-020).
  bool get isForbiddenRole => status == 403 && code == 'forbidden';

  /// 422 refusal of a lifecycle step: `transition_refused` (wave 5a, QA-025)
  /// or the older `not_allowed`. [message] says why; reload the subject.
  bool get isTransitionRefused => status == 422 && transitionRefusedCodes.contains(code);

  static const transitionRefusedCodes = {'transition_refused', 'not_allowed'};

  static const authRejectedCodes = {'wrong_token', 'no_workspace', 'account_suspended'};

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

  /// `Content-Language` of the answer (`ar` / `en`), when sent.
  String? get language => contentLanguageOf(headers);

  int get page => meta.i('page') ?? meta.i('current_page') ?? 1;
  int get lastPage => meta.i('last_page') ?? 1;
  int? get total => meta.i('total');
  bool get hasMore => page < lastPage;
}

/// `Retry-After` as seconds or an HTTP date (RFC 9110 §10.2.3).
Duration? parseRetryAfter(String? raw, {DateTime? now}) {
  if (raw == null || raw.trim().isEmpty) return null;
  final seconds = int.tryParse(raw.trim());
  if (seconds != null) return Duration(seconds: seconds < 0 ? 0 : seconds);
  final date = _parseHttpDate(raw.trim());
  if (date == null) return null;
  final diff = date.difference(now ?? DateTime.now().toUtc());
  return diff.isNegative ? Duration.zero : diff;
}

DateTime? _parseHttpDate(String raw) {
  // IMF-fixdate: `Wed, 21 Oct 2026 07:28:00 GMT`.
  final m = RegExp(r'^\w{3}, (\d{2}) (\w{3}) (\d{4}) (\d{2}):(\d{2}):(\d{2}) GMT$').firstMatch(raw);
  if (m == null) return null;
  const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  final month = months.indexOf(m.group(2)!) + 1;
  if (month == 0) return null;
  return DateTime.utc(int.parse(m.group(3)!), month, int.parse(m.group(1)!), int.parse(m.group(4)!), int.parse(m.group(5)!), int.parse(m.group(6)!));
}

/// The primary language of a `Content-Language` header (`ar-OM` → `ar`).
String? contentLanguageOf(Map<String, String> headers) {
  final raw = headers['content-language'];
  if (raw == null || raw.trim().isEmpty) return null;
  return raw.split(',').first.trim().split('-').first.toLowerCase();
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
    final retryAfter = status == 429 ? parseRetryAfter(headers['retry-after']) : null;
    final language = contentLanguageOf(headers);
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
      throw ApiException(status, map.str('code', status == 429 ? 'too_many_requests' : 'http_error'), map.str('message', 'Something went wrong.'),
          fields: fields, details: details, retryAfter: retryAfter, language: language);
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
    throw ApiException(status, code, raw.str('message', 'Something went wrong.'), fields: fields, retryAfter: retryAfter, language: language);
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
  ApiClient({required this.baseUrl, required this.transport, this.onUnauthenticated, this.onAuthFailure, DateTime Function()? clock}) : _clock = clock ?? DateTime.now;

  final String baseUrl;
  final HttpTransport transport;
  final DateTime Function() _clock;

  /// Called when a signed-in request returns 401 (token revoked/expired).
  final void Function()? onUnauthenticated;

  /// Called with the error when a signed-in request returns 401 (token
  /// expired after 30/90 days, or revoked) or a 403 [ApiException.isAuthRejected]
  /// (`wrong_token`, `no_workspace`, `account_suspended`). [Session] sets it
  /// to sign out with a message, so the app asks to sign in again.
  void Function(ApiException error)? onAuthFailure;

  /// Every API error, for app-level gates (e.g. the vendor Seller Agreement
  /// screen on 403 `seller_agreement_required`).
  final List<void Function(ApiException error)> errorListeners = [];

  String? market;
  String? token;

  /// UI language sent as `Accept-Language` (routes outside a market follow it).
  String? locale;

  /// `Content-Language` of the last answer (the language API texts are in).
  String? contentLanguage;

  /// 429 `Retry-After`: no new call to the same path before this time.
  final Map<String, DateTime> _retryAt = {};

  /// Seconds left before [path] may be called again after a 429 (0 = now).
  Duration retryWait(String method, String path) {
    final until = _retryAt['$method $path'];
    if (until == null) return Duration.zero;
    final left = until.difference(_clock());
    return left.isNegative ? Duration.zero : left;
  }

  Uri uri(String path, {Map<String, dynamic>? query, bool global = false}) {
    final root = baseUrl.endsWith('/') ? baseUrl.substring(0, baseUrl.length - 1) : baseUrl;
    final clean = path.startsWith('/') ? path.substring(1) : path;
    final prefix = global || market == null ? root : '$root/$market';
    return Uri.parse('$prefix/$clean${buildQuery(query)}');
  }

  Future<ApiResponse<dynamic>> _send(String method, String path, {Map<String, dynamic>? query, List<int>? body, String? contentType, bool global = false}) async {
    final wait = retryWait(method, path);
    if (wait > Duration.zero) {
      // Honour the server's Retry-After without calling it again.
      throw ApiException(429, 'too_many_requests', 'Too many attempts. Please wait and try again.', retryAfter: wait, language: contentLanguage);
    }
    final headers = <String, String>{
      'Accept': 'application/json',
      'Content-Type': ?contentType,
      if (token != null) 'Authorization': 'Bearer $token',
      'Accept-Language': ?locale,
    };
    HttpResponseData response;
    try {
      response = await transport.send(HttpRequestData(method: method, uri: uri(path, query: query, global: global), headers: headers, body: body));
    } on ApiException {
      rethrow;
    } catch (e) {
      throw ApiException(0, 'network_error', 'Check your connection and try again.');
    }
    contentLanguage = contentLanguageOf(response.headers) ?? contentLanguage;
    try {
      return parseEnvelope(response.status, response.body, headers: response.headers);
    } on ApiException catch (e) {
      if (e.isRateLimited) {
        _retryAt['$method $path'] = _clock().add(e.retryAfter ?? const Duration(seconds: 60));
      }
      if (token != null && (e.isUnauthenticated || e.isAuthRejected)) {
        if (e.isUnauthenticated) onUnauthenticated?.call();
        onAuthFailure?.call(e);
      }
      for (final listener in List.of(errorListeners)) {
        listener(e);
      }
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

  /// GET a file (a PDF and the like) as raw bytes. Errors come back as the
  /// usual JSON envelope and throw [ApiException] like any other call.
  Future<ApiFile> download(String path, {Map<String, dynamic>? query}) async {
    final wait = retryWait('GET', path);
    if (wait > Duration.zero) {
      throw ApiException(429, 'too_many_requests', 'Too many attempts. Please wait and try again.', retryAfter: wait, language: contentLanguage);
    }
    HttpResponseData response;
    try {
      response = await transport.send(HttpRequestData(method: 'GET', uri: uri(path, query: query), headers: {
        'Accept': 'application/pdf, application/octet-stream, application/json',
        if (token != null) 'Authorization': 'Bearer $token',
        'Accept-Language': ?locale,
      }));
    } on ApiException {
      rethrow;
    } catch (e) {
      throw ApiException(0, 'network_error', 'Check your connection and try again.');
    }
    if (response.status < 200 || response.status >= 300) {
      try {
        parseEnvelope(response.status, response.body, headers: response.headers);
      } on ApiException catch (e) {
        if (token != null && (e.isUnauthenticated || e.isAuthRejected)) {
          if (e.isUnauthenticated) onUnauthenticated?.call();
          onAuthFailure?.call(e);
        }
        rethrow;
      }
    }
    return ApiFile(response.bytes ?? utf8.encode(response.body), response.headers['content-type']?.split(';').first.trim(), filenameFromDisposition(response.headers['content-disposition']));
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

/// A downloaded file ([ApiClient.download]).
class ApiFile {
  const ApiFile(this.bytes, this.contentType, this.filename);

  final List<int> bytes;
  final String? contentType;

  /// From `Content-Disposition` (`attachment; filename="PS-1.pdf"`), if sent.
  final String? filename;
}

/// The file name of a `Content-Disposition` header, or null.
String? filenameFromDisposition(String? header) {
  if (header == null) return null;
  final star = RegExp(r"filename\*=(?:UTF-8'')?([^;]+)", caseSensitive: false).firstMatch(header);
  if (star != null) return Uri.decodeComponent(star.group(1)!.trim().replaceAll('"', ''));
  final plain = RegExp(r'filename="?([^";]+)"?', caseSensitive: false).firstMatch(header);
  return plain?.group(1)?.trim();
}
