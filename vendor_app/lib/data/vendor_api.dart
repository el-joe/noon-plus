import 'package:nawy_core/nawy_core.dart';

/// `v1/{market}/ops/vendor/…` (Sanctum, ability `ops:vendor`).
class VendorApi {
  VendorApi(this.api);

  final ApiClient api;

  static const logoutPath = 'ops/auth/logout';

  Future<Json> login(String email, String password) async =>
      asJson((await api.post('ops/auth/login', body: {'email': email, 'password': password, 'device_name': AppConfig.deviceName, 'role': 'vendor'})).data);

  Future<Json> dashboard() => api.getObject('ops/vendor/dashboard');

  Future<ApiResponse<List<Json>>> orders({String? status, String? q, int page = 1}) =>
      api.getList('ops/vendor/orders', query: {'status': status, 'q': q, 'page': page});

  Future<Json> order(int id) => api.getObject('ops/vendor/orders/$id');

  Future<Json> advance(int id, String to) async => asJson((await api.post('ops/vendor/orders/$id/advance', body: {'to': to})).data);

  Future<Json> cancel(int id, String reason) async => asJson((await api.post('ops/vendor/orders/$id/cancel', body: {'reason': reason})).data);

  Future<ApiResponse<List<Json>>> stock({String? q, bool low = false, int page = 1}) =>
      api.getList('ops/vendor/stock', query: {'q': q, 'low': low ? true : null, 'page': page});

  Future<Json> adjustStock(int listingId, StockAdjustment adjustment) async =>
      asJson((await api.post('ops/vendor/stock/$listingId', body: adjustment.json)).data);
}

/// Order status filters shown as chips (null = all).
const orderFilters = <String?>[null, 'placed', 'confirmed', 'processing', 'packed'];

/// Stock reasons (backend `nawy.vendors.stock_reasons`).
const stockReasons = ['restock', 'correction', 'damaged', 'lost'];

/// A stock move: positive adds units, negative removes (Seller Center rule).
class StockAdjustment {
  const StockAdjustment(this.quantity, this.reason);

  final int quantity;
  final String reason;

  Json get json => {'quantity': quantity, 'reason': reason};

  /// Null when valid, otherwise an l10n key. [current] is the units in stock.
  static String? validate({required String raw, required bool remove, required String? reason, required int current}) {
    final value = int.tryParse(raw.trim());
    if (value == null || value <= 0) return 'stock.errQuantity';
    if (value > 100000) return 'stock.errTooMany';
    if (reason == null || !stockReasons.contains(reason)) return 'stock.errReason';
    if (remove && value > current) return 'stock.errInsufficient';
    return null;
  }

  static StockAdjustment from({required String raw, required bool remove, required String reason}) {
    final value = int.parse(raw.trim());
    return StockAdjustment(remove ? -value : value, reason);
  }

  /// Units after the move.
  int applyTo(int current) => current + quantity;
}

/// Low stock = 1 to threshold units (backend `LOW_STOCK_THRESHOLD`).
bool isLowStock(Json row) {
  final stock = row.i('stock') ?? 0;
  final threshold = row.i('low_stock_threshold') ?? 5;
  return stock >= 1 && stock <= threshold;
}
