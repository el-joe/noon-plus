import 'dart:async';
import 'dart:math' as math;

import 'package:flutter/foundation.dart' show defaultTargetPlatform, kIsWeb, setEquals, TargetPlatform;
import 'package:flutter/services.dart';
import 'package:flutter/widgets.dart';
import 'package:geolocator/geolocator.dart';
import 'package:nawy_core/nawy_core.dart';

import 'models.dart';

/// GPS for dispatch (owner review W6, P9) and live tracking on the road.
///
/// [DutyTracker] has two modes:
/// * on duty (online), no parcel on board: one `POST delivery/me/location`
///   every [DutyTracker.interval] while the app is open (the dispatch
///   "nearest" auto-assign);
/// * a stop in progress ([Assignment.isInProgress]: picked up, out for
///   delivery, arrived): the [LocationSource.track] position stream, which
///   keeps running with the app in the background (Android foreground
///   service with an ongoing notification; iOS background location updates
///   with the blue indicator). Fixes go through [LocationUploader] (at most
///   one ping per 15 s, only after moving 50 m, a heartbeat every 60 s,
///   inaccurate fixes skipped, the latest unsent fix retried).
///
/// Everything stops when no stop is in progress and the agent is offline,
/// and on sign-out (the shell disposes the tracker).

/// Whether the app may read the location.
enum LocationAccess {
  granted,

  /// Not asked yet (or "ask every time"): show the explanation and ask.
  notAsked,

  /// Refused; on Android it can be asked again.
  denied,

  /// Refused for good: only the system settings can change it.
  deniedForever,

  /// Location services (GPS) are switched off on the device.
  servicesOff,

  /// No location support on this platform.
  unsupported,
}

/// One position fix for `POST delivery/me/location`.
class LocationFix {
  const LocationFix({required this.lat, required this.lng, required this.recordedAt, this.accuracy, this.heading, this.speed});

  final double lat;
  final double lng;

  /// Horizontal accuracy in metres.
  final double? accuracy;

  /// Degrees from north (0–360).
  final double? heading;

  /// Metres per second.
  final double? speed;
  final DateTime recordedAt;

  Json toJson() => {
        'lat': lat,
        'lng': lng,
        'accuracy': ?accuracy,
        'heading': ?heading,
        'speed': ?speed,
        'recorded_at': recordedAt.toUtc().toIso8601String(),
      };
}

/// Great-circle distance in metres (haversine).
double distanceMeters(LocationFix a, LocationFix b) {
  const r = 6371000.0;
  double rad(double d) => d * math.pi / 180;
  final dLat = rad(b.lat - a.lat);
  final dLng = rad(b.lng - a.lng);
  final h = math.pow(math.sin(dLat / 2), 2) + math.cos(rad(a.lat)) * math.cos(rad(b.lat)) * math.pow(math.sin(dLng / 2), 2);
  return 2 * r * math.asin(math.min(1, math.sqrt(h)));
}

/// The ongoing notification of the Android foreground service.
typedef TrackingNotice = ({String title, String text});

abstract class LocationSource {
  const LocationSource();

  /// Whether this platform has a location API at all.
  bool get available;

  /// Current permission state, without prompting.
  Future<LocationAccess> access();

  /// Shows the system permission prompt (when it still can).
  Future<LocationAccess> requestAccess();

  /// One position fix, or null.
  Future<({double lat, double lng})?> current();

  /// Opens the app's system settings (for "denied forever").
  Future<bool> openSettings() async => false;

  /// Opens the device's location settings (GPS switched off).
  Future<bool> openLocationSettings() async => false;

  /// Location allowed "all the time" (not only while the app is in use).
  Future<bool> backgroundAllowed() async => false;

  /// Asks for "all the time" (Android 10+: a second prompt or the settings
  /// page; iOS: the settings app). Whether it is allowed now.
  Future<bool> requestBackground() async => false;

  /// Position fixes while a stop is in progress, also with the app in the
  /// background ([notice] is the Android notification). Cancelling the
  /// subscription stops the updates and the service.
  Stream<LocationFix> track(TrackingNotice notice) => const Stream.empty();
}

class NoLocationSource extends LocationSource {
  const NoLocationSource();

  @override
  bool get available => false;

  @override
  Future<LocationAccess> access() async => LocationAccess.unsupported;

  @override
  Future<LocationAccess> requestAccess() async => LocationAccess.unsupported;

  @override
  Future<({double lat, double lng})?> current() async => null;
}

