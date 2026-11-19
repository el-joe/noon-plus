import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nawy_core/nawy_core.dart';
import 'package:nawy_core/testing.dart';
import 'package:travel_agency_app/data/travel_api.dart';
import 'package:travel_agency_app/l10n/strings.dart';
import 'package:travel_agency_app/screens/promotion_checkout.dart';
import 'package:travel_agency_app/screens/promotions.dart';
import 'package:travel_agency_app/screens/shell.dart';

import '../helpers.dart';

const buyUrl = 'https://partners.nawy.test/omn-en/travel/promotions/buy';

Map<String, dynamic> promotion(int id, String status, String label, {String amount = '25.500', String currency = 'OMR', String? startsAt, String? endsAt}) => {
      'id': id,
      'number': 'PR-$id',
      'status': status,
      'status_label': label,
      'package': {'id': 7, 'title': 'Salalah Khareef 4 days'},
      'product': {'code': 'travel_top_7', 'name': 'Top of travel list', 'duration_days': 7},
      'amount': moneyJson(amount, currency),
      'starts_at': startsAt,
      'ends_at': endsAt,
      'created_at': '2026-11-10T08:00:00Z',
    };

HttpResponseData promotionsResponse(List<Map<String, dynamic>> rows, {String url = buyUrl}) => FakeTransport.ok(rows, meta: {'buy_url': url});

String? textOf(WidgetTester tester, String key) => tester.widget<Text>(find.byKey(Key(key))).data;

void main() {

  test('promotion statuses map onto chip colours', () {
    expect(promotionChipStatus('pending_payment'), 'pending');
    expect(promotionChipStatus('active'), 'active');
    expect(promotionChipStatus('expired'), 'expired');
  });

  testWidgets('lists promotions with status, product, 3-decimal money and dates', (tester) async {
    final transport = FakeTransport({
      'GET ops/travel/promotions': promotionsResponse([
        promotion(1, 'active', 'Active', startsAt: '2026-11-11T00:00:00Z', endsAt: '2026-11-18T00:00:00Z'),
        promotion(2, 'pending_payment', 'Awaiting payment', amount: '12.5', currency: 'KWD'),
        promotion(3, 'expired', 'Expired', amount: '99', currency: 'AED', startsAt: '2026-10-01T00:00:00Z', endsAt: '2026-10-08T00:00:00Z'),
        promotion(4, 'refunded', 'Refunded'),
      ]),
    });
    await pumpScreen(tester, const PromotionsScreen(), transport);

    expect(find.descendant(of: find.byKey(const Key('promotion-status-1')), matching: find.text('Active')), findsOneWidget);
    expect(find.descendant(of: find.byKey(const Key('promotion-status-2')), matching: find.text('Awaiting payment')), findsOneWidget);
    expect(find.descendant(of: find.byKey(const Key('promotion-status-3')), matching: find.text('Expired')), findsOneWidget);
    expect(find.descendant(of: find.byKey(const Key('promotion-status-4')), matching: find.text('Refunded')), findsOneWidget);
    expect(find.text('Salalah Khareef 4 days'), findsNWidgets(4));
    expect(find.text('PR-1 · Top of travel list · 7 days'), findsOneWidget);

    expect(textOf(tester, 'promotion-amount-1'), 'OMR 25.500');
    expect(textOf(tester, 'promotion-amount-2'), 'KWD 12.500');
    expect(textOf(tester, 'promotion-amount-3'), 'AED 99.00');

    expect(textOf(tester, 'promotion-dates-2'), 'Starts once paid');
    expect(textOf(tester, 'promotion-dates-1'), contains('→'));
  });

  testWidgets('empty state still offers to promote a trip', (tester) async {
    await pumpScreen(tester, const PromotionsScreen(), FakeTransport({'GET ops/travel/promotions': promotionsResponse([])}));
    expect(find.text('No promotions yet'), findsOneWidget);
    expect(find.byKey(const Key('promote')), findsOneWidget);
  });

  testWidgets('"Promote a trip" opens the in-app purchase, not the browser', (tester) async {
    await pumpScreen(tester, const PromotionsScreen(), FakeTransport({
      'GET ops/travel/promotions': promotionsResponse([promotion(1, 'active', 'Active')]),
      'GET ops/travel/promotions/options': {'packages': <dynamic>[], 'products': <dynamic>[], 'vat_rate': '5.00'},
    }));
    await tester.tap(find.byKey(const Key('promote')));
    await tester.pumpAndSettle();
    expect(find.byType(PromoteTripScreen), findsOneWidget);
    expect(find.text('No trip can be promoted'), findsOneWidget);
  });

  testWidgets('pull to refresh reloads the list', (tester) async {
    final transport = FakeTransport({'GET ops/travel/promotions': promotionsResponse([promotion(1, 'active', 'Active')])});
    await pumpScreen(tester, const PromotionsScreen(), transport);
    await tester.fling(find.byType(ListView), const Offset(0, 400), 1000);
    await tester.pumpAndSettle();
    expect(transport.sent('GET ops/travel/promotions'), hasLength(2));
  });

  testWidgets('a role without the promotions group sees the refusal', (tester) async {
    await pumpScreen(tester, const PromotionsScreen(), FakeTransport({'GET ops/travel/promotions': FakeTransport.error(403, 'forbidden', 'Not allowed.')}));
    expect(find.byKey(const Key('promote')), findsNothing);
    expect(find.byIcon(Icons.lock_outline_rounded), findsOneWidget);
  });

  testWidgets('the shell has a Promote tab', (tester) async {
    await pumpScreen(tester, const TravelShell(), FakeTransport({'GET ops/travel/promotions': promotionsResponse([])}));
    await tester.tap(find.text('Promote'));
    await tester.pumpAndSettle();
    expect(find.text('Promotions'), findsOneWidget);
  });

  testWidgets('the first tab is "Dashboard" (لوحة التحكم in Arabic), as in the Figma travel app frames', (tester) async {
    await pumpScreen(tester, const TravelShell(), FakeTransport({'GET ops/travel/promotions': promotionsResponse([])}));
    final labels = tester.widgetList<NavigationDestination>(find.byType(NavigationDestination)).map((d) => d.label).toList();
    expect(labels.first, 'Dashboard');
    expect(find.text('Summary'), findsNothing);

    await pumpScreen(tester, const TravelShell(), FakeTransport({'GET ops/travel/promotions': promotionsResponse([])}), locale: 'ar');
    expect(tester.widgetList<NavigationDestination>(find.byType(NavigationDestination)).first.label, 'لوحة التحكم');
  });

  testWidgets('Arabic labels', (tester) async {
    await pumpScreen(tester, const PromotionsScreen(), FakeTransport({'GET ops/travel/promotions': promotionsResponse([promotion(1, 'active', 'نشط')])}), locale: 'ar');
    expect(find.text('روّج لرحلة'), findsOneWidget);
    expect(find.text('25.500 OMR'), findsOneWidget);
  });

  test('EN and AR tables have the same keys', () {
    expect(travelAr.keys.toSet(), travelEn.keys.toSet());
  });
}
