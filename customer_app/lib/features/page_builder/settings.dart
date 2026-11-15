import '../../core/json.dart';

/// Tolerant readers for page-builder block `settings` (free-form JSON from
/// Admin → Content → Page builder; keys per docs/design/page-builder-blocks.md).
/// Localised keys may be `title` or `title_en` / `title_ar`, or `{en, ar}`.
class BlockSettings {
  const BlockSettings(this.raw, this.locale);

  final Json raw;
  final String locale;

  String? str(String key, {bool localised = true}) {
    final candidates = localised ? ['${key}_$locale', key] : [key];
    for (final k in candidates) {
      final v = raw[k];
      if (v is String && v.trim().isNotEmpty) return v;
      if (v is num) return v.toString();
      if (v is Map && v[locale] is String) return v[locale] as String;
    }
    return null;
  }

  num number(String key, num fallback) {
    final v = raw[key];
    if (v is num) return v;
    if (v is String) return num.tryParse(v) ?? fallback;
    return fallback;
  }

  bool flag(String key, [bool fallback = false]) {
    final v = raw[key];
    if (v is bool) return v;
    if (v == 1 || v == '1' || v == 'true') return true;
    if (v == 0 || v == '0' || v == 'false') return false;
    return fallback;
  }

  List<Json> list(String key) => raw.l(key);

  List<String> strings(String key) => raw.strings(key);

  BlockSettings child(Json json) => BlockSettings(json, locale);

  List<BlockItem> items(String key) => list(key).map((e) => BlockItem.from(child(e))).toList();

  BlockItem get asItem => BlockItem.from(this);

  String? get title => str('title') ?? str('heading');
  String? get subtitle => str('subtitle');
  String? get viewAll => str('view_all', localised: false) ?? str('view_all_link', localised: false);

  /// `#rrggbb` background, or null.
  String? get background {
    final bg = raw['background'];
    final value = bg is String ? bg : (bg is Map ? bg['color'] : null);
    return value is String && RegExp(r'^#[0-9a-fA-F]{6}$').hasMatch(value) ? value : null;
  }
}

/// One slide / tile / banner in any of the editor's spellings.
class BlockItem {
  const BlockItem({this.image, this.link, this.label, this.caption, this.sticker, this.code});

  final String? image;
  final String? link;
  final String? label;
  final String? caption;
  final String? sticker;
  final String? code;

  factory BlockItem.from(BlockSettings s) {
    final image = s.str('image_m', localised: false) ??
        s.str('image_d', localised: false) ??
        s.str('image', localised: false) ??
        s.str('image_url', localised: false) ??
        s.str('src', localised: false) ??
        s.str('logo', localised: false);
    return BlockItem(
      image: safeImageUrl(image),
      link: s.str('link', localised: false) ?? s.str('url', localised: false) ?? s.str('href', localised: false) ?? s.str('link_url', localised: false),
      label: s.str('label') ?? s.str('title') ?? s.str('name') ?? s.str('text'),
      caption: s.str('caption') ?? s.str('subtitle') ?? s.str('offer'),
      sticker: s.str('sticker') ?? s.str('badge'),
      code: s.str('code', localised: false),
    );
  }

  bool get isEmpty => image == null && (label == null || label!.isEmpty);
}

/// Only absolute http(s) image URLs are loaded in the app.
String? safeImageUrl(String? url) {
  if (url == null) return null;
  return url.startsWith('https://') || url.startsWith('http://') ? url : null;
}
