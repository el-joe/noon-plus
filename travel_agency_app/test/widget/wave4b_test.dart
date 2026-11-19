import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nawy_core/testing.dart';
import 'package:travel_agency_app/app.dart';
import 'package:travel_agency_app/screens/bookings.dart';

import '../helpers.dart';

Map<String, dynamic> booking() => {
      'id': 21,
      'number': 'TB-OM-21',
      'status': 'requested',
      'package': 'Salalah Khareef 4 days',
      'starts_on': '2026-12-02',
      'travellers': 2,
      'lead': {'name': 'Huda', 'email': 'huda@example.com', 'phone': '+96890000000'},
      'estimate': moneyJson('360.000', 'OMR'),
      'can_confirm': true,
      'can_reject': true,
    };

void main() {
  testWidgets('403 forbidden on confirm (staff role) shows the role message', (tester) async {
    final transport = FakeTransport({
      'GET ops/travel/bookings': [booking()],
      'POST ops/travel/bookings/21/confirm': FakeTransport.error(403, 'forbidden', 'This action is unauthorized.'),
    });
    await pumpScreen(tester, const BookingsScreen(), transport);
    await tester.tap(find.byKey(const Key('confirm-21')));
    await tester.pumpAndSettle();
    expect(find.text('Your role does not allow this. Ask the account owner for access.'), findsOneWidget);
  });

  testWidgets('401 after 30 days returns to sign-in with a message', (tester) async {
    final transport = FakeTransport({'GET ops/travel/dashboard': FakeTransport.error(401, 'unauthenticated', 'Unauthenticated.')});
    final session = await testSession(transport);
    await tester.pumpWidget(TravelApp(session: session));
    await tester.pumpAndSettle();
    expect(session.signedIn, isFalse);
    expect(find.text('Your session has expired. Please sign in again.'), findsOneWidget);
  });

  testWidgets('429 on sign-in shows the wait', (tester) async {
    final transport = FakeTransport({
      'POST ops/auth/login': FakeTransport.error(429, 'too_many_requests', 'Too many.'),
    });
    final session = await testSession(transport, signedIn: false);
    await tester.pumpWidget(TravelApp(session: session));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('email')), 'a@b.test');
    await tester.enterText(find.byKey(const Key('password')), 'x');
    await tester.tap(find.byKey(const Key('sign-in')));
    await tester.pumpAndSettle();
    expect(find.text('Too many attempts. Please wait a moment and try again.'), findsOneWidget);
  });
}
