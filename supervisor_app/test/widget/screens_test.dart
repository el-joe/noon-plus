import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nawy_core/nawy_core.dart' show PlatformBridge;
import 'package:nawy_core/testing.dart';
import 'package:supervisor_app/app.dart';
import 'package:supervisor_app/screens/dashboard.dart';
import 'package:supervisor_app/screens/dispatch.dart';
import 'package:supervisor_app/screens/drivers.dart';
import 'package:supervisor_app/screens/failed.dart';

import '../helpers.dart';

final dashboard = {
  'company': {'id': 3, 'name': 'Gulf Express'},
  'kpis': {'open_assignments': 12, 'unassigned': 4, 'delivered_today': 31, 'failed_today': 2, 'drivers_active': 7, 'cod_in_hand': moneyJson('2115.00')},
};

final board = {
  'unassigned': [
    {'id': 41, 'number': 'SH-41', 'sub_order_number': 'NW-AE-1000041-1', 'status': 'label_created', 'city': 'Dubai', 'cod_amount': moneyJson('120.00')},
  ],
  'open': [
    {'id': 9, 'number': 'DA-9', 'status': 'picked_up', 'agent': {'id': 5, 'name': 'Rashid Ali'}, 'sla_due_at': '2026-11-15T14:00:00+04:00', 'city': 'Dubai'},
  ],
  'drivers': [
    {'id': 5, 'name': 'Rashid Ali', 'status': 'active', 'open': 3},
    {'id': 6, 'name': 'Omar Saleh', 'status': 'unavailable', 'open': 0},
  ],
};

Map<String, dynamic> failedRow({List<String> actions = const ['reschedule', 'return']}) => {
      'id': 77, 'number': 'SH-77', 'sub_order_number': 'NW-AE-1000077-1', 'status': 'failed', 'failure_reason': 'not_home',
      'attempts': 1, 'max_attempts': 3, 'city': 'Sharjah', 'rescheduled_for': null, 'last_failed_at': '2026-11-15T09:00:00Z', 'actions': actions,
    };

