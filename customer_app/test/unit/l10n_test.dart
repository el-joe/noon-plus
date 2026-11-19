import 'dart:io';

import 'package:customer_app/l10n/l10n.dart';
import 'package:customer_app/l10n/strings_ar.dart';
import 'package:customer_app/l10n/strings_en.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('EN and AR tables have the same keys', () {
    expect(stringsAr.keys.toSet().difference(stringsEn.keys.toSet()), isEmpty);
    expect(stringsEn.keys.toSet().difference(stringsAr.keys.toSet()), isEmpty);
  });

  test('every literal key used in lib/ exists', () {
    final pattern = RegExp(
        r"'((?:account|address|auth|blocks|cards|cart|categories|checkout|common|compare|contracts|deals|giftCards|header|help|home|live|market|membership|notifications|offers|orders|payment|plp|product|profile|property|qa|returns|reviews|search|security|seller|souq|state|staticPage|support|tabs|travel|wallet|wishlist|sort|status)\.[a-zA-Z][a-zA-Z0-9.]*)'");
    final missing = <String>{};
    for (final file in Directory('lib').listSync(recursive: true).whereType<File>().where((f) => f.path.endsWith('.dart') && !f.path.contains('l10n/strings_'))) {
      for (final m in pattern.allMatches(file.readAsStringSync())) {
        final key = m.group(1)!;
        if (key.endsWith('.dart')) continue;
        if (!stringsEn.containsKey(key)) missing.add('$key (${file.path})');
      }
    }
    expect(missing, isEmpty);
  });

  test('placeholders and fallbacks', () {
    const en = L10n('en');
    const ar = L10n('ar');
    expect(en.t('cart.shipmentOf', {'n': 1, 'total': 2}), 'Shipment 1 of 2');
    expect(ar.t('cart.shipmentOf', {'n': 1, 'total': 2}), 'الشحنة 1 من 2');
    expect(en.t('no.such.key'), 'no.such.key');
    expect(ar.isAr, isTrue);
    expect(en.status('out_for_delivery'), 'Out for delivery');
    expect(ar.status('delivered'), 'تم التوصيل');
    expect(en.status('some_new_state'), 'Some new state');
  });

  test('every Arabic value is non-empty and keeps its placeholders', () {
    final placeholder = RegExp(r'\{[a-z]+\}');
    for (final key in stringsEn.keys) {
      expect(stringsAr[key]!.trim(), isNotEmpty, reason: key);
      final enPh = placeholder.allMatches(stringsEn[key]!).map((m) => m.group(0)).toSet();
      final arPh = placeholder.allMatches(stringsAr[key]!).map((m) => m.group(0)).toSet();
      expect(arPh, enPh, reason: key);
    }
  });
}