/// `geolocator` on Android / iOS (and the browser on the web).
class GeolocatorLocationSource extends LocationSource {
  const GeolocatorLocationSource();

  /// `MainActivity`: asks for POST_NOTIFICATIONS (Android 13+) so the
  /// tracking notification shows.
  static const _permissions = MethodChannel('nawy.delivery/permissions');

  static bool get _android => !kIsWeb && defaultTargetPlatform == TargetPlatform.android;
  static bool get _ios => !kIsWeb && defaultTargetPlatform == TargetPlatform.iOS;

  @override
  bool get available => kIsWeb || _android || _ios;

  static LocationAccess _map(LocationPermission p) => switch (p) {
        LocationPermission.always || LocationPermission.whileInUse => LocationAccess.granted,
        LocationPermission.denied => LocationAccess.notAsked,
        LocationPermission.deniedForever => LocationAccess.deniedForever,
        LocationPermission.unableToDetermine => LocationAccess.notAsked,
      };

  @override
  Future<LocationAccess> access() async {
    if (!available) return LocationAccess.unsupported;
    try {
      if (!kIsWeb && !await Geolocator.isLocationServiceEnabled()) return LocationAccess.servicesOff;
      return _map(await Geolocator.checkPermission());
    } catch (_) {
      return LocationAccess.unsupported;
    }
  }

  @override
  Future<LocationAccess> requestAccess() async {
    if (!available) return LocationAccess.unsupported;
    try {
      final result = _map(await Geolocator.requestPermission());
      // Asked and still "denied" means the agent refused this time.
      return result == LocationAccess.notAsked ? LocationAccess.denied : result;
    } catch (_) {
      return LocationAccess.unsupported;
    }
  }

  @override
  Future<bool> backgroundAllowed() async {
    if (kIsWeb || !available) return false;
    try {
      return await Geolocator.checkPermission() == LocationPermission.always;
    } catch (_) {
      return false;
    }
  }

  @override
  Future<bool> requestBackground() async {
    if (kIsWeb || !available) return false;
    try {
      if (await Geolocator.checkPermission() == LocationPermission.always) return true;
      if (_android) {
        // With "while in use" granted, geolocator asks for
        // ACCESS_BACKGROUND_LOCATION (Android 11+ opens the settings page).
        final p = await Geolocator.requestPermission();
        if (p == LocationPermission.always) return true;
      }
      // iOS cannot be asked twice: "Always" is chosen in the settings app.
      await Geolocator.openAppSettings();
      return await Geolocator.checkPermission() == LocationPermission.always;
    } catch (_) {
      return false;
    }
  }

  @override
  Future<({double lat, double lng})?> current() async {
    try {
      final p = await Geolocator.getCurrentPosition(locationSettings: const LocationSettings(accuracy: LocationAccuracy.high, timeLimit: Duration(seconds: 20)));
      return (lat: p.latitude, lng: p.longitude);
    } catch (_) {
      return null;
    }
  }

  @override
  Future<bool> openSettings() async {
    try {
      return await Geolocator.openAppSettings();
    } catch (_) {
      return false;
    }
  }

  @override
  Future<bool> openLocationSettings() async {
    try {
      return await Geolocator.openLocationSettings();
    } catch (_) {
      return false;
    }
  }

  static LocationSettings settingsFor(TrackingNotice notice) {
    if (_android) {
      return AndroidSettings(
        accuracy: LocationAccuracy.high,
        distanceFilter: 0,
        intervalDuration: const Duration(seconds: 10),
        foregroundNotificationConfig: ForegroundNotificationConfig(
          notificationTitle: notice.title,
          notificationText: notice.text,
          notificationChannelName: notice.title,
          enableWakeLock: true,
          setOngoing: true,
        ),
      );
    }
    if (_ios) {
      return AppleSettings(
        accuracy: LocationAccuracy.high,
        distanceFilter: 10,
        activityType: ActivityType.automotiveNavigation,
        pauseLocationUpdatesAutomatically: false,
        showBackgroundLocationIndicator: true,
        allowBackgroundLocationUpdates: true,
      );
    }
    return const LocationSettings(accuracy: LocationAccuracy.high, distanceFilter: 10);
  }

  @override
  Stream<LocationFix> track(TrackingNotice notice) async* {
    if (_android) {
      try {
        await _permissions.invokeMethod<bool>('requestNotifications');
      } catch (_) {
        // The service still runs; the notification may just be hidden.
      }
    }
    yield* Geolocator.getPositionStream(locationSettings: settingsFor(notice)).map((p) => LocationFix(
          lat: p.latitude,
          lng: p.longitude,
          accuracy: p.accuracy > 0 ? p.accuracy : null,
          heading: p.heading >= 0 && p.heading <= 360 && p.speed > 0.5 ? p.heading : null,
          speed: p.speed >= 0 ? p.speed : null,
          recordedAt: p.timestamp,
        ));
  }
}

