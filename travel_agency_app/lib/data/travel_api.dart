import 'package:nawy_core/nawy_core.dart';

/// `v1/{market}/ops/travel/…` (Sanctum, ability `ops:travel_agency`): the
/// agency's own booking requests and departures. Decision D8: bookings are
/// requests the agency confirms or rejects — nothing is paid on Nawy.
class TravelApi {
  TravelApi(this.api);

  final ApiClient api;

  static const role = 'travel_agency';
  static const logoutPath = 'ops/auth/logout';

  Future<Json> login(String email, String password) async =>
      asJson((await api.post('ops/auth/login', body: {'email': email, 'password': password, 'device_name': AppConfig.deviceName, 'role': role})).data);

  Future<Json> dashboard() => api.getObject('ops/travel/dashboard');

  Future<ApiResponse<List<Json>>> bookings({String? status, int page = 1}) => api.getList('ops/travel/bookings', query: {'status': status, 'page': page});
  Future<Json> confirm(int id) async => asJson((await api.post('ops/travel/bookings/$id/confirm')).data);
  Future<Json> reject(int id, String reason) async => asJson((await api.post('ops/travel/bookings/$id/reject', body: {'reason': reason})).data);

  Future<ApiResponse<List<Json>>> departures({int page = 1}) => api.getList('ops/travel/departures', query: {'page': page});
  Future<Json> setCapacity(int id, int capacity) async => asJson((await api.post('ops/travel/departures/$id/capacity', body: {'capacity': capacity})).data);
}

/// Booking statuses the agency filters by (backend `BookingStatus`).
const bookingFilters = ['requested', 'confirmed', 'rejected', 'cancelled', 'completed'];

/// Seats still free (never negative).
int seatsLeft(int capacity, int booked) => capacity - booked < 0 ? 0 : capacity - booked;

/// 0..1 share of the departure that is booked.
double bookedShare(int capacity, int booked) => capacity <= 0 ? (booked > 0 ? 1 : 0) : (booked >= capacity ? 1 : booked / capacity);

/// Validates a new seat capacity: whole number 0–1000, not below the seats
/// already held or booked. Returns an l10n error key, or null when valid.
String? capacityError(String input, int booked) {
  final value = int.tryParse(input.trim());
  if (value == null) return 'capacity.notNumber';
  if (value < 0 || value > 1000) return 'capacity.range';
  if (value < booked) return 'capacity.belowBooked';
  return null;
}