void main() {
  group('sign in', () {
    testWidgets('sends role supervisor and opens the summary', (tester) async {
      final transport = FakeTransport({
        'POST ops/auth/login': {'token': 'tok', 'user': {'id': 1, 'name': 'Sara'}, 'workspace': {'role': 'supervisor', 'id': 3, 'name': 'Gulf Express'}},
        'GET ops/supervisor/dashboard': dashboard,
      });
      final session = await testSession(transport, signedIn: false);
      await tester.pumpWidget(SupervisorApp(session: session));
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const Key('email')), 'sara@gulf.test');
      await tester.enterText(find.byKey(const Key('password')), 'secret');
      await tester.tap(find.byKey(const Key('sign-in')));
      await tester.pumpAndSettle();
      final body = FakeTransport.bodyOf(transport.sent('POST ops/auth/login').single);
      expect(body['role'], 'supervisor');
      expect(await session.store.read('token'), 'tok');
      expect((session.profile!['workspace'] as Map)['name'], 'Gulf Express');
      expect(find.byType(DashboardScreen), findsOneWidget);
    });

    testWidgets('no_workspace shows the API message', (tester) async {
      final transport = FakeTransport({'POST ops/auth/login': FakeTransport.error(403, 'no_workspace', 'This account has no supervisor workspace in this country.')});
      final session = await testSession(transport, signedIn: false);
      await tester.pumpWidget(SupervisorApp(session: session));
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const Key('email')), 'a@b.c');
      await tester.enterText(find.byKey(const Key('password')), 'x');
      await tester.tap(find.byKey(const Key('sign-in')));
      await tester.pumpAndSettle();
      expect(find.text('This account has no supervisor workspace in this country.'), findsOneWidget);
      expect(session.signedIn, isFalse);
    });
  });

  testWidgets('dashboard shows the company KPIs', (tester) async {
    await pumpScreen(tester, const DashboardScreen(), FakeTransport({'GET ops/supervisor/dashboard': dashboard}));
    expect(find.text('Gulf Express'), findsOneWidget);
    expect(find.descendant(of: find.byKey(const Key('kpi-unassigned')), matching: find.text('4')), findsOneWidget);
    expect(find.descendant(of: find.byKey(const Key('kpi-delivered')), matching: find.text('31')), findsOneWidget);
    expect(find.text('AED 2,115.00'), findsOneWidget);
  });

  group('dispatch', () {
    testWidgets('assigns a specific driver', (tester) async {
      final transport = FakeTransport({
        'GET ops/supervisor/dispatch': board,
        'POST ops/supervisor/dispatch/41/assign': {'assignment': {'id': 10, 'number': 'DA-10', 'status': 'assigned', 'agent': {'id': 5, 'name': 'Rashid Ali'}}},
      });
      await pumpScreen(tester, const DispatchScreen(), transport);
      expect(find.text('NW-AE-1000041-1'), findsOneWidget);
      expect(find.text('DA-9'), findsOneWidget);
      await tester.tap(find.byKey(const Key('assign-41')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('driver-5')));
      await tester.pumpAndSettle();
      expect(FakeTransport.bodyOf(transport.sent('POST ops/supervisor/dispatch/41/assign').single), {'agent_id': 5});
      expect(find.text('Driver assigned'), findsOneWidget);
    });

    testWidgets('auto-assign sends agent_id null', (tester) async {
      final transport = FakeTransport({
        'GET ops/supervisor/dispatch': board,
        'POST ops/supervisor/dispatch/41/assign': {'assignment': {'id': 10}},
      });
      await pumpScreen(tester, const DispatchScreen(), transport);
      await tester.tap(find.byKey(const Key('assign-41')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('auto-assign')));
      await tester.pumpAndSettle();
      final body = FakeTransport.bodyOf(transport.sent('POST ops/supervisor/dispatch/41/assign').single);
      expect(body.containsKey('agent_id'), isTrue);
      expect(body['agent_id'], isNull);
    });
  });

  group('failed deliveries', () {
    testWidgets('reschedule posts the picked date as Y-m-d', (tester) async {
      final transport = FakeTransport({'GET ops/supervisor/failed': [failedRow()], 'POST ops/supervisor/failed/77/reschedule': failedRow(actions: [])});
      await pumpScreen(tester, FailedScreen(pickDate: (_) async => DateTime(2026, 11, 18)), transport);
      expect(find.text('Customer not available · attempt 1/3 · Sharjah'), findsOneWidget);
      expect(find.byKey(const Key('returned-77')), findsNothing);
      await tester.tap(find.byKey(const Key('reschedule-77')));
      await tester.pumpAndSettle();
      expect(FakeTransport.bodyOf(transport.sent('POST ops/supervisor/failed/77/reschedule').single), {'date': '2026-11-18'});
    });

    testWidgets('return to origin asks for a reason', (tester) async {
      final transport = FakeTransport({'GET ops/supervisor/failed': [failedRow()], 'POST ops/supervisor/failed/77/return': failedRow(actions: ['returned'])});
      await pumpScreen(tester, const FailedScreen(), transport);
      await tester.tap(find.byKey(const Key('return-77')));
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const Key('reason-field')), 'Customer refused twice');
      await tester.pump();
      await tester.tap(find.text('Submit'));
      await tester.pumpAndSettle();
      expect(FakeTransport.bodyOf(transport.sent('POST ops/supervisor/failed/77/return').single), {'reason': 'Customer refused twice'});
    });

    testWidgets('a refused action shows the message', (tester) async {
      final transport = FakeTransport({
        'GET ops/supervisor/failed': [failedRow(actions: ['returned'])],
        'POST ops/supervisor/failed/77/returned': FakeTransport.error(422, 'transition_refused', 'The parcel is not on its way back.'),
      });
      await pumpScreen(tester, const FailedScreen(), transport);
      await tester.tap(find.byKey(const Key('returned-77')));
      await tester.pumpAndSettle();
      expect(find.text('The parcel is not on its way back.'), findsOneWidget);
    });
  });

  group('drivers', () {
    final drivers = [
      {'id': 5, 'name': 'Rashid Ali', 'status': 'active', 'phone': '+971501112233', 'vehicle': {'type': 'bike', 'plate': 'D 12345'}, 'open_assignments': 3, 'cod_in_hand': moneyJson('312.00'), 'last_ping_at': null},
      {'id': 6, 'name': 'Omar Saleh', 'status': 'suspended', 'phone': null, 'vehicle': {'type': null, 'plate': null}, 'open_assignments': 0, 'cod_in_hand': moneyJson('0.00'), 'last_ping_at': null},
    ];

    testWidgets("tapping a driver's phone opens the dialler (D16, P12)", (tester) async {
      final launched = <Uri>[];
      PlatformBridge.debugLaunch = (uri) async {
        launched.add(uri);
        return true;
      };
      addTearDown(() => PlatformBridge.debugLaunch = null);
      await pumpScreen(tester, const DriversScreen(), FakeTransport({'GET ops/supervisor/drivers': drivers}));
      expect(find.byKey(const Key('call-driver-6')), findsNothing);
      await tester.tap(find.byKey(const Key('call-driver-5')));
      expect(launched.single.toString(), 'tel:+971501112233');
    });

    testWidgets('suspend with a reason and reactivate', (tester) async {
      final transport = FakeTransport({
        'GET ops/supervisor/drivers': drivers,
        'POST ops/supervisor/drivers/5/status': drivers[0],
        'POST ops/supervisor/drivers/6/status': drivers[1],
      });
      await pumpScreen(tester, const DriversScreen(), transport);
      expect(find.text('Motorbike · D 12345 · 3 open · cash AED 312.00'), findsOneWidget);
      await tester.tap(find.byKey(const Key('suspend-5')));
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const Key('reason-field')), 'Late COD deposit');
      await tester.pump();
      await tester.tap(find.text('Submit'));
      await tester.pumpAndSettle();
      expect(FakeTransport.bodyOf(transport.sent('POST ops/supervisor/drivers/5/status').single), {'status': 'suspended', 'reason': 'Late COD deposit'});

      await tester.tap(find.byKey(const Key('activate-6')));
      await tester.pumpAndSettle();
      expect(FakeTransport.bodyOf(transport.sent('POST ops/supervisor/drivers/6/status').single), {'status': 'active'});
    });
  });

  testWidgets('Arabic runs right to left', (tester) async {
    await pumpScreen(tester, const DashboardScreen(), FakeTransport({'GET ops/supervisor/dashboard': dashboard}), locale: 'ar');
    expect(find.text('سُلّمت اليوم'), findsOneWidget);
    expect(Directionality.of(tester.element(find.byKey(const Key('company')))), TextDirection.rtl);
    expect(find.text('2,115.00 AED'), findsOneWidget);
  });
}