/// When a fix is worth a ping while a stop is in progress.
class PingPolicy {
  const PingPolicy({
    this.minInterval = const Duration(seconds: 15),
    this.minDistance = 50,
    this.heartbeat = const Duration(seconds: 60),
    this.maxAccuracy = 100,
  });

  /// No more than one ping per this (also the retry wait after a failure).
  final Duration minInterval;

  /// Metres moved since the last ping before a new one is sent.
  final double minDistance;

  /// A ping at least this often while a stop is in progress, moved or not.
  final Duration heartbeat;

  /// Fixes less accurate than this (metres) are skipped.
  final double maxAccuracy;

  bool accurate(LocationFix fix) => fix.accuracy == null || fix.accuracy! <= maxAccuracy;

  /// The first fix; then after [heartbeat]; or after [minInterval] when the
  /// agent moved [minDistance] metres since [last].
  bool shouldSend(LocationFix fix, {LocationFix? last, DateTime? lastAt, required DateTime now}) {
    if (!accurate(fix)) return false;
    if (last == null || lastAt == null) return true;
    final elapsed = now.difference(lastAt);
    if (elapsed >= heartbeat) return true;
    return elapsed >= minInterval && distanceMeters(last, fix) >= minDistance;
  }
}

/// Posts trip fixes to `POST delivery/me/location` following [PingPolicy].
/// When a ping fails for want of a connection (or a 5xx / 429) the fix is
/// kept as [pending] and retried; a newer fix replaces it (only the latest
/// position matters). A refused fix (422) is dropped. Pure apart from the
/// API, so it is unit tested with a fake clock.
class LocationUploader {
  LocationUploader(this.api, {this.policy = const PingPolicy(), DateTime Function()? clock}) : _clock = clock ?? DateTime.now;

  final ApiClient api;
  final PingPolicy policy;
  final DateTime Function() _clock;

  LocationFix? lastSent;
  DateTime? lastSentAt;

  /// The latest fix not sent yet because the last attempt failed (or one
  /// was in flight).
  LocationFix? pending;

  /// The newest accurate fix seen.
  LocationFix? latest;
  DateTime? _lastAttemptAt;
  bool _sending = false;
  int sent = 0;

  /// A new fix from the stream. True when it was sent.
  Future<bool> offer(LocationFix fix) async {
    if (!policy.accurate(fix)) return false;
    latest = fix;
    final now = _clock();
    if (pending != null) {
      pending = fix;
      return _retryDue(now) ? _send(fix) : false;
    }
    if (!policy.shouldSend(fix, last: lastSent, lastAt: lastSentAt, now: now)) return false;
    return _send(fix);
  }

  /// The periodic tick: retries [pending], and sends the heartbeat when
  /// nothing went out for [PingPolicy.heartbeat] ([fresh] gives a new fix,
  /// else the latest one is sent again).
  Future<bool> tick({Future<LocationFix?> Function()? fresh}) async {
    final now = _clock();
    if (pending != null) return _retryDue(now) ? _send(pending!) : false;
    if (lastSentAt != null && now.difference(lastSentAt!) < policy.heartbeat) return false;
    var fix = fresh == null ? null : await fresh();
    if (fix != null && !policy.accurate(fix)) fix = null;
    fix ??= latest;
    if (fix == null) return false;
    latest = fix;
    return _send(fix);
  }

  bool _retryDue(DateTime now) => _lastAttemptAt == null || now.difference(_lastAttemptAt!) >= policy.minInterval;

  Future<bool> _send(LocationFix fix) async {
    if (_sending) {
      pending = fix;
      return false;
    }
    _sending = true;
    _lastAttemptAt = _clock();
    try {
      await api.post('delivery/me/location', body: fix.toJson());
      lastSent = fix;
      lastSentAt = _clock();
      if (identical(pending, fix)) pending = null;
      sent++;
      return true;
    } on ApiException catch (e) {
      final retry = e.isNetwork || e.isRateLimited || e.status >= 500;
      if (retry) {
        pending ??= fix;
      } else if (identical(pending, fix)) {
        pending = null;
      }
      return false;
    } finally {
      _sending = false;
    }
  }

  void reset() {
    pending = null;
    latest = null;
  }
}

