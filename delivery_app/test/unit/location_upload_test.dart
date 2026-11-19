import 'dart:async';
import 'dart:io';

import 'package:delivery_app/data/location.dart';
import 'package:delivery_app/data/models.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nawy_core/nawy_core.dart';
import 'package:nawy_core/testing.dart';

import '../helpers.dart';

/// Background tracking while a stop is in progress: throttle, heartbeat,
/// retry of the latest fix, and the stream's start / stop.
void main() {
  final t0 = DateTime.utc(2026, 11, 19, 8);
  // ~111 m per 0.001° of latitude.
  LocationFix fix(double dLat, int seconds, {double? accuracy = 8}) => LocationFix(lat: 25.0 + dLat, lng: 55.0, accuracy: accuracy, recordedAt: t0.add(Duration(seconds: seconds)));

  ApiClient client(FakeTransport t) => ApiClient(baseUrl: 'http://x/api/v1', transport: t)..market = 'uae-en';

  group('PingPolicy', () {
    const policy = PingPolicy();

    test('first fix, then 15 s and 50 m, or the 60 s heartbeat', () {
      final last = fix(0, 0);
      expect(policy.shouldSend(last, now: t0), isTrue);
      // Moved 111 m but only 10 s later.
      expect(policy.shouldSend(fix(0.001, 10), last: last, lastAt: t0, now: t0.add(const Duration(seconds: 10))), isFalse);
      // 20 s and 111 m.
      expect(policy.shouldSend(fix(0.001, 20), last: last, lastAt: t0, now: t0.add(const Duration(seconds: 20))), isTrue);
      // 20 s but only ~22 m.
      expect(policy.shouldSend(fix(0.0002, 20), last: last, lastAt: t0, now: t0.add(const Duration(seconds: 20))), isFalse);
      // Standing still for 60 s: heartbeat.
      expect(policy.shouldSend(fix(0, 60), last: last, lastAt: t0, now: t0.add(const Duration(seconds: 60))), isTrue);
    });

    test('inaccurate fixes (> 100 m) are skipped; unknown accuracy is kept', () {
      expect(policy.shouldSend(fix(0, 0, accuracy: 150), now: t0), isFalse);
      expect(policy.shouldSend(fix(0, 0, accuracy: null), now: t0), isTrue);
    });

    test('distance is metres on the ground', () {
      expect(distanceMeters(fix(0, 0), fix(0.001, 0)), closeTo(111.2, 0.5));
    });
  });

  group('LocationUploader', () {
    test('posts lat, lng, accuracy, heading, speed and recorded_at; throttles', () async {
      var now = t0;
      final transport = FakeTransport({'POST delivery/me/location': {'saved': true}});
      final up = LocationUploader(client(transport), clock: () => now);

      final first = LocationFix(lat: 25.1, lng: 55.2, accuracy: 6.5, heading: 90, speed: 8.3, recordedAt: t0);
      expect(await up.offer(first), isTrue);
      expect(FakeTransport.bodyOf(transport.requests.single), {'lat': 25.1, 'lng': 55.2, 'accuracy': 6.5, 'heading': 90, 'speed': 8.3, 'recorded_at': '2026-11-19T08:00:00.000Z'});

      now = t0.add(const Duration(seconds: 5));
      expect(await up.offer(fix(0.01, 5)), isFalse, reason: 'within 15 s');
      now = t0.add(const Duration(seconds: 16));
      expect(await up.offer(fix(0.0101, 16)), isTrue, reason: 'moved and 15 s passed');
      now = t0.add(const Duration(seconds: 40));
      expect(await up.offer(fix(0.0101, 40, accuracy: 300)), isFalse, reason: 'inaccurate');
      expect(up.sent, 2);
    });

    test('no connection: the latest fix waits and is retried; a newer one replaces it', () async {
      var now = t0;
      var online = false;
      final transport = FakeTransport({
        'POST delivery/me/location': (HttpRequestData _) {
          if (!online) throw const SocketException('offline');
          return FakeTransport.ok({'saved': true});
        },
      });
      final up = LocationUploader(client(transport), clock: () => now);

      expect(await up.offer(fix(0, 0)), isFalse);
      expect(up.pending?.lat, 25.0);

      now = t0.add(const Duration(seconds: 5));
      expect(await up.offer(fix(0.002, 5)), isFalse);
      expect(up.pending?.lat, 25.002, reason: 'only the latest position matters');
      expect(transport.requests, hasLength(1), reason: 'no retry within 15 s');

      online = true;
      now = t0.add(const Duration(seconds: 16));
      expect(await up.tick(), isTrue);
      expect(up.pending, isNull);
      expect(FakeTransport.bodyOf(transport.requests.last)['lat'], 25.002);
      expect(up.lastSentAt, now);
    });

    test('a refused fix (422) is dropped, not retried', () async {
      final transport = FakeTransport({'POST delivery/me/location': FakeTransport.error(422, 'validation_failed', 'Bad lat')});
      final up = LocationUploader(client(transport), clock: () => t0);
      expect(await up.offer(fix(0, 0)), isFalse);
      expect(up.pending, isNull);
    });

    test('heartbeat: nothing sent for 60 s → a fresh fix (or the latest) goes out', () async {
      var now = t0;
      final transport = FakeTransport({'POST delivery/me/location': {'saved': true}});
      final up = LocationUploader(client(transport), clock: () => now);
      expect(await up.tick(), isFalse, reason: 'no fix yet');
      await up.offer(fix(0, 0));

      now = t0.add(const Duration(seconds: 30));
      expect(await up.tick(fresh: () async => fix(0, 30)), isFalse);
      now = t0.add(const Duration(seconds: 61));
      expect(await up.tick(fresh: () async => fix(0.00001, 61)), isTrue);
      expect(FakeTransport.bodyOf(transport.requests.last)['lat'], 25.00001);

      now = t0.add(const Duration(seconds: 125));
      expect(await up.tick(fresh: () async => null), isTrue, reason: 'no fresh fix: the latest again');
      expect(transport.requests, hasLength(3));
    });
  });

  group('DutyTracker on the road', () {
    late DateTime now;
    late _TripSource source;
    late _Scheduler scheduler;
    late FakeTransport transport;

    DutyTracker make() {
      now = t0;
      source = _TripSource();
      scheduler = _Scheduler();
      transport = FakeTransport({'POST delivery/me/location': {'saved': true}});
      return DutyTracker(client(transport), source, scheduler: scheduler.call, clock: () => now)..notice = (title: 'Delivery in progress', text: 'Sharing');
    }

    Assignment stop(String status) => Assignment(assignmentJson(status: status));

    test('a picked-up stop starts the stream (also offline); finishing it stops stream and timer', () async {
      final tracker = make();
      await tracker.updateStops([stop('accepted')]);
      expect(tracker.tracking, isFalse);
      expect(source.tracks, 0);

      await tracker.updateStops([stop('picked_up')]);
      expect(tracker.tracking, isTrue);
      expect(source.lastNotice?.title, 'Delivery in progress');
      expect(scheduler.timers.single.interval, const Duration(seconds: 15));

      source.emit(fix(0, 0));
      await pumpEventQueue();
      expect(transport.sent('POST delivery/me/location'), hasLength(1));
      expect(tracker.sent, 1);

      // Heartbeat after 60 s standing still: the source's current fix.
      now = t0.add(const Duration(seconds: 61));
      await scheduler.timers.single.fire();
      expect(transport.sent('POST delivery/me/location'), hasLength(2));
      expect(FakeTransport.bodyOf(transport.requests.last)['lat'], 23.58);

      await tracker.noteStop(stop('delivered'));
      expect(tracker.tracking, isFalse);
      expect(source.listening, isFalse, reason: 'stream (and the Android service) stopped');
      expect(scheduler.timers.single.cancelled, isTrue);
      expect(tracker.state, DutyGps.offDuty);
    });

    test('online: the 2-minute timer gives way to the stream and comes back after', () async {
      final tracker = make();
      await tracker.setOnDuty(true);
      expect(tracker.running, isTrue);
      final dutyTimer = scheduler.timers.single;

      await tracker.noteStop(stop('picked_up'));
      expect(dutyTimer.cancelled, isTrue);
      expect(tracker.tracking, isTrue);

      await tracker.updateStops(const []);
      expect(tracker.tracking, isFalse);
      expect(tracker.running, isTrue);
      expect(scheduler.timers.last.interval, const Duration(minutes: 2));
    });

    test('"while in use" only: asks for "all the time" with a way to say not now', () async {
      final tracker = make();
      await tracker.updateStops([stop('picked_up')]);
      expect(tracker.state, DutyGps.needsBackground);
      tracker.dismissBackground();
      expect(tracker.state, DutyGps.sharing);

      final again = make();
      await again.updateStops([stop('picked_up')]);
      source.onBackgroundRequest = true;
      await again.requestBackground();
      expect(source.backgroundRequests, 1);
      expect(again.state, DutyGps.sharing);
    });

    test('not asked yet: no stream until allowed', () async {
      final tracker = make();
      source.state = LocationAccess.notAsked;
      await tracker.updateStops([stop('picked_up')]);
      expect(tracker.tracking, isFalse);
      expect(tracker.state, DutyGps.needsPermission);
      await tracker.requestAccess();
      expect(tracker.tracking, isTrue);
    });

    test('GPS switched off mid-trip: stops, shows blocked, opens the location settings; resumes on recheck', () async {
      final tracker = make();
      await tracker.updateStops([stop('picked_up')]);
      source.state = LocationAccess.servicesOff;
      source.fail(Exception('location service disabled'));
      await pumpEventQueue();
      expect(tracker.tracking, isFalse);
      expect(tracker.state, DutyGps.blocked);
      await tracker.openSettings();
      expect(source.locationSettingsOpened, 1);

      source.state = LocationAccess.granted;
      await tracker.recheck();
      expect(tracker.tracking, isTrue);
    });

    test('sign-out (dispose) stops the stream and the heartbeat', () async {
      final tracker = make();
      await tracker.updateStops([stop('picked_up')]);
      tracker.dispose();
      expect(source.listening, isFalse);
      expect(scheduler.timers.single.cancelled, isTrue);
    });
  });
}

