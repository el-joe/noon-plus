import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nawy_core/nawy_core.dart';
import 'package:nawy_core/testing.dart';
import 'package:vendor_app/app.dart';
import 'package:vendor_app/data/vendor_api.dart';
import 'package:vendor_app/screens/agreement.dart';
import 'package:vendor_app/screens/dashboard.dart';
import 'package:vendor_app/screens/stock.dart';

import '../helpers.dart';

Map<String, dynamic> agreementJson({bool signed = false, bool canSign = true, int id = 4}) => {
      'required': true,
      'signed': signed,
      'can_sign': canSign,
      'version': {'id': id, 'title': 'Nawy Seller Agreement v4', 'text': 'The seller lists genuine goods...'},
    };

void main() {
  testWidgets('unsigned store: the agreement shows first; signing opens the dashboard', (tester) async {
    var signed = false;
    final transport = FakeTransport({
      'GET ops/vendor/agreement': (HttpRequestData _) => FakeTransport.ok(agreementJson(signed: signed)),
      'POST ops/vendor/agreement/sign': (HttpRequestData r) {
        signed = true;
        return FakeTransport.ok({'signed': true});
      },
      'GET ops/vendor/dashboard': (HttpRequestData _) =>
          signed ? FakeTransport.ok({'kpis': {}}) : FakeTransport.error(403, 'seller_agreement_required', 'Sign the Seller Agreement first.'),
    });
    final session = await testSession(transport);
    await session.updateProfile({'name': 'Sara Ahmed'});
    await tester.pumpWidget(VendorApp(session: session));
    await tester.pumpAndSettle();

    expect(find.byType(AgreementScreen), findsOneWidget);
    expect(find.text('Nawy Seller Agreement v4'), findsOneWidget);
    expect(tester.widget<FilledButton>(find.byKey(const Key('agreement-sign'))).onPressed, isNull);
    await tester.tap(find.byKey(const Key('agreement-accept')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('agreement-sign')));
    await tester.pumpAndSettle();

    expect(FakeTransport.bodyOf(transport.sent('POST ops/vendor/agreement/sign').single), {'version_id': 4, 'full_name': 'Sara Ahmed', 'accept': true});
    expect(find.byType(DashboardScreen), findsOneWidget);
    expect(find.byType(AgreementScreen), findsNothing);
  });

  testWidgets('a 403 seller_agreement_required later brings the agreement back', (tester) async {
    var required = false;
    final transport = FakeTransport({
      'GET ops/vendor/agreement': (HttpRequestData _) => FakeTransport.ok(required ? agreementJson() : {'required': true, 'signed': true, 'can_sign': true, 'version': null}),
      'GET ops/vendor/dashboard': (HttpRequestData _) =>
          required ? FakeTransport.error(403, 'seller_agreement_required', 'Sign the new version.') : FakeTransport.ok({'kpis': {}}),
    });
    final session = await testSession(transport);
    await tester.pumpWidget(VendorApp(session: session));
    await tester.pumpAndSettle();
    expect(find.byType(DashboardScreen), findsOneWidget);

    required = true;
    await expectLater(() => session.api.get('ops/vendor/dashboard'), throwsA(isA<ApiException>()));
    await tester.pumpAndSettle();
    expect(find.byType(AgreementScreen), findsOneWidget);
    expect(session.signedIn, isTrue);
  });

  testWidgets('staff who are not the owner cannot sign; 409 outdated reloads', (tester) async {
    final transport = FakeTransport({'GET ops/vendor/agreement': agreementJson(canSign: false)});
    await pumpScreen(tester, const VendorGate(), transport);
    expect(find.byKey(const Key('agreement-owner-only')), findsOneWidget);
    expect(find.byKey(const Key('agreement-sign')), findsNothing);
  });

  testWidgets('version_outdated reloads the agreement', (tester) async {
    var version = 4;
    final transport = FakeTransport({
      'GET ops/vendor/agreement': (HttpRequestData _) => FakeTransport.ok(agreementJson(id: version)),
      'POST ops/vendor/agreement/sign': (HttpRequestData _) {
        version = 5;
        return FakeTransport.error(409, 'version_outdated', 'This agreement version is no longer current.');
      },
    });
    await pumpScreen(tester, const VendorGate(), transport);
    await tester.enterText(find.byKey(const Key('agreement-name')), 'Sara Ahmed');
    await tester.tap(find.byKey(const Key('agreement-accept')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('agreement-sign')));
    await tester.pumpAndSettle();
    expect(transport.sent('GET ops/vendor/agreement'), hasLength(2));
    expect(find.byType(AgreementScreen), findsOneWidget);
  });

  testWidgets('403 forbidden for a staff role shows the role message, not a sign-out', (tester) async {
    final transport = FakeTransport({
      'GET ops/vendor/stock': [stockJson(stock: 10)],
      'POST ops/vendor/stock/31': FakeTransport.error(403, 'forbidden', 'This action is unauthorized.'),
    });
    await pumpScreen(tester, const StockScreen(), transport);
    await tester.tap(find.byKey(const Key('stock-31')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('quantity')), '2');
    await tester.tap(find.byKey(const Key('save-stock')));
    await tester.pumpAndSettle();
    expect(find.text('Your role does not allow this. Ask the account owner for access.'), findsOneWidget);
  });

  test('SellerAgreement.blocking', () {
    expect(SellerAgreement(agreementJson()).blocking, isTrue);
    expect(SellerAgreement(agreementJson(signed: true)).blocking, isFalse);
    expect(SellerAgreement({'required': false}).blocking, isFalse);
  });
}
