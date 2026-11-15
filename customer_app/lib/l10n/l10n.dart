import 'package:flutter/widgets.dart';

import 'strings_ar.dart';
import 'strings_en.dart';

/// Hand-written EN/AR string tables (no `intl` / ARB codegen; project rule).
/// Keys are dotted (`cart.title`). `{name}` placeholders are replaced from
/// [params]. A missing Arabic key falls back to English, then to the key.
class L10n {
  const L10n(this.locale);

  final String locale;

  bool get isAr => locale == 'ar';
  TextDirection get direction => isAr ? TextDirection.rtl : TextDirection.ltr;

  static const tables = <String, Map<String, String>>{'en': stringsEn, 'ar': stringsAr};

  String t(String key, [Map<String, Object?>? params]) {
    var text = tables[locale]?[key] ?? stringsEn[key] ?? key;
    params?.forEach((name, value) => text = text.replaceAll('{$name}', '${value ?? ''}'));
    return text;
  }

  /// Status labels from the API (`out_for_delivery` → "Out for delivery").
  String status(String? raw) {
    if (raw == null || raw.isEmpty) return '';
    final key = 'status.$raw';
    final known = tables[locale]?[key] ?? stringsEn[key];
    if (known != null) return known;
    final words = raw.replaceAll(RegExp(r'[_.\-]'), ' ');
    return words[0].toUpperCase() + words.substring(1);
  }

  static L10n of(BuildContext context) => context.dependOnInheritedWidgetOfExactType<L10nScope>()?.l10n ?? const L10n('en');
}

class L10nScope extends InheritedWidget {
  const L10nScope({super.key, required this.l10n, required super.child});

  final L10n l10n;

  @override
  bool updateShouldNotify(L10nScope oldWidget) => oldWidget.l10n.locale != l10n.locale;
}

extension L10nContext on BuildContext {
  L10n get l10n => L10n.of(this);
  String t(String key, [Map<String, Object?>? params]) => L10n.of(this).t(key, params);
}
