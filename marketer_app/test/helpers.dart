import 'package:marketer_app/l10n/strings.dart';
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
      l10n: L10n(locale, en: marketerEn, ar: marketerAr),
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

Map<String, dynamic> conversionJson(int id, {String status = 'approved', String amount = '500.00', String commission = '40.00'}) => {
      'id': id,
      'order_number': 'NW-AE-10000$id',
      'campaign': 'Summer audio',
      'amount': moneyJson(amount),
      'commission': moneyJson(commission),
      'status': status,
      'occurred_at': '2026-11-14T12:00:00+04:00',
    };