/// Starts a repeating timer (tests pass a fake to tick by hand).
typedef PingScheduler = Timer Function(Duration interval, void Function(Timer timer) tick);

/// What the GPS row and the "share your location" banner show.
enum DutyGps {
  /// Offline with nothing on board: nothing is shared.
  offDuty,

  /// Sending pings (online, or on the road with a parcel).
  sharing,

  /// On the road and sharing, but only "while using the app": ask for "all
  /// the time" so it keeps going with the screen off.
  needsBackground,

  /// Online (or on the road) but the permission has not been asked yet.
  needsPermission,

  /// Refused, or GPS is off.
  blocked,

  /// No location on this device / platform.
  unsupported,
}

/// Sends location pings while the agent is on duty (available) and tracks
/// them while a stop is in progress (see the library comment).
class DutyTracker extends ChangeNotifier {
  DutyTracker(this.api, this.source, {this.interval = const Duration(minutes: 2), PingScheduler? scheduler, DateTime Function()? clock, PingPolicy policy = const PingPolicy()})
      : _scheduler = scheduler ?? Timer.periodic,
        _clock = clock ?? DateTime.now,
        uploader = LocationUploader(api, policy: policy, clock: clock);

  final ApiClient api;
  final LocationSource source;
  final Duration interval;
  final PingScheduler _scheduler;
  final DateTime Function() _clock;

  /// Trip pings (throttle and retry).
  final LocationUploader uploader;

  /// The Android notification while tracking (the shell sets it in the
  /// agent's language).
  TrackingNotice notice = (title: 'Nawy Delivery', text: 'Sharing your location during the delivery');

  Timer? _timer;
  Timer? _heartbeat;
  Timer? _restart;
  StreamSubscription<LocationFix>? _trip;
  bool _onDuty = false;
  bool _disposed = false;
  bool _always = false;
  bool _backgroundDismissed = false;
  final Set<String> _inProgress = {};
  LocationAccess _access = LocationAccess.notAsked;
  bool _accessChecked = false;
  DateTime? _lastSentAt;
  int _sent = 0;

  bool get onDuty => _onDuty;

  /// The on-duty timer runs.
  bool get running => _timer != null;

  /// The trip position stream runs.
  bool get tracking => _trip != null;

  /// A stop is in progress (picked up / out for delivery / arrived).
  bool get onTrip => _inProgress.isNotEmpty;
  LocationAccess get access => _access;
  DateTime? get lastSentAt => uploader.lastSentAt != null && (_lastSentAt == null || uploader.lastSentAt!.isAfter(_lastSentAt!)) ? uploader.lastSentAt : _lastSentAt;
  int get sent => _sent + uploader.sent;

  DutyGps get state {
    if (!source.available || _access == LocationAccess.unsupported) return DutyGps.unsupported;
    if (!_onDuty && !onTrip) return DutyGps.offDuty;
    if (tracking) return !_always && !_backgroundDismissed ? DutyGps.needsBackground : DutyGps.sharing;
    if (running) return DutyGps.sharing;
    if (_access == LocationAccess.notAsked) return DutyGps.needsPermission;
    return DutyGps.blocked;
  }

  void _changed() {
    if (!_disposed) notifyListeners();
  }

  /// Follows the availability from `GET delivery/me` / the online switch.
  Future<void> setOnDuty(bool onDuty) async {
    if (onDuty == _onDuty && (running || tracking || !onDuty)) return;
    _onDuty = onDuty;
    await _refreshAccess(force: onDuty);
    _apply();
    _changed();
  }

  /// The open stops from the list: tracks while one is in progress.
  Future<void> updateStops(Iterable<Assignment> open) async {
    final now = {for (final a in open) if (a.isInProgress) a.number};
    if (setEquals(now, _inProgress)) return;
    _inProgress
      ..clear()
      ..addAll(now);
    await _refreshAccess();
    _apply();
    _changed();
  }

  /// One stop as just loaded (stop detail, after a step).
  Future<void> noteStop(Assignment a) async {
    final changed = a.isInProgress ? _inProgress.add(a.number) : _inProgress.remove(a.number);
    if (!changed) return;
    await _refreshAccess();
    _apply();
    _changed();
  }

  Future<void> _refreshAccess({bool force = false}) async {
    if (_disposed || (!force && _accessChecked) || (!_onDuty && !onTrip)) return;
    _access = await source.access();
    _always = _access == LocationAccess.granted && await source.backgroundAllowed();
    _accessChecked = true;
  }

