import 'dart:async';

import 'package:delivery_app/app.dart';
import 'package:delivery_app/data/location.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nawy_core/testing.dart';

import '../helpers.dart';

/// The app tracks while a stop is picked up, explains "all the time" and
/// stops on sign-out.
void main() {
  testWidgets('a picked-up stop starts tracking with the notice; "all the time" is explained; sign-out stops it', (tester) async {
    final transport = FakeTransport({
      'GET delivery/me': {'name': 'Rashid Ali', 'available': true, 'status': 'active', 'cod_in_hand': '0.0000'},
      'GET delivery/assignments': [assignmentJson(status: 'picked_up')],
      'POST delivery/me/location': {'saved': true},
      'POST auth/logout': {'ok': true},
    });
    final source = _Source();
    final session = await testSession(transport);
    await tester.pumpWidget(DeliveryApp(session: session, location: source, pingScheduler: (interval, tick) => _Timer()));
    await tester.pumpAndSettle();

    expect(source.notice?.title, 'Delivery in progress');
    expect(source.controller?.hasListener, isTrue);
    expect(find.text('Keep sharing with the screen off'), findsOneWidget);

    source.controller!.add(LocationFix(lat: 25.1, lng: 55.2, accuracy: 5, recordedAt: DateTime.utc(2026, 11, 19, 8)));
    await tester.pumpAndSettle();
    expect(FakeTransport.bodyOf(transport.sent('POST delivery/me/location').last)['recorded_at'], '2026-11-19T08:00:00.000Z');

    await tester.tap(find.byKey(const Key('gps-not-now')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('gps-banner')), findsNothing);

    await session.signOut();
    await tester.pumpAndSettle();
    expect(source.controller!.hasListener, isFalse);
  });
}

class _Source extends LocationSource {
  StreamController<LocationFix>? controller;
  TrackingNotice? notice;

  @override
  bool get available => true;

  @override
  Future<LocationAccess> access() async => LocationAccess.granted;

  @override
  Future<LocationAccess> requestAccess() async => LocationAccess.granted;

  @override
  Future<({double lat, double lng})?> current() async => (lat: 25.0, lng: 55.0);

  @override
  Stream<LocationFix> track(TrackingNotice notice) {
    this.notice = notice;
    controller = StreamController<LocationFix>();
    return controller!.stream;
  }
}

class _Timer implements Timer {
  bool cancelled = false;

  @override
  void cancel() => cancelled = true;

  @override
  bool get isActive => !cancelled;

  @override
  int get tick => 0;
}
