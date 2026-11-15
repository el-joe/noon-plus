/// Tolerant readers for decoded JSON (`Map<String, dynamic>` / `List`).
///
/// The API is versioned but optional keys may be missing or null; these
/// helpers never throw on a wrong type, they return null / empty instead.
typedef Json = Map<String, dynamic>;

extension JsonRead on Map<String, dynamic> {
  String? s(String key) {
    final v = this[key];
    if (v == null) return null;
    if (v is String) return v;
    if (v is num || v is bool) return v.toString();
    return null;
  }

  String str(String key, [String fallback = '']) => s(key) ?? fallback;

  int? i(String key) {
    final v = this[key];
    if (v is int) return v;
    if (v is num) return v.toInt();
    if (v is String) return int.tryParse(v);
    return null;
  }

  double? d(String key) {
    final v = this[key];
    if (v is num) return v.toDouble();
    if (v is String) return double.tryParse(v);
    return null;
  }

  bool b(String key, [bool fallback = false]) {
    final v = this[key];
    if (v is bool) return v;
    if (v is num) return v != 0;
    if (v is String) return v == '1' || v == 'true';
    return fallback;
  }

  Json? m(String key) {
    final v = this[key];
    if (v is Map) return v.cast<String, dynamic>();
    return null;
  }

  List<Json> l(String key) => asJsonList(this[key]);

  List<String> strings(String key) {
    final v = this[key];
    if (v is List) return v.where((e) => e != null).map((e) => e.toString()).where((e) => e.trim().isNotEmpty).toList();
    if (v is String) return v.split(RegExp(r'[\n,]')).map((e) => e.trim()).where((e) => e.isNotEmpty).toList();
    return const [];
  }
}

/// A list of objects, skipping anything that is not a map.
List<Json> asJsonList(dynamic value) {
  if (value is List) {
    return value.whereType<Map>().map((e) => e.cast<String, dynamic>()).toList();
  }
  return const [];
}

Json asJson(dynamic value) => value is Map ? value.cast<String, dynamic>() : <String, dynamic>{};