  /// The agent tapped "Allow location": system prompt, then start.
  Future<void> requestAccess() async {
    _access = await source.requestAccess();
    _accessChecked = true;
    _always = _access == LocationAccess.granted && await source.backgroundAllowed();
    _apply();
    _changed();
  }

  /// "Allow all the time" on the road (rationale shown by the banner).
  Future<void> requestBackground() async {
    _always = await source.requestBackground();
    _changed();
  }

  /// "Not now" on the "all the time" banner (tracking goes on while the app
  /// is in use and in the background with the notification).
  void dismissBackground() {
    _backgroundDismissed = true;
    _changed();
  }

  /// "Denied forever": the app's settings; GPS off: the location settings.
  Future<void> openSettings() => _access == LocationAccess.servicesOff ? source.openLocationSettings() : source.openSettings();

  /// Re-checks the permission (e.g. back from the settings app).
  Future<void> recheck() async {
    if (!_onDuty && !onTrip) return;
    await _refreshAccess(force: true);
    _apply();
    _changed();
  }

  /// Starts / stops the timer and the trip stream for the current state.
  void _apply() {
    if (_disposed) return;
    if (_restart != null) {
      // Waiting to restart the stream after an error.
      if (onTrip) return;
      _restart!.cancel();
      _restart = null;
    }
    final granted = _access == LocationAccess.granted && source.available;
    if (granted && onTrip) {
      _stopTimer();
      _startTrip();
    } else {
      _stopTrip();
      if (granted && _onDuty) {
        _startTimer();
      } else {
        _stopTimer();
      }
    }
  }

  void _startTimer() {
    if (_timer != null || _disposed) return;
    _timer = _scheduler(interval, (_) => pingOnce());
    pingOnce();
  }

  void _stopTimer() {
    _timer?.cancel();
    _timer = null;
  }

  void _startTrip() {
    if (_trip != null || _disposed) return;
    _trip = source.track(notice).listen(
      (fix) async {
        if (await uploader.offer(fix)) _changed();
      },
      onError: (Object _) async {
        // GPS switched off or the permission taken back: stop and show it
        // (the banner / resume re-check restarts). Any other stream error:
        // start again in 30 s rather than spinning.
        _stopTrip();
        _access = await source.access();
        if (_disposed) return;
        if (_access == LocationAccess.granted) {
          _restart?.cancel();
          _restart = Timer(const Duration(seconds: 30), () {
            _restart = null;
            _apply();
            _changed();
          });
        } else {
          _apply();
        }
        _changed();
      },
      onDone: () {
        _trip = null;
        _stopHeartbeat();
      },
    );
    _heartbeat = _scheduler(uploader.policy.minInterval, (_) => heartbeat());
  }

  /// The trip tick: retry a failed ping; heartbeat when idle.
  Future<bool> heartbeat() async {
    if (_trip == null) return false;
    final sentNow = await uploader.tick(fresh: () async {
      final f = await source.current();
      return f == null ? null : LocationFix(lat: f.lat, lng: f.lng, recordedAt: _clock());
    });
    if (sentNow) _changed();
    return sentNow;
  }

  void _stopHeartbeat() {
    _heartbeat?.cancel();
    _heartbeat = null;
  }

  void _stopTrip() {
    _trip?.cancel();
    _trip = null;
    _stopHeartbeat();
    uploader.reset();
  }

  /// One fix posted to the ping endpoint (on-duty timer). False when there
  /// was no fix or the API refused it (the next tick tries again).
  Future<bool> pingOnce() async {
    final fix = await source.current();
    if (fix == null || !_onDuty) return false;
    try {
      await api.post('delivery/me/location', body: {'lat': fix.lat, 'lng': fix.lng});
      _lastSentAt = _clock();
      _sent++;
      _changed();
      return true;
    } on ApiException {
      return false;
    }
  }

  @override
  void dispose() {
    _disposed = true;
    _restart?.cancel();
    _stopTimer();
    _stopTrip();
    super.dispose();
  }
}

/// Gives the screens the shell's [DutyTracker].
class DutyScope extends InheritedNotifier<DutyTracker> {
  const DutyScope({super.key, required DutyTracker tracker, required super.child}) : super(notifier: tracker);

  static DutyTracker? maybeOf(BuildContext context) => context.dependOnInheritedWidgetOfExactType<DutyScope>()?.notifier;

  /// Without subscribing (for callbacks).
  static DutyTracker? read(BuildContext context) => context.getInheritedWidgetOfExactType<DutyScope>()?.notifier;
}
