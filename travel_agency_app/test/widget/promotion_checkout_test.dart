import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nawy_core/nawy_core.dart';
import 'package:nawy_core/testing.dart';
import 'package:travel_agency_app/data/travel_api.dart';
import 'package:travel_agency_app/screens/promotion_checkout.dart';
import 'package:travel_agency_app/screens/promotions.dart';
import 'package:travel_agency_app/screens/shell.dart';

import '../helpers.dart';

/// In-app promotion purchase (D14): options → buy → in-app tab → deep link /
/// resume → verify → result.
void main() {
  const redirect = 'https://pay.fake.test/stripe/fake_ref_1';
  final launched = <Uri>[];
  var closes = 0;

  setUp(() {
    launched.clear();
    closes = 0;
    PromotionCheckout.launch = (uri) async {
      launched.add(uri);
      return true;
    };
    PromotionCheckout.closeTab = () async => closes++;
    PromotionReturnLinks.install();
  });
  tearDown(PromotionReturnLinks.uninstall);

  Map<String, dynamic> options() => {
        'packages': [
          {'id': 7, 'title': 'Baku 4 nights'},
          {'id': 8, 'title': 'Salalah Khareef'},
        ],
        'products': [
          {'id': 3, 'code': 'travel-7d', 'name': '7 days at the top', 'duration_days': 7, 'price': moneyJson('105.00'), 'vat': moneyJson('5.00')},
          {'id': 4, 'code': 'travel-14d', 'name': '14 days at the top', 'duration_days': 14, 'price': moneyJson('189.00'), 'vat': moneyJson('9.00')},
        ],
        'vat_rate': '5.00',
      };

  Map<String, dynamic> answer(String result, {String status = 'pending_payment', String paymentStatus = 'pending', String ref = 'ref-1', String? redirectUrl = redirect, String? failure, bool canPay = true}) => {
        'promotion': {
          'id': 41,
          'number': 'TP-AE-000041',
          'status': status,
          'status_label': status,
          'package': {'id': 7, 'title': 'Baku 4 nights'},
          'product': {'code': 'travel-7d', 'name': '7 days at the top', 'duration_days': 7},
          'amount': moneyJson('105.00'),
          'starts_at': result == 'active' ? '2026-11-19T08:00:00Z' : (result == 'scheduled' ? '2026-12-01T08:00:00Z' : null),
          'ends_at': result == 'active' ? '2026-11-26T08:00:00Z' : (result == 'scheduled' ? '2026-12-08T08:00:00Z' : null),
          'can_pay': canPay && status == 'pending_payment',
        },
        'payment': {'reference': ref, 'status': paymentStatus, 'method': 'stripe', 'redirect_url': paymentStatus == 'pending' ? redirectUrl : null, 'failure_message': failure},
        'result': result,
      };

  test('return links are recognised with the scheme or as the route Flutter hands over', () {
    expect(promotionReturnReference(Uri.parse('nawytravel://promotions/return?payment=abc&status=captured')), 'abc');
    expect(promotionReturnReference(Uri.parse('/return?payment=abc')), 'abc');
    expect(promotionReturnReference(Uri.parse('/promotions/return?payment=abc')), 'abc');
    expect(promotionReturnReference(Uri.parse('nawytravel://promotions/return')), isNull);
    expect(promotionReturnReference(Uri.parse('https://evil.test/other?payment=abc')), isNull);
  });

  testWidgets('chooses a trip and an option, pays in the in-app tab and shows the live promotion after the return', (tester) async {
    final transport = FakeTransport({
      'GET ops/travel/promotions/options': options(),
      'POST ops/travel/promotions': FakeTransport.ok(answer('pending'), status: 201),
      'POST ops/travel/promotions/41/verify': answer('active', status: 'active', paymentStatus: 'captured', canPay: false),
    });
    await pumpScreen(tester, const PromoteTripScreen(), transport);

    expect(tester.widget<ButtonStyleButton>(find.byKey(const Key('promote-pay'))).onPressed, isNull);
    await tester.tap(find.byKey(const Key('promote-package-7')));
    await tester.tap(find.byKey(const Key('promote-product-3')));
    await tester.pumpAndSettle();
    expect(find.text('Total AED 105.00, VAT AED 5.00 included'), findsOneWidget);
    expect(find.text('Pay AED 105.00 by card'), findsOneWidget);

    await tester.tap(find.byKey(const Key('promote-pay')));
    await tester.pumpAndSettle();
    final body = jsonDecode(utf8.decode(transport.sent('POST ops/travel/promotions').single.body!)) as Map;
    expect(body, {'package_id': 7, 'product_id': 3});
    expect(launched, [Uri.parse(redirect)]);
    expect(find.byKey(const Key('checkout-result-pending')), findsOneWidget);
    expect(find.text('Complete the payment'), findsOneWidget);

    // The gateway return deep link reaches the open payment screen.
    PromotionCheckout.returns.add('ref-1');
    await tester.pumpAndSettle();
    expect(closes, 1);
    final verify = jsonDecode(utf8.decode(transport.sent('POST ops/travel/promotions/41/verify').single.body!)) as Map;
    expect(verify, {'payment': 'ref-1'});
    expect(find.byKey(const Key('checkout-result-active')), findsOneWidget);
    expect(find.text('Your promotion is live'), findsOneWidget);
  });

  testWidgets('coming back to the app (resume) verifies; a failed payment is paid again in a new tab', (tester) async {
    var verifies = 0;
    final transport = FakeTransport({
      'POST ops/travel/promotions/41/verify': (HttpRequestData _) {
        verifies++;
        return FakeTransport.ok(answer('failed', paymentStatus: 'failed', failure: 'The card was declined.'));
      },
      'POST ops/travel/promotions/41/pay': FakeTransport.ok(answer('pending', ref: 'ref-2', redirectUrl: '$redirect-2'), status: 201),
    });
    await pumpScreen(tester, PromotionPaymentScreen(start: answer('pending')), transport);
    expect(launched, hasLength(1));

    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
    tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
    await tester.pumpAndSettle();
    expect(verifies, 1);
    expect(closes, 1);
    expect(find.byKey(const Key('checkout-result-failed')), findsOneWidget);
    expect(find.text('The card was declined.'), findsOneWidget);

    await tester.tap(find.byKey(const Key('checkout-retry')));
    await tester.pumpAndSettle();
    expect(transport.sent('POST ops/travel/promotions/41/pay'), hasLength(1));
    expect(launched.last, Uri.parse('$redirect-2'));
  });

  testWidgets('pending confirmation re-checks automatically and with "Check again"', (tester) async {
    var verifies = 0;
    final transport = FakeTransport({
      'POST ops/travel/promotions/41/verify': (HttpRequestData _) {
        verifies++;
        return FakeTransport.ok(verifies < 3
            ? answer('pending', paymentStatus: 'under_review', redirectUrl: null)
            : answer('scheduled', status: 'active', paymentStatus: 'captured', canPay: false));
      },
    });
    PromotionCheckout.launch = (uri) async => false;
    await pumpScreen(tester, PromotionPaymentScreen(start: answer('pending'), recheckEvery: const Duration(seconds: 2)), transport);
    expect(find.text('Could not open the payment page'), findsOneWidget);

    await tester.tap(find.byKey(const Key('checkout-check')));
    await tester.pumpAndSettle();
    expect(verifies, 1);
    expect(find.text('Payment being confirmed'), findsOneWidget);

    await tester.pump(const Duration(seconds: 2));
    await tester.pumpAndSettle();
    expect(verifies, 2);
    await tester.pump(const Duration(seconds: 2));
    await tester.pumpAndSettle();
    expect(verifies, 3);
    expect(find.byKey(const Key('checkout-result-scheduled')), findsOneWidget);

    // Settled: no more automatic checks.
    await tester.pump(const Duration(seconds: 6));
    expect(verifies, 3);
  });

  testWidgets('paid but closed asks to contact support; a refused buy shows the message', (tester) async {
    await pumpScreen(tester, PromotionPaymentScreen(start: answer('paid_inactive', status: 'cancelled', paymentStatus: 'captured', canPay: false)), FakeTransport({}));
    expect(find.text('Paid, but not active'), findsOneWidget);
    expect(find.byKey(const Key('checkout-retry')), findsNothing);

    await pumpScreen(tester, const PromoteTripScreen(), FakeTransport({
      'GET ops/travel/promotions/options': options(),
      'POST ops/travel/promotions': FakeTransport.error(422, 'transition_refused', 'Only an active, published trip can be promoted.'),
    }));
    await tester.tap(find.byKey(const Key('promote-package-8')));
    await tester.tap(find.byKey(const Key('promote-product-4')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('promote-pay')));
    await tester.pumpAndSettle();
    expect(find.text('Only an active, published trip can be promoted.'), findsOneWidget);
    expect(launched, isEmpty);
  });

  testWidgets('a return link with no payment screen open finds the promotion and shows its state', (tester) async {
    final transport = FakeTransport({
      'GET ops/travel/promotions': FakeTransport.ok([answer('pending')['promotion']]),
      'POST ops/travel/promotions/41/verify': answer('active', status: 'active', paymentStatus: 'captured', canPay: false),
    });
    await pumpScreen(tester, const TravelShell(), transport);
    await tester.binding.handlePushRoute('nawytravel://promotions/return?payment=ref-1&status=captured');
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('checkout-result-active')), findsOneWidget);
  });

  testWidgets('a promotion waiting for payment has "Pay now" in the list', (tester) async {
    final transport = FakeTransport({
      'GET ops/travel/promotions': FakeTransport.ok([answer('pending')['promotion']], meta: {'in_app': true}),
      'POST ops/travel/promotions/41/pay': FakeTransport.ok(answer('pending', ref: 'ref-3'), status: 201),
    });
    await pumpScreen(tester, const PromotionsScreen(), transport);
    await tester.tap(find.byKey(const Key('promotion-pay-41')));
    await tester.pumpAndSettle();
    expect(launched, [Uri.parse(redirect)]);
    expect(find.byType(PromotionPaymentScreen), findsOneWidget);
  });

  testWidgets('Arabic checkout labels', (tester) async {
    await pumpScreen(tester, PromotionPaymentScreen(start: answer('active', status: 'active', paymentStatus: 'captured', canPay: false)), FakeTransport({}), locale: 'ar');
    expect(find.text('ترويجك نشط الآن'), findsOneWidget);
  });
}
