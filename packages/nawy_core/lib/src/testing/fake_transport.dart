import 'dart:convert';

import '../api/api_client.dart';
import '../json.dart';

typedef Handler = HttpResponseData Function(HttpRequestData request);

/// In-memory transport: routes are `'GET delivery/me'` (path after the
/// market). Unknown GETs return an empty list, other unknown calls 404.
class FakeTransport implements HttpTransport {
  FakeTransport([Map<String, Object>? routes]) : routes = {...?routes};

  final Map<String, Object> routes;
  final List<HttpRequestData> requests = [];

  static HttpResponseData ok(Object? data, {Map<String, dynamic> meta = const {}, int status = 200}) =>
      HttpResponseData(status, jsonEncode({'data': data, 'meta': meta}));

  static HttpResponseData error(int status, String code, String message, [Map<String, List<String>> fields = const {}]) =>
      HttpResponseData(status, jsonEncode({'error': {'code': code, 'message': message, 'fields': fields}}));

  String keyFor(HttpRequestData r) {
    final segments = r.uri.pathSegments;
    final v1 = segments.indexOf('v1');
    final rest = segments.sublist(v1 + 1);
    final market = rest.isNotEmpty && RegExp(r'^[a-z]{3}-(en|ar)$').hasMatch(rest.first);
    return '${r.method} ${(market ? rest.sublist(1) : rest).join('/')}';
  }

  /// JSON body of a request (empty for multipart / no body).
  static Json bodyOf(HttpRequestData r) {
    if (r.body == null) return {};
    try {
      return asJson(jsonDecode(utf8.decode(r.body!)));
    } catch (_) {
      return {};
    }
  }

  List<HttpRequestData> sent(String key) => requests.where((r) => keyFor(r) == key).toList();

  @override
  Future<HttpResponseData> send(HttpRequestData request) async {
    requests.add(request);
    final key = keyFor(request);
    final route = routes[key];
    if (route is Handler) return route(request);
    if (route is HttpResponseData) return route;
    if (route != null) return ok(route);
    if (request.method == 'GET') return ok(const []);
    return error(404, 'not_found', 'Not found: $key');
  }
}
