import 'package:delivery_app/data/models.dart';
import 'package:delivery_app/screens/cod.dart';
import 'package:delivery_app/screens/my_day.dart';
import 'package:delivery_app/screens/offer.dart';
import 'package:delivery_app/screens/pickup.dart';
import 'package:delivery_app/screens/stop_detail.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nawy_core/nawy_core.dart';
import 'package:nawy_core/testing.dart';

import '../helpers.dart';

void main() {
  group('cod_in_hand is a Money object (QA-042)', () {
    test('codInHandOf reads the Money map, the older string, or zero', () {
      expect(codInHandOf({'cod_in_hand': moneyJson('1240.5000', 'OMR')}, 'AED').format(), 'OMR 1,240.500');
      expect(codInHandOf({'cod_in_hand': '1240.0000'}, 'AED').format(), 'AED 1,240.00');
      expect(codInHandOf({'cod_in_hand': 75}, 'AED').format(), 'AED 75.00');
      expect(codInHandOf({}, 'AED').isZero, isTrue);
      expect(codInHandOf({'cod_in_hand': 'n/a'}, 'AED').isZero, isTrue);
    });

    testWidgets('My day shows the held cash with its own currency', (tester) async {
      final transport = FakeTransport({
        'GET delivery/me': {'name': 'Rashid', 'available': true, 'cod_in_hand': moneyJson('980.2500', 'AED')},
        'GET delivery/assignments': [],
      });
      await pumpScreen(tester, const MyDayScreen(), transport);
      expect(find.text('AED 980.25'), findsOneWidget);
    });

    testWidgets('Arabic puts the currency after the amount', (tester) async {
      final transport = FakeTransport({
        'GET delivery/me': {'name': 'Rashid', 'available': true, 'cod_in_hand': moneyJson('980.25', 'AED')},
        'GET delivery/assignments': [],
      });
      await pumpScreen(tester, const MyDayScreen(), transport, locale: 'ar');
      expect(find.text('980.25 AED'), findsOneWidget);
    });

    testWidgets('the COD screen also accepts an older string in_hand', (tester) async {
      final transport = FakeTransport({'GET delivery/me/cod': {'in_hand': '45.5', 'collections': [], 'settlements': []}});
      await pumpScreen(tester, const CodScreen(), transport);
      expect(find.text('AED 45.50'), findsOneWidget);
    });
  });

  group('refused steps (QA-025)', () {
    testWidgets('transition_refused on pickup shows the message and reloads the stop', (tester) async {
      var status = 'accepted';
      final transport = FakeTransport({
        'GET delivery/assignments/DA-1001': (HttpRequestData _) => FakeTransport.ok(assignmentJson(status: status)),
        'POST delivery/assignments/DA-1001/start': (HttpRequestData _) {
          status = 'cancelled';
          return FakeTransport.error(422, 'transition_refused', 'This shipment was cancelled.');
        },
      });
      await pumpScreen(tester, const StopDetailScreen(number: 'DA-1001'), transport);
      await tester.tap(find.byKey(const Key('action-start')));
      await tester.pumpAndSettle();
      for (var i = 0; i < 2; i++) {
        await tester.tap(find.byKey(Key('parcel-$i')));
      }
      await tester.pump();
      await tester.tap(find.byKey(const Key('pickup-start')));
      await tester.pumpAndSettle();

      expect(find.text('This shipment was cancelled.'), findsOneWidget);
      expect(find.byType(PickupScreen), findsNothing);
      expect(transport.sent('GET delivery/assignments/DA-1001'), hasLength(2));
      expect(find.byKey(const Key('cancelled-banner')), findsOneWidget);
    });

    testWidgets('the older not_allowed still goes back and reloads', (tester) async {
      final transport = FakeTransport({
        'GET delivery/assignments/DA-1001': assignmentJson(status: 'assigned'),
        'POST delivery/assignments/DA-1001/accept': FakeTransport.error(422, 'not_allowed', 'Offer expired.'),
      });
      await pumpScreen(tester, const StopDetailScreen(number: 'DA-1001'), transport);
      await tester.tap(find.byKey(const Key('action-accept')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('offer-accept')));
      await tester.pumpAndSettle();
      expect(find.text('Offer expired.'), findsOneWidget);
      expect(find.byType(OfferScreen), findsNothing);
      expect(transport.sent('GET delivery/assignments/DA-1001'), hasLength(2));
    });

    testWidgets('other errors keep the agent on the step screen', (tester) async {
      final transport = FakeTransport({
        'GET delivery/assignments/DA-1001': assignmentJson(status: 'assigned'),
        'POST delivery/assignments/DA-1001/accept': FakeTransport.error(500, 'server_error', 'Try later.'),
      });
      await pumpScreen(tester, const StopDetailScreen(number: 'DA-1001'), transport);
      await tester.tap(find.byKey(const Key('action-accept')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('offer-accept')));
      await tester.pumpAndSettle();
      expect(find.text('Try later.'), findsOneWidget);
      expect(find.byType(OfferScreen), findsOneWidget);
    });

    testWidgets('a refused arrive shows the message and reloads', (tester) async {
      final transport = FakeTransport({
        'GET delivery/assignments/DA-1001': assignmentJson(),
        'POST delivery/assignments/DA-1001/arrive': FakeTransport.error(422, 'transition_refused', 'Already arrived.'),
      });
      await pumpScreen(tester, const StopDetailScreen(number: 'DA-1001'), transport);
      await tester.tap(find.byKey(const Key('action-arrive')));
      await tester.pumpAndSettle();
      expect(find.text('Already arrived.'), findsOneWidget);
      expect(transport.sent('GET delivery/assignments/DA-1001'), hasLength(2));
    });
  });
}
