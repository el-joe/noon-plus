import 'dart:convert';
import 'dart:js_interop';

import 'api_client.dart';

/// Browser `fetch` transport for the web build (dart:io has no HttpClient on
/// the web). Response headers are limited to the ones the API exposes via
/// CORS (`X-Cart-Token`, `X-Booking-Token`, `Retry-After`).
class WebHttpTransport implements HttpTransport {
  static const _exposed = ['x-cart-token', 'x-booking-token', 'retry-after'];

  @override
  Future<HttpResponseData> send(HttpRequestData request) async {
    final init = _FetchInit(
      method: request.method.toJS,
      headers: request.headers.jsify(),
      body: request.body == null ? null : utf8.decode(request.body!).toJS,
    );
    final response = await _fetch(request.uri.toString().toJS, init).toDart;
    final body = (await response.text().toDart).toDart;
    final headers = <String, String>{};
    for (final name in _exposed) {
      final value = response.headers.get(name.toJS)?.toDart;
      if (value != null) headers[name] = value;
    }
    return HttpResponseData(response.status, body, headers);
  }
}

HttpTransport createTransport() => WebHttpTransport();

@JS('fetch')
external JSPromise<_FetchResponse> _fetch(JSString url, _FetchInit init);

extension type _FetchInit._(JSObject _) implements JSObject {
  external factory _FetchInit({JSString method, JSAny? headers, JSString? body});
}

extension type _FetchResponse._(JSObject _) implements JSObject {
  external int get status;
  external _FetchHeaders get headers;
  external JSPromise<JSString> text();
}

extension type _FetchHeaders._(JSObject _) implements JSObject {
  external JSString? get(JSString name);
}