class _TripSource extends LocationSource {
  LocationAccess state = LocationAccess.granted;
  bool always = false;
  bool onBackgroundRequest = false;
  int backgroundRequests = 0;
  int tracks = 0;
  int locationSettingsOpened = 0;
  TrackingNotice? lastNotice;
  StreamController<LocationFix>? _controller;

  bool get listening => _controller?.hasListener ?? false;

  void emit(LocationFix f) => _controller!.add(f);
  void fail(Object error) => _controller!.addError(error);

  @override
  bool get available => true;

  @override
  Future<LocationAccess> access() async => state;

  @override
  Future<LocationAccess> requestAccess() async => state = LocationAccess.granted;

  @override
  Future<({double lat, double lng})?> current() async => (lat: 23.58, lng: 58.38);

  @override
  Future<bool> backgroundAllowed() async => always;

  @override
  Future<bool> requestBackground() async {
    backgroundRequests++;
    return always = onBackgroundRequest;
  }

  @override
  Future<bool> openLocationSettings() async {
    locationSettingsOpened++;
    return true;
  }

  @override
  Stream<LocationFix> track(TrackingNotice notice) {
    tracks++;
    lastNotice = notice;
    _controller = StreamController<LocationFix>();
    return _controller!.stream;
  }
}

class _Scheduler {
  final timers = <_Timer>[];

  Timer call(Duration interval, void Function(Timer) tick) {
    final t = _Timer(interval, tick);
    timers.add(t);
    return t;
  }
}

class _Timer implements Timer {
  _Timer(this.interval, this.callback);

  final Duration interval;
  final void Function(Timer) callback;
  bool cancelled = false;

  Future<void> fire() async {
    callback(this);
    await pumpEventQueue();
  }

  @override
  void cancel() => cancelled = true;

  @override
  bool get isActive => !cancelled;

  @override
  int get tick => 0;
}
