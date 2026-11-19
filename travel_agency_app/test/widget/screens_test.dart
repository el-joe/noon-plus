import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nawy_core/nawy_core.dart' show PlatformBridge;
import 'package:nawy_core/testing.dart';
import 'package:travel_agency_app/app.dart';
import 'package:travel_agency_app/screens/bookings.dart';
import 'package:travel_agency_app/screens/dashboard.dart';
import 'package:travel_agency_app/screens/departures.dart';

import '../helpers.dart';

final dashboard = {
  'agency': {'id': 8, 'name': 'Desert Rose Tours'},
  'kpis': {'pending_requests': 3, 'confirmed_this_month': 14, 'upcoming_departures': 5, 'seats_left': 62},
};

Map<String, dynamic> booking({String status = 'requested', bool canConfirm = true, bool canReject = true}) => {
      'id': 21, 'number': 'TB-21', 'status': status, 'package': 'Salalah Khareef 4 days', 'departure_date': '2026-12-02', 'travellers': 3,
      'contact_name': 'Mariam Said', 'contact_phone': '+96891234567', 'contact_email': 'm@x.test', 'total': moneyJson('360.000', 'OMR'),
      'submitted_at': '2026-11-14T10:00:00Z', 'can_confirm': canConfirm, 'can_reject': canReject,
    };

final departure = {'id': 4, 'package': 'Salalah Khareef 4 days', 'starts_on': '2026-12-02', 'ends_on': '2026-12-05', 'capacity': 40, 'booked': 12, 'seats_left': 28, 'status': 'scheduled'};

