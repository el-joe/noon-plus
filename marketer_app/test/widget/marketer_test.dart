import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:marketer_app/app.dart';
import 'package:marketer_app/screens/campaigns.dart';
import 'package:marketer_app/screens/conversions.dart';
import 'package:marketer_app/screens/dashboard.dart';
import 'package:marketer_app/screens/links.dart';
import 'package:nawy_core/nawy_core.dart' show HttpRequestData;
import 'package:nawy_core/testing.dart';

import '../helpers.dart';

final dashboardJson = {
  'profile': {'id': 3, 'name': 'Pulse', 'types': ['influencer'], 'code': 'PULSE10'},
  'kpis': {'clicks': 200, 'conversions': 7, 'sales': moneyJson('3500.00'), 'earnings': moneyJson('280.00'), 'active_campaigns': 2},
};

final campaignJson = {
  'id': 11,
  'campaign_id': 4,
  'name': 'Summer audio',
  'status': 'active',
  'seller': 'SoundMax',
  'commission': '8%',
  'code': 'PULSE10',
  'starts_at': '2026-11-01T00:00:00+04:00',
  'ends_at': '2026-11-30T00:00:00+04:00',
  'link': 'https://nawy.test/uae-en/r/PULSE10',
  'conversions': 5,
};

void main() {
  late List<String> clipboard;
  setUp(() {
    clipboard = [];
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger.setMockMethodCallHandler(SystemChannels.platform, (call) async {
      if (call.method == 'Clipboard.setData') clipboard.add((call.arguments as Map)['text'] as String);
      return null;
    });
  });

  testWidgets('sign in sends role marketer', (tester) async {
    final transport = FakeTransport({
      'POST ops/auth/login': {'token': 'tok', 'user': {'id': 1, 'name': 'Pulse'}, 'workspace': {'role': 'marketer', 'id': 3, 'name': 'Pulse'}},
      'GET ops/marketer/dashboard': dashboardJson,
    });
    final session = await testSession(transport, signedIn: false);
    await tester.pumpWidget(MarketerApp(session: session));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('email')), 'pulse@nawy.test');
    await tester.enterText(find.byKey(const Key('password')), 'secret');
    await tester.tap(find.byKey(const Key('sign-in')));
    await tester.pumpAndSettle();
    expect(FakeTransport.bodyOf(transport.sent('POST ops/auth/login').single)['role'], 'marketer');
    expect(find.byType(DashboardScreen), findsOneWidget);
  });

  testWidgets('no workspace error is shown', (tester) async {
    final transport = FakeTransport({'POST ops/auth/login': FakeTransport.error(403, 'no_workspace', 'This account has no marketer workspace in this country.')});
    final session = await testSession(transport, signedIn: false);
    await tester.pumpWidget(MarketerApp(session: session));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('email')), 'x@nawy.test');
    await tester.enterText(find.byKey(const Key('password')), 'secret');
    await tester.tap(find.byKey(const Key('sign-in')));
    await tester.pumpAndSettle();
    expect(find.text('This account has no marketer workspace in this country.'), findsOneWidget);
  });

  testWidgets('dashboard KPIs with money, conversion rate and code', (tester) async {
    int? tab;
    await pumpScreen(tester, DashboardScreen(onOpen: (i) => tab = i), FakeTransport({'GET ops/marketer/dashboard': dashboardJson}));
    expect(find.text('AED 280.00'), findsOneWidget);
    expect(find.text('AED 3,500.00'), findsOneWidget);
    expect(find.text('4%'), findsOneWidget);
    expect(find.text('Your code: PULSE10'), findsOneWidget);
    expect(find.text('Influencer'), findsOneWidget);
    await tester.tap(find.byKey(const Key('action-links')));
    expect(tab, 2);
  });

  testWidgets('campaigns render and the link copies', (tester) async {
    await pumpScreen(tester, const CampaignsScreen(), FakeTransport({'GET ops/marketer/campaigns': [campaignJson]}));
    expect(find.text('Summer audio'), findsOneWidget);
    expect(find.text('SoundMax'), findsOneWidget);
    expect(find.text('You earn: 8%'), findsOneWidget);
    expect(find.text('5 sales'), findsOneWidget);
    await tester.tap(find.byKey(const Key('copy-11')));
    await tester.pump();
    expect(clipboard, ['https://nawy.test/uae-en/r/PULSE10']);
    expect(find.text('Copied'), findsOneWidget);
  });

  testWidgets('links: main link copies; empty state without links', (tester) async {
    await pumpScreen(
      tester,
      const LinksScreen(),
      FakeTransport({
        'GET ops/marketer/links': {
          'referral_link': 'https://nawy.test/uae-en/r/PULSE10',
          'links': [
            {'invitation_id': 11, 'campaign': 'Summer audio', 'code': 'PULSE10', 'url': 'https://nawy.test/uae-en/r/PULSE10', 'clicks': 120, 'conversions': 5},
          ],
        },
      }),
    );
    expect(find.textContaining('120 clicks · 5 sales'), findsOneWidget);
    await tester.tap(find.byKey(const Key('copy-main')));
    await tester.pump();
    expect(clipboard.single, 'https://nawy.test/uae-en/r/PULSE10');
  });

  testWidgets('conversions page and load more', (tester) async {
    final transport = FakeTransport({
      'GET ops/marketer/conversions': (HttpRequestData r) {
        final page = int.parse(r.uri.queryParameters['page'] ?? '1');
        return FakeTransport.ok(page == 1 ? [conversionJson(1), conversionJson(2, status: 'reversed')] : [conversionJson(3, commission: '10.00')],
            meta: {'current_page': page, 'last_page': 2, 'total': 3});
      },
    });
    await pumpScreen(tester, const ConversionsScreen(), transport);
    expect(find.text('NW-AE-100001'), findsOneWidget);
    expect(find.text('Commission on these sales: AED 40.00'), findsOneWidget);
    await tester.tap(find.byKey(const Key('load-more')));
    await tester.pumpAndSettle();
    expect(find.text('NW-AE-100003'), findsOneWidget);
    expect(find.text('Commission on these sales: AED 50.00'), findsOneWidget);
    expect(find.byKey(const Key('load-more')), findsNothing);
  });

  testWidgets('Arabic is right to left', (tester) async {
    await pumpScreen(tester, DashboardScreen(onOpen: (_) {}), FakeTransport({'GET ops/marketer/dashboard': dashboardJson}), locale: 'ar');
    expect(find.text('أرباحك'), findsOneWidget);
    expect(find.text('280.00 AED'), findsOneWidget);
    expect(Directionality.of(tester.element(find.text('أرباحك'))), TextDirection.rtl);
  });
}
