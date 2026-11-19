import 'package:nawy_core/device.dart' show PickedMedia;

import 'api/api_client.dart';

/// Flattens a JSON body into multipart form fields with Laravel bracket
/// names: `{'items': [{'id': 1}]}` → `items[0][id] = 1`; booleans become
/// `1` / `0`; nulls are dropped.
Map<String, String> formFields(Map<String, dynamic> body) {
  final out = <String, String>{};
  void add(String name, Object? value) {
    if (value == null) return;
    if (value is Map) {
      value.forEach((k, v) => add('$name[$k]', v));
    } else if (value is List) {
      for (var i = 0; i < value.length; i++) {
        add('$name[$i]', value[i]);
      }
    } else if (value is bool) {
      out[name] = value ? '1' : '0';
    } else {
      out[name] = '$value';
    }
  }

  body.forEach(add);
  return out;
}

/// POSTs [body] as JSON, or as multipart with [files] under `{field}[]` when
/// any were picked (the API reads both the same way).
Future<ApiResponse<dynamic>> postWithFiles(ApiClient api, String path, Map<String, dynamic> body, {required String field, required List<PickedMedia> files}) {
  if (files.isEmpty) return api.post(path, body: body);
  return api.multipart(path, fields: formFields(body), files: [for (final f in files) f.part('$field[]')]);
}
