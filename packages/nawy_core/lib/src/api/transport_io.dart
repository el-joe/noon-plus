import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'api_client.dart';

/// dart:io transport (Android, iOS, desktop). No pub packages (project rule).
class IoHttpTransport implements HttpTransport {
  IoHttpTransport({Duration timeout = const Duration(seconds: 20)}) : _timeout = timeout {
    _client.connectionTimeout = timeout;
  }

  final HttpClient _client = HttpClient();
  final Duration _timeout;

  @override
  Future<HttpResponseData> send(HttpRequestData request) async {
    final req = await _client.openUrl(request.method, request.uri).timeout(_timeout);
    request.headers.forEach(req.headers.set);
    if (request.body != null) {
      req.contentLength = request.body!.length;
      req.add(request.body!);
    }
    final res = await req.close().timeout(_timeout);
    final bytes = await res.fold<List<int>>(<int>[], (all, chunk) => all..addAll(chunk)).timeout(_timeout);
    final headers = <String, String>{};
    res.headers.forEach((name, values) => headers[name.toLowerCase()] = values.join(','));
    return HttpResponseData(res.statusCode, utf8.decode(bytes, allowMalformed: true), headers, bytes);
  }
}

HttpTransport createTransport() => IoHttpTransport();
