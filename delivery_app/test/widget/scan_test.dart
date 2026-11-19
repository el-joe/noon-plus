import 'dart:io';

import 'package:delivery_app/data/models.dart';
import 'package:delivery_app/screens/pickup.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nawy_core/nawy_core.dart';
import 'package:nawy_core/testing.dart';

import '../helpers.dart';

/// Parcel label check (Code 39 mod 43) at pickup, confirmed by the API.
void main() {
  final label = 'NW-AE-1028810-1.${parcelCheckChar('NW-AE-1028810-1')}';
  final otherLabel = 'NW-AE-9999999-1.${parcelCheckChar('NW-AE-9999999-1')}';

  Future<FakeTransport> open(WidgetTester tester, List<String?> codes, {Object? scan}) async {
    final transport = FakeTransport({'POST delivery/assignments/DA-1001/scan': ?scan});
    var i = 0;
    await pumpScreen(tester, PickupScreen(assignment: Assignment(assignmentJson(status: 'accepted')), scanner: (_) async => codes[i++]), transport);
    await tester.tap(find.byKey(const Key('pickup-scan')));
    await tester.pumpAndSettle();
    return transport;
  }

  testWidgets('a matching label is confirmed by the API and ticks the parcel', (tester) async {
    final transport = await open(tester, ['*$label*'], scan: {'matches': true, 'shipment_number': 'NW-AE-1028810-1'});
    expect(find.byKey(const Key('pickup-verified')), findsOneWidget);
    expect(FakeTransport.bodyOf(transport.sent('POST delivery/assignments/DA-1001/scan').single), {'code': label});
  });

  testWidgets('a bad check character asks to scan again, without calling the API', (tester) async {
    final transport = await open(tester, ['NW-AE-1028810-1.Q']);
    expect(find.text("This label didn't scan cleanly — scan again."), findsOneWidget);
    expect(find.byKey(const Key('pickup-verified')), findsNothing);
    expect(transport.requests, isEmpty);
  });

  testWidgets("another parcel's label names its shipment", (tester) async {
    final transport = await open(tester, [otherLabel]);
    expect(find.text('This parcel belongs to another delivery (NW-AE-9999999-1).'), findsOneWidget);
    expect(transport.requests, isEmpty);
  });

  testWidgets('the API refusing (wrong_parcel) wins over the phone', (tester) async {
    await open(tester, [label], scan: FakeTransport.error(422, 'wrong_parcel', 'This parcel belongs to another delivery (NW-AE-1).'));
    expect(find.text('This parcel belongs to another delivery (NW-AE-1).'), findsOneWidget);
    expect(find.byKey(const Key('pickup-verified')), findsNothing);
    expect(tester.widget<FilledButton>(find.byKey(const Key('pickup-start'))).onPressed, isNull);
  });

  testWidgets('offline, the phone check stands', (tester) async {
    await open(tester, [label], scan: (HttpRequestData _) => throw const SocketException('offline'));
    expect(find.byKey(const Key('pickup-verified')), findsOneWidget);
    expect(tester.widget<FilledButton>(find.byKey(const Key('pickup-start'))).onPressed, isNotNull);
  });

  testWidgets('Arabic message for a bad label', (tester) async {
    final transport = FakeTransport();
    await pumpScreen(tester, PickupScreen(assignment: Assignment(assignmentJson(status: 'accepted')), scanner: (_) async => 'NW-AE-1028810-1.Q'), transport, locale: 'ar');
    await tester.tap(find.byKey(const Key('pickup-scan')));
    await tester.pumpAndSettle();
    expect(find.text('لم تتم قراءة الملصق بشكل سليم — امسحه مرة أخرى.'), findsOneWidget);
  });
}
