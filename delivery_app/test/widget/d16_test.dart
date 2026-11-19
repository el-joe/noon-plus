import 'dart:async';

import 'package:delivery_app/app.dart';
import 'package:delivery_app/data/location.dart';
import 'package:delivery_app/data/models.dart';
import 'package:delivery_app/screens/pickup.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nawy_core/nawy_core.dart';
import 'package:nawy_core/testing.dart';

import '../helpers.dart';

/// Owner-review D16: pickup scan with manual fallback (P10), GPS pings while
/// online (P9), tap-to-call (P12).
void main() {
  group('pickup scan (mobile_scanner) with manual entry', () {
    Future<FakeTransport> open(WidgetTester tester, ParcelScannerStub scanner) async {
      final transport = FakeTransport({
        'POST delivery/assignments/DA-1001/start': assignmentJson(status: 'picked_up'),
        'POST delivery/assignments/DA-1001/scan': {'matches': true, 'shipment_number': 'NW-AE-1028810-1'},
      });
      await pumpScreen(tester, Builder(builder: (context) => PickupScreen(assignment: Assignment(assignmentJson(status: 'accepted')), scanner: scanner.call)), transport);
      return transport;
    }

    bool startEnabled(WidgetTester tester) => tester.widget<FilledButton>(find.byKey(const Key('pickup-start'))).onPressed != null;

    testWidgets('scanning the shipment label ticks the parcel and enables Start', (tester) async {
      final scanner = ParcelScannerStub(['nw-ae-1028810-1 ']);
      final transport = await open(tester, scanner);
      expect(startEnabled(tester), isFalse);

      await tester.tap(find.byKey(const Key('pickup-scan')));
      await tester.pumpAndSettle();
      expect(scanner.calls, 1);
      expect(find.byKey(const Key('pickup-verified')), findsOneWidget);
      expect(tester.widget<CheckboxListTile>(find.byKey(const Key('parcel-1'))).value, isTrue);

      await tester.tap(find.byKey(const Key('pickup-start')));
      await tester.pumpAndSettle();
      expect(transport.sent('POST delivery/assignments/DA-1001/start'), hasLength(1));
    });

    testWidgets("another parcel's code is refused", (tester) async {
      await open(tester, ParcelScannerStub(['NW-AE-9999999-1']));
      await tester.tap(find.byKey(const Key('pickup-scan')));
      await tester.pumpAndSettle();
      expect(find.text('NW-AE-9999999-1 is not this parcel. Check the label.'), findsOneWidget);
      expect(find.byKey(const Key('pickup-verified')), findsNothing);
      expect(startEnabled(tester), isFalse);
    });

    testWidgets('camera closed or failing: the agent types the shipment number', (tester) async {
      final scanner = ParcelScannerStub([null]);
      await open(tester, scanner);
      await tester.tap(find.byKey(const Key('pickup-scan')));
      await tester.pumpAndSettle();
      final field = tester.widget<TextField>(find.byKey(const Key('pickup-code')));
      expect(field.focusNode!.hasFocus, isTrue);

      await tester.enterText(find.byKey(const Key('pickup-code')), 'NW-AE-1028810-1');
      await tester.tap(find.byKey(const Key('pickup-code-check')));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('pickup-verified')), findsOneWidget);
      expect(startEnabled(tester), isTrue);
    });

    test('codes match the shipment, assignment or order number', () {
      final a = Assignment(assignmentJson());
      expect(pickupCodeMatches(a, ' nw-ae-1028810-1'), isTrue);
      expect(pickupCodeMatches(a, 'DA-1001'), isTrue);
      expect(pickupCodeMatches(a, 'NW-AE-1028810'), isTrue);
      expect(pickupCodeMatches(a, 'NW-AE-1028810-2'), isFalse);
      expect(pickupCodeMatches(a, '  '), isFalse);
    });
  });

  group('GPS while online', () {
    testWidgets('online agent is asked for the permission, then pings start; going offline stops them', (tester) async {
      var available = true;
      final transport = FakeTransport({
        'GET delivery/me': (HttpRequestData _) => FakeTransport.ok({'name': 'Rashid Ali', 'available': available, 'status': 'active', 'cod_in_hand': '0.0000'}),
        'GET delivery/assignments': const <Object>[],
        'POST delivery/me/location': {'saved': true},
        'POST delivery/me/availability': (HttpRequestData _) {
          available = false;
          return FakeTransport.ok({'available': false});
        },
      });
      final source = _Source();
      final timers = <_Timer>[];
      final session = await testSession(transport);
      await tester.pumpWidget(DeliveryApp(
        session: session,
        location: source,
        pingScheduler: (interval, tick) {
          final t = _Timer(tick);
          timers.add(t);
          return t;
        },
      ));
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('gps-banner')), findsOneWidget);
      expect(transport.sent('POST delivery/me/location'), isEmpty);

      await tester.tap(find.byKey(const Key('gps-allow')));
      await tester.pumpAndSettle();
      expect(source.requests, 1);
      expect(find.byKey(const Key('gps-banner')), findsNothing);
      expect(transport.sent('POST delivery/me/location'), hasLength(1));

      timers.single.callback(timers.single);
      await tester.pumpAndSettle();
      expect(transport.sent('POST delivery/me/location'), hasLength(2));

      await tester.tap(find.byKey(const Key('availability')));
      await tester.pumpAndSettle();
      expect(timers.single.cancelled, isTrue);
    });
  });

  testWidgets("the customer's phone opens the dialler", (tester) async {
    final launched = <Uri>[];
    PlatformBridge.debugLaunch = (uri) async {
      launched.add(uri);
      return true;
    };
    addTearDown(() => PlatformBridge.debugLaunch = null);
    await pumpScreen(tester, Scaffold(body: PhoneLink(Assignment(assignmentJson()).customerPhone!)), FakeTransport());
    await tester.tap(find.text('+971501234567'));
    await tester.pumpAndSettle();
    expect(launched.single.toString(), 'tel:+971501234567');
  });
}

class ParcelScannerStub {
  ParcelScannerStub(this.results);

  final List<String?> results;
  int calls = 0;

  Future<String?> call(BuildContext context) async => results[calls++];
}

class _Source extends LocationSource {
  LocationAccess state = LocationAccess.notAsked;
  int requests = 0;

  @override
  bool get available => true;

  @override
  Future<LocationAccess> access() async => state;

  @override
  Future<LocationAccess> requestAccess() async {
    requests++;
    return state = LocationAccess.granted;
  }

  @override
  Future<({double lat, double lng})?> current() async => (lat: 23.58, lng: 58.38);

  @override
  Future<bool> openSettings() async => true;
}

class _Timer implements Timer {
  _Timer(this.callback);

  final void Function(Timer) callback;
  bool cancelled = false;

  @override
  void cancel() => cancelled = true;

  @override
  bool get isActive => !cancelled;

  @override
  int get tick => 0;
}
