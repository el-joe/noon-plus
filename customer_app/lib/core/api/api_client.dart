import 'dart:convert';

import 'package:nawy_core/device.dart' show FilePart, encodeMultipart;

import '../json.dart';

export 'package:nawy_core/device.dart' show FilePart;

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
  /// another app (`wrong_token`, `no_workspace`), a suspended account, or an
  /// old phone-only account that left before adding its email
  /// (`email_required`: it signs in by phone again and adds the email).
  bool get isAuthRejected => status == 403 && authRejectedCodes.contains(code);

  /// 403 `forbidden`: the signed-in staff role may not do this (QC-020).
  bool get isForbiddenRole => status == 403 && code == 'forbidden';

  static const authRejectedCodes = {'wrong_token', 'no_workspace', 'account_suspended', 'email_required'};

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

/// A successful body that is not wrapped in the envelope (GeoJSON): the
/// decoded JSON as `data`; [ApiException] `invalid_response` when it is not
/// JSON.
ApiResponse<dynamic> parseRaw(int status, String body, {Map<String, String> headers = const {}}) {
  if (body.trim().isEmpty) return ApiResponse<dynamic>(null, const {}, status: status, headers: headers);
  try {
    return ApiResponse<dynamic>(jsonDecode(body), const {}, status: status, headers: headers);
  } on FormatException {
    throw ApiException(status, 'invalid_response', 'The server returned an unexpected response.');
  }
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

/// Typed client for `/api/v1/{market}/…` with Sanctum bearer tokens and the
/// guest cart token (`X-Cart-Token`).
class ApiClient {
  ApiClient({required this.baseUrl, required this.transport, this.onUnauthenticated, this.onAuthFailure, DateTime Function()? clock}) : _clock = clock ?? DateTime.now;

  final String baseUrl;
  final HttpTransport transport;
  final DateTime Function() _clock;

  /// Called when a signed-in request returns 401 (token revoked/expired).
  final void Function()? onUnauthenticated;

  /// Called with the error when a signed-in request returns 401 (the 90-day
  /// token expired or was revoked) or 403 `account_suspended` /
  /// `wrong_token`. [AppState] sets it to sign out with a message.
  void Function(ApiException error)? onAuthFailure;

  String? market;
  String? token;

  /// UI language sent as `Accept-Language` (routes outside a market follow it).
  String? locale;

  /// `Content-Language` of the last answer (the language API texts are in).
  String? contentLanguage;

  /// 429 `Retry-After`: no new call to the same path before this time.
  final Map<String, DateTime> _retryAt = {};

  /// Time left before [path] may be called again after a 429 (zero = now).
  Duration retryWait(String method, String path) {
    final until = _retryAt['$method $path'];
    if (until == null) return Duration.zero;
    final left = until.difference(_clock());
    return left.isNegative ? Duration.zero : left;
  }
  String? cartToken;
  void Function(String token)? onCartToken;

  Uri uri(String path, {Map<String, dynamic>? query, bool global = false}) {
    final root = baseUrl.endsWith('/') ? baseUrl.substring(0, baseUrl.length - 1) : baseUrl;
    final clean = path.startsWith('/') ? path.substring(1) : path;
    final prefix = global || market == null ? root : '$root/$market';
    return Uri.parse('$prefix/$clean${buildQuery(query)}');
  }

  Future<ApiResponse<dynamic>> request(
    String method,
    String path, {
    Map<String, dynamic>? query,
    Object? body,
    Map<String, String>? headers,
    bool global = false,
  }) =>
      _send(method, path,
          query: query,
          body: body == null ? null : utf8.encode(jsonEncode(body)),
          contentType: body == null ? null : 'application/json',
          headers: headers,
          global: global);

  /// POST `multipart/form-data` (review / return photos, ticket attachments,
  /// ad photos, avatar). Nested values use bracket names in [fields]
  /// (`items[0][order_item_id]`); repeated files share a `photos[]` field.
  Future<ApiResponse<dynamic>> multipart(String path, {Map<String, String> fields = const {}, List<FilePart> files = const []}) {
    final encoded = encodeMultipart(fields, files);
    return _send('POST', path, body: encoded.body, contentType: encoded.contentType);
  }

  Future<ApiResponse<dynamic>> _send(
    String method,
    String path, {
    Map<String, dynamic>? query,
    List<int>? body,
    String? contentType,
    Map<String, String>? headers,
    bool global = false,
    bool raw = false,
  }) async {
    final wait = retryWait(method, path);
    if (wait > Duration.zero) {
      // Honour the server's Retry-After without calling it again.
      throw ApiException(429, 'too_many_requests', 'Too many attempts. Please wait and try again.', retryAfter: wait, language: contentLanguage);
    }
    final allHeaders = <String, String>{
      'Accept': 'application/json',
      'Content-Type': ?contentType,
      if (token != null) 'Authorization': 'Bearer $token',
      'X-Cart-Token': ?cartToken,
      'Accept-Language': ?locale,
      ...?headers,
    };
    HttpResponseData response;
    try {
      response = await transport.send(HttpRequestData(
        method: method,
        uri: uri(path, query: query, global: global),
        headers: allHeaders,
        body: body,
      ));
    } on ApiException {
      rethrow;
    } catch (e) {
      throw ApiException(0, 'network_error', 'Check your connection and try again.');
    }
    final newCartToken = response.headers['x-cart-token'];
    if (newCartToken != null && newCartToken.isNotEmpty && newCartToken != cartToken) {
      cartToken = newCartToken;
      onCartToken?.call(newCartToken);
    }
    contentLanguage = contentLanguageOf(response.headers) ?? contentLanguage;
    try {
      if (raw && response.status >= 200 && response.status < 300) return parseRaw(response.status, response.body, headers: response.headers);
      return parseEnvelope(response.status, response.body, headers: response.headers);
    } on ApiException catch (e) {
      if (e.isRateLimited) {
        _retryAt['$method $path'] = _clock().add(e.retryAfter ?? const Duration(seconds: 60));
      }
      if (token != null && (e.isUnauthenticated || e.isAuthRejected)) {
        if (e.isUnauthenticated) onUnauthenticated?.call();
        onAuthFailure?.call(e);
      }
      rethrow;
    }
  }

  Future<ApiResponse<dynamic>> get(String path, {Map<String, dynamic>? query, Map<String, String>? headers, bool global = false}) =>
      request('GET', path, query: query, headers: headers, global: global);
  Future<ApiResponse<dynamic>> post(String path, {Object? body, Map<String, String>? headers}) => request('POST', path, body: body ?? const <String, dynamic>{}, headers: headers);
  Future<ApiResponse<dynamic>> put(String path, {Object? body}) => request('PUT', path, body: body ?? const <String, dynamic>{});
  Future<ApiResponse<dynamic>> patch(String path, {Object? body}) => request('PATCH', path, body: body ?? const <String, dynamic>{});
  Future<ApiResponse<dynamic>> delete(String path, {Object? body}) => request('DELETE', path, body: body);

  /// GET an endpoint that answers without the `{data, meta}` envelope
  /// (`classifieds/map` is plain GeoJSON). Errors still use the envelope.
  Future<ApiResponse<dynamic>> getRaw(String path, {Map<String, dynamic>? query}) => _send('GET', path, query: query, raw: true);

  /// POST JSON to an endpoint that answers without the envelope
  /// (`broadcasting/auth` answers `{auth}`). Errors still use the envelope.
  Future<ApiResponse<dynamic>> postRaw(String path, {Object? body}) =>
      _send('POST', path, body: utf8.encode(jsonEncode(body ?? const <String, dynamic>{})), contentType: 'application/json', raw: true);

  /// `data` as an object (empty map when it is not one).
  Future<Json> getObject(String path, {Map<String, dynamic>? query, Map<String, String>? headers}) async => asJson((await get(path, query: query, headers: headers)).data);

  /// `data` as a list of objects.
  Future<ApiResponse<List<Json>>> getList(String path, {Map<String, dynamic>? query}) async {
    final res = await get(path, query: query);
    return ApiResponse(asJsonList(res.data), res.meta, status: res.status, headers: res.headers);
  }
}

String enc(String segment) => Uri.encodeComponent(segment);
