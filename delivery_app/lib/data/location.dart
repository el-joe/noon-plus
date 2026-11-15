import 'dart:async';

import 'package:nawy_core/nawy_core.dart';

/// GPS for the dispatch "nearest" auto-assign (owner review W6).
///
/// OWNER REVIEW (WP10, package request `geolocator`): the Flutter SDK has no
/// location API, so [NoLocationSource] is the default and nothing is sent.
/// With `geolocator` approved, implement [LocationSource] with it and pass it
/// to [LocationPinger]; `POST delivery/me/location {lat, lng}` is ready.
abstract class LocationSource {
  bool get available;
  Future<({double lat, double lng})?> current();
}

class NoLocationSource implements LocationSource {
  const NoLocationSource();

  @override
  bool get available => false;

  @override
  Future<({double lat, double lng})?> current() async => null;
}

/// Sends a ping every [interval] while the agent is available.
class LocationPinger {
  LocationPinger(this.api, this.source, {this.interval = const Duration(minutes: 2)});

  final ApiClient api;
  final LocationSource source;
  final Duration interval;
  Timer? _timer;
  DateTime? lastSentAt;

  bool get running => _timer != null;

  Future<bool> pingOnce() async {
    final fix = await source.current();
    if (fix == null) return false;
    try {
      await api.post('delivery/me/location', body: {'lat': fix.lat, 'lng': fix.lng});
      lastSentAt = DateTime.now();
      return true;
    } on ApiException {
      return false;
    }
  }

  void start() {
    if (!source.available || _timer != null) return;
    pingOnce();
    _timer = Timer.periodic(interval, (_) => pingOnce());
  }

  void stop() {
    _timer?.cancel();
    _timer = null;
  }
}
