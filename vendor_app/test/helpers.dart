import 'package:vendor_app/l10n/strings.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nawy_core/nawy_core.dart';
import 'package:nawy_core/testing.dart';

Map<String, dynamic> moneyJson(String amount, [String currency = 'AED']) => {'amount': amount, 'currency': currency};

Future<Session> testSession(FakeTransport transport, {String locale = 'en', bool signedIn = true}) async {
  final store = MemorySecureStore({'country': 'uae', 'locale': locale, if (signedIn) 'token': 'test-token'});
  final api = ApiClient(baseUrl: 'http://api.test/api/v1', transport: transport);
  final session = Session(api: api, store: store);
  await session.load();
  return session;
}

/// Pumps [child] inside the app's scopes (session, EN/AR tables, theme).
Future<void> pumpScreen(WidgetTester tester, Widget child, FakeTransport transport, {String locale = 'en'}) async {
  tester.view.physicalSize = const Size(1080, 2400);
  tester.view.devicePixelRatio = 2.6;
  addTearDown(tester.view.reset);
  final session = await testSession(transport, locale: locale);
  await tester.pumpWidget(SessionScope(
    session: session,
    child: L10nScope(
      l10n: L10n(locale, en: vendorEn, ar: vendorAr),
      child: MaterialApp(
        theme: buildNawyTheme(),
        locale: Locale(locale),
        supportedLocales: const [Locale('en'), Locale('ar')],
        localizationsDelegates: GlobalMaterialLocalizations.delegates,
        home: child,
      ),
    ),
  ));
  await tester.pumpAndSettle();
}

Map<String, dynamic> orderJson({int id = 7, String status = 'placed', String? next = 'confirmed', bool canCancel = true}) => {
      'id': id,
      'number': 'NW-AE-1000007-1',
      'status': status,
      'placed_at': '2026-11-15T09:00:00+04:00',
      'items_count': 2,
      'total': moneyJson('249.00'),
      'next_step': next,
      'next_step_label': next == null ? null : 'Confirm order',
      'can_cancel': canCancel,
      'items': [
        {'title': 'Nova X12', 'sku': 'NX12-BLK', 'quantity': 1, 'unit_price': moneyJson('199.00')},
        {'title': 'Case', 'sku': 'CASE-1', 'quantity': 2, 'unit_price': moneyJson('25.00')},
      ],
    };

Map<String, dynamic> stockJson({int id = 31, int stock = 3}) =>
    {'listing_id': id, 'title': 'Nova X12', 'sku': 'NX12-BLK', 'stock': stock, 'low_stock_threshold': 5, 'status': 'active'};