void main() {
  group('sign in', () {
    testWidgets('sends role travel_agency and opens the dashboard', (tester) async {
      final transport = FakeTransport({
        'POST ops/auth/login': {'token': 'tok', 'user': {'id': 1, 'name': 'Khalid'}, 'workspace': {'role': 'travel_agency', 'id': 8, 'name': 'Desert Rose Tours'}},
        'GET ops/travel/dashboard': dashboard,
      });
      final session = await testSession(transport, signedIn: false);
      await tester.pumpWidget(TravelApp(session: session));
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const Key('email')), 'k@rose.test');
      await tester.enterText(find.byKey(const Key('password')), 'secret');
      await tester.tap(find.byKey(const Key('sign-in')));
      await tester.pumpAndSettle();
      expect(FakeTransport.bodyOf(transport.sent('POST ops/auth/login').single)['role'], 'travel_agency');
      expect(find.byType(DashboardScreen), findsOneWidget);
      expect(find.text('Desert Rose Tours'), findsOneWidget);
    });

    testWidgets('no_workspace shows the API message', (tester) async {
      final transport = FakeTransport({'POST ops/auth/login': FakeTransport.error(403, 'no_workspace', 'This account has no travel agency workspace in this country.')});
      final session = await testSession(transport, signedIn: false);
      await tester.pumpWidget(TravelApp(session: session));
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const Key('email')), 'a@b.c');
      await tester.enterText(find.byKey(const Key('password')), 'x');
      await tester.tap(find.byKey(const Key('sign-in')));
      await tester.pumpAndSettle();
      expect(find.text('This account has no travel agency workspace in this country.'), findsOneWidget);
    });
  });

  testWidgets('dashboard KPIs', (tester) async {
    await pumpScreen(tester, const DashboardScreen(), FakeTransport({'GET ops/travel/dashboard': dashboard}));
    expect(find.descendant(of: find.byKey(const Key('kpi-pending')), matching: find.text('3')), findsOneWidget);
    expect(find.descendant(of: find.byKey(const Key('kpi-seats')), matching: find.text('62')), findsOneWidget);
    expect(find.text('Answer 3 booking requests'), findsOneWidget);
  });

  group('booking requests (D8)', () {
    testWidgets("tapping the traveller's phone opens the dialler (D16, P12)", (tester) async {
      final launched = <Uri>[];
      PlatformBridge.debugLaunch = (uri) async {
        launched.add(uri);
        return true;
      };
      addTearDown(() => PlatformBridge.debugLaunch = null);
      await pumpScreen(tester, const BookingsScreen(), FakeTransport({'GET ops/travel/bookings': [booking()]}));
      expect(find.text('Mariam Said'), findsOneWidget);
      await tester.tap(find.byKey(const Key('call-contact-21')));
      expect(launched.single.toString(), 'tel:+96891234567');
    });

    testWidgets('lists requested bookings and confirms', (tester) async {
      final transport = FakeTransport({
        'GET ops/travel/bookings': [booking()],
        'POST ops/travel/bookings/21/confirm': booking(status: 'confirmed', canConfirm: false, canReject: false),
      });
      await pumpScreen(tester, const BookingsScreen(), transport);
      expect(transport.requests.first.uri.queryParameters['status'], 'requested');
      expect(find.text('Estimate OMR 360.000'), findsOneWidget);
      await tester.tap(find.byKey(const Key('confirm-21')));
      await tester.pumpAndSettle();
      expect(transport.sent('POST ops/travel/bookings/21/confirm'), hasLength(1));
      expect(find.text('Booking confirmed'), findsOneWidget);
    });

    testWidgets('reject needs a reason of 5+ characters', (tester) async {
      final transport = FakeTransport({'GET ops/travel/bookings': [booking()], 'POST ops/travel/bookings/21/reject': booking(status: 'rejected')});
      await pumpScreen(tester, const BookingsScreen(), transport);
      await tester.tap(find.byKey(const Key('reject-21')));
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const Key('reason-field')), 'Full');
      await tester.pump();
      expect(tester.widget<FilledButton>(find.widgetWithText(FilledButton, 'Submit')).onPressed, isNull);
      await tester.enterText(find.byKey(const Key('reason-field')), 'Departure is fully booked');
      await tester.pump();
      await tester.tap(find.text('Submit'));
      await tester.pumpAndSettle();
      expect(FakeTransport.bodyOf(transport.sent('POST ops/travel/bookings/21/reject').single), {'reason': 'Departure is fully booked'});
    });

    testWidgets('the "All" filter drops the status and finished bookings have no actions', (tester) async {
      final transport = FakeTransport({'GET ops/travel/bookings': [booking(status: 'confirmed', canConfirm: false, canReject: false)]});
      await pumpScreen(tester, const BookingsScreen(), transport);
      await tester.tap(find.byKey(const Key('filter-all')));
      await tester.pumpAndSettle();
      expect(transport.requests.last.uri.queryParameters.containsKey('status'), isFalse);
      expect(find.byKey(const Key('confirm-21')), findsNothing);
    });
  });

  group('departures', () {
    testWidgets('changes capacity and validates against booked seats', (tester) async {
      final transport = FakeTransport({'GET ops/travel/departures': [departure], 'POST ops/travel/departures/4/capacity': {...departure, 'capacity': 45, 'seats_left': 33}});
      await pumpScreen(tester, const DeparturesScreen(), transport);
      expect(find.text('12 of 40 booked · 28 left'), findsOneWidget);
      await tester.tap(find.byKey(const Key('capacity-4')));
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const Key('capacity-field')), '10');
      await tester.pump();
      expect(find.text('Not below the 12 seats already booked'), findsOneWidget);
      expect(tester.widget<FilledButton>(find.byKey(const Key('capacity-save'))).onPressed, isNull);
      await tester.enterText(find.byKey(const Key('capacity-field')), '45');
      await tester.pump();
      await tester.tap(find.byKey(const Key('capacity-save')));
      await tester.pumpAndSettle();
      expect(FakeTransport.bodyOf(transport.sent('POST ops/travel/departures/4/capacity').single), {'capacity': 45});
    });

    testWidgets('shows the API refusal', (tester) async {
      final transport = FakeTransport({
        'GET ops/travel/departures': [departure],
        'POST ops/travel/departures/4/capacity': FakeTransport.error(422, 'transition_refused', 'Seats are already held for this departure.'),
      });
      await pumpScreen(tester, const DeparturesScreen(), transport);
      await tester.tap(find.byKey(const Key('capacity-4')));
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const Key('capacity-field')), '20');
      await tester.pump();
      await tester.tap(find.byKey(const Key('capacity-save')));
      await tester.pumpAndSettle();
      expect(find.text('Seats are already held for this departure.'), findsOneWidget);
    });
  });

  testWidgets('Arabic runs right to left', (tester) async {
    await pumpScreen(tester, const DashboardScreen(), FakeTransport({'GET ops/travel/dashboard': dashboard}), locale: 'ar');
    expect(find.text('مقاعد متبقية'), findsOneWidget);
    expect(Directionality.of(tester.element(find.byKey(const Key('agency')))), TextDirection.rtl);
  });
}
