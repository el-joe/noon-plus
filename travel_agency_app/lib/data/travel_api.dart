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

  /// D14 paid placement: the agency's promotions (403 without the
  /// `promotions` permission group); new ones are bought in the app.
  Future<ApiResponse<List<Json>>> promotions() => api.getList('ops/travel/promotions');

  /// In-app purchase (D14): the agency's live trips and the promotion
  /// options on sale (`price` VAT included, `vat` part of it).
  Future<Json> promotionOptions() => api.getObject('ops/travel/promotions/options');

  /// Opens the promotion and its card payment → `{promotion, payment, result}`;
  /// the app opens `payment.redirect_url` in an in-app browser tab.
  Future<Json> buyPromotion({required int packageId, required int productId}) async =>
      asJson((await api.post('ops/travel/promotions', body: {'package_id': packageId, 'product_id': productId})).data);

  Future<Json> promotion(int id) => api.getObject('ops/travel/promotions/$id');

  /// Pay again for a promotion still waiting for payment (same shape as buy).
  Future<Json> payPromotion(int id) async => asJson((await api.post('ops/travel/promotions/$id/pay')).data);

  /// After the gateway return / app resume: the server asks the gateway and
  /// answers `result` (active, scheduled, pending, failed, paid_inactive, closed).
  Future<Json> verifyPromotion(int id, {String? payment}) async =>
      asJson((await api.post('ops/travel/promotions/$id/verify', body: {'payment': ?payment})).data);
}

/// The reference of a gateway return deep link
/// (`nawytravel://promotions/return?payment=…&status=…`, or the
/// `/return?payment=…` route Flutter hands over), else null.
String? promotionReturnReference(Uri uri) {
  final path = uri.path.endsWith('/') ? uri.path.substring(0, uri.path.length - 1) : uri.path;
  final isReturn = path.endsWith('/return') && (uri.scheme == 'nawytravel' || uri.host == 'promotions' || path.contains('promotions') || uri.scheme.isEmpty);
  final reference = uri.queryParameters['payment'];
  return isReturn && reference != null && reference.isNotEmpty ? reference : null;
}

/// Promotion statuses (backend D14): pending_payment, active, expired,
/// cancelled, refunded. Maps them onto [StatusChip] colours (the label comes
/// from the server's `status_label`).
String promotionChipStatus(String status) => status == 'pending_payment' ? 'pending' : status;

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

/// `adults` / `children` of a booking (wave 5a, QC-027), or null when the
/// API does not send the split (older answers carry `travellers` only).
({int adults, int children})? partyOf(Json booking) {
  final adults = booking.i('adults');
  if (adults == null) return null;
  return (adults: adults, children: booking.i('children') ?? 0);
}

/// Booking rooms (QA-037): `{room_type, travellers, unit_price: Money,
/// line_total: Money}`. Rows without a room type are skipped.
List<Json> roomsOf(Json booking) => booking.l('rooms').where((r) => (r.s('room_type') ?? '').isNotEmpty).toList();
