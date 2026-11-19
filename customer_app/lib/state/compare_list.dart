import 'package:flutter/foundation.dart';

/// The products picked for "Compare" (QC-043, `GET products/compare`): at
/// most [max] slugs, in the order they were added. App-wide singleton.
class CompareList extends ValueNotifier<List<String>> {
  CompareList() : super(const []);

  static final CompareList instance = CompareList();

  static const max = 4;

  bool contains(String slug) => value.contains(slug);

  bool get isFull => value.length >= max;

  /// Adds or removes [slug]. Returns false when the list is full.
  bool toggle(String slug) {
    if (contains(slug)) {
      remove(slug);
      return true;
    }
    if (isFull) return false;
    value = [...value, slug];
    return true;
  }

  void remove(String slug) => value = [...value]..remove(slug);

  void clear() => value = const [];
}
