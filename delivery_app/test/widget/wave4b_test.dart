import 'package:delivery_app/app.dart';
import 'package:delivery_app/data/delivery_api.dart';
import 'package:delivery_app/data/models.dart';
import 'package:delivery_app/screens/my_day.dart';
import 'package:delivery_app/screens/stop_detail.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nawy_core/nawy_core.dart';
import 'package:nawy_core/testing.dart';

import '../helpers.dart';

void main() {
  group('cancelled assignments (QA-005)', () {
    test('the open list drops cancelled assignments', () async {
      final transport = FakeTransport({
        'GET delivery/assignments': [assignmentJson(), {...assignmentJson(), 'number': 'DA-2', 'status': 'cancelled'}],
      });
      final session = await testSession(transport);
      final stops = await DeliveryApi(session.api).assignments();
      expect(stops.map((a) => a.number), ['DA-1001']);
    });

    test('CancelledWatch reports only stops that left the list and were cancelled', () async {
      final watch = CancelledWatch();
      Future<List<Assignment>> lookup(Set<String> numbers) async => [for (final n in numbers) if (n == 'DA-1') Assignment({'number': n, 'status': 'cancelled'})];
      expect(await watch.check(lookup, [Assignment({'number': 'DA-1'}), Assignment({'number': 'DA-2'})]), isEmpty);
      final found = await watch.check(lookup, [Assignment({'number': 'DA-2'})]);
      expect(found.map((a) => a.number), ['DA-1']);
      expect(vanishedStops({'A', 'B'}, [Assignment({'number': 'B'})]), {'A'});
    });

    testWidgets('My day tells the agent when an open stop was cancelled', (tester) async {
      var cancelled = false;
      final transport = FakeTransport({
        'GET delivery/me': {'name': 'Rashid', 'available': true, 'cod_in_hand': '0'},
        'GET delivery/assignments': (HttpRequestData _) => FakeTransport.ok(cancelled ? [] : [assignmentJson()]),
        'GET delivery/assignments/DA-1001': assignmentJson(status: 'cancelled'),
      });
      await pumpScreen(tester, const MyDayScreen(), transport);
      expect(find.byKey(const Key('stop-DA-1001')), findsOneWidget);

      cancelled = true;
      await tester.state<AsyncBodyState<(Json, List<Assignment>)>>(find.byType(AsyncBody<(Json, List<Assignment>)>)).reload();
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('stop-DA-1001')), findsNothing);
      expect(find.text('Cancelled and removed from your stops: DA-1001'), findsOneWidget);
    });

    testWidgets('a cancelled stop shows the banner and no actions', (tester) async {
      final transport = FakeTransport({'GET delivery/assignments/DA-1001': assignmentJson(status: 'cancelled')});
      await pumpScreen(tester, const StopDetailScreen(number: 'DA-1001'), transport);
      expect(find.byKey(const Key('cancelled-banner')), findsOneWidget);
      expect(find.text('Cancelled'), findsOneWidget);
      for (final a in ['navigate', 'arrive', 'deliver', 'fail', 'accept', 'start']) {
        expect(find.byKey(Key('action-$a')), findsNothing);
      }
    });
  });

  group('auth failures', () {
    testWidgets('403 wrong_token signs out and asks to sign in again', (tester) async {
      final transport = FakeTransport({
        'GET delivery/me': FakeTransport.error(403, 'wrong_token', 'Use the delivery app sign-in.'),
        'GET delivery/assignments': [],
      });
      final session = await testSession(transport);
      await session.updateProfile({'name': 'Rashid', 'email': 'rashid@nawy.test'});
      await tester.pumpWidget(DeliveryApp(session: session));
      await tester.pumpAndSettle();
      expect(session.signedIn, isFalse);
      expect(await session.store.read('token'), isNull);
      expect(find.byKey(const Key('sign-out-notice')), findsOneWidget);
      expect(find.text('This sign-in is not valid for this app. Please sign in again.'), findsOneWidget);
      expect(find.text('rashid@nawy.test'), findsOneWidget);
    });

    testWidgets('401 after 30 days: session expired message', (tester) async {
      final transport = FakeTransport({
        'GET delivery/me': FakeTransport.error(401, 'unauthenticated', 'Unauthenticated.'),
        'GET delivery/assignments': FakeTransport.error(401, 'unauthenticated', 'Unauthenticated.'),
      });
      final session = await testSession(transport);
      await tester.pumpWidget(DeliveryApp(session: session));
      await tester.pumpAndSettle();
      expect(session.signedIn, isFalse);
      expect(find.text('Your session has expired. Please sign in again.'), findsOneWidget);
    });
  });
}
