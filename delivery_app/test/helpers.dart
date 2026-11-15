import 'package:delivery_app/l10n/strings.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nawy_core/nawy_core.dart';
import 'package:nawy_core/testing.dart';

Map<String, dynamic> moneyJson(String amount, [String currency = 'AED']) => {'amount': amount, 'currency': currency};

Map<String, dynamic> assignmentJson({String status = 'picked_up', String? cod, bool proofRequired = false, String proofType = 'either', String code = '4817', String? arrivedAt}) => {
      'number': 'DA-1001',
      'status': status,
      'attempt': 1,
      'delivery_code': code,
      'shipment_number': 'NW-AE-1028810-1',
      'order_number': 'NW-AE-1028810',
      'payment_method': cod == null ? 'card' : 'cod',
      'cod_amount': cod == null ? null : moneyJson(cod),
      'pickup': {'seller': 'SoundMax'},
      'customer': {
        'name': 'Huda Khalid',
        'phone': '+971501234567',
        'address': {'building': 'JBR Sadaf 4', 'apartment': 'Apt 905', 'area': 'Dubai Marina', 'city': 'Dubai'},
      },
      'items': [
        {'title': 'Air fryer XL', 'quantity': 1},
        {'title': 'Kitchen scale', 'quantity': 2},
      ],
      'proof': {'required': proofRequired, 'type': proofType, 'has_photo': false, 'has_signature': false},
      'failure_reasons': ['not_home', 'wrong_address', 'refused', 'unreachable', 'cod_not_ready'],
      'failure_reason': null,
      'sla_due_at': '2026-11-15T14:40:00+04:00',
      'arrived_at': arrivedAt,
    };

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
      l10n: L10n(locale, en: deliveryEn, ar: deliveryAr),
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
