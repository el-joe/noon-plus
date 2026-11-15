import 'package:flutter/foundation.dart';

import '../core/api/api_client.dart';
import '../core/json.dart';
import '../core/money.dart';

/// Pure cart maths on the API cart (`CartPresenter`). The server is the source
/// of truth for prices; the app only sums what it shows (badges, previews).
class CartMath {
  const CartMath._();

  static List<Json> lines(Json? cart) => [for (final shipment in cart?.l('shipments') ?? const <Json>[]) ...shipment.l('items')];

  /// Number of units across every shipment (tab badge).
  static int itemCount(Json? cart) => lines(cart).fold(0, (sum, line) => sum + (line.i('quantity') ?? 0));

  /// unit price × quantity (preview while a quantity change is in flight).
  static Money lineTotal(Money unitPrice, int quantity) => unitPrice.times(quantity);

  /// Sum of line totals, in the cart currency.
  static Money? subtotal(Json? cart) {
    Money? sum;
    for (final line in lines(cart)) {
      final total = Money.fromJson(line['line_total']);
      if (total == null) continue;
      sum = sum == null ? total : sum + total;
    }
    return sum;
  }

  /// Savings against the regular price: Σ (regular − unit) × qty, never negative.
  static Money? savings(Json? cart) {
    Money? sum;
    for (final line in lines(cart)) {
      final unit = Money.fromJson(line['unit_price']);
      final regular = Money.fromJson(line['regular_price']);
      if (unit == null || regular == null || regular.currency != unit.currency) continue;
      final diff = regular - unit;
      if (diff.isNegative) continue;
      final saved = diff.times(line.i('quantity') ?? 0);
      sum = sum == null ? saved : sum + saved;
    }
    return sum;
  }

  /// Remaining spend to reach [threshold] (zero once reached).
  static Money remainingFor(Money subtotal, Money threshold) {
    final rest = threshold - subtotal;
    return rest.isNegative ? Money.zero(subtotal.currency) : rest;
  }

  /// Quantity bounds for the stepper (1..min(stock, 10)).
  static int clampQuantity(int wanted, {int? inStock, int max = 10}) {
    final cap = inStock == null ? max : (inStock < max ? inStock : max);
    if (cap < 1) return 0;
    return wanted < 1 ? 1 : (wanted > cap ? cap : wanted);
  }
}

/// The current cart (guest via `X-Cart-Token`, or the signed-in customer's).
class CartStore extends ChangeNotifier {
  CartStore({required this.api, this.onChanged});

  final ApiClient api;
  final VoidCallback? onChanged;

  Json? cart;
  bool loading = false;
  ApiException? error;

  int get count => CartMath.itemCount(cart);
  bool get isEmpty => CartMath.lines(cart).isEmpty;

  void clear() {
    cart = null;
    _changed();
  }

  void _changed() {
    notifyListeners();
    onChanged?.call();
  }

  Future<Json?> _run(Future<ApiResponse<dynamic>> Function() call) async {
    loading = true;
    error = null;
    notifyListeners();
    try {
      final res = await call();
      cart = asJson(res.data);
      return cart;
    } on ApiException catch (e) {
      error = e;
      rethrow;
    } finally {
      loading = false;
      _changed();
    }
  }

  Future<Json?> refresh({String? paymentMethod}) => _run(() => api.get('cart', query: {'payment_method': paymentMethod}));

  Future<Json?> setQuantity(int listingId, int quantity) =>
      _run(() => quantity <= 0 ? api.delete('cart/items/$listingId') : api.put('cart/items', body: {'listing_id': listingId, 'quantity': quantity}));

  Future<Json?> add(int listingId, {int quantity = 1}) {
    final existing = CartMath.lines(cart).where((l) => l.i('listing_id') == listingId).fold<int>(0, (s, l) => s + (l.i('quantity') ?? 0));
    return setQuantity(listingId, existing + quantity);
  }

  Future<Json?> remove(int listingId) => _run(() => api.delete('cart/items/$listingId'));

  Future<Json?> setWarranty(int listingId, int? planId) => _run(() => api.put('cart/items/$listingId/warranty', body: {'warranty_plan_id': planId}));

  Future<Json?> applyCoupon(String code) => _run(() => api.post('cart/coupons', body: {'code': code}));

  Future<Json?> removeCoupon(String code) => _run(() => api.delete('cart/coupons/${enc(code)}'));

  Future<Json?> setShipping({String? city, String? area, String? method}) =>
      _run(() => api.put('cart/shipping', body: {'city': city, 'area': area, 'method': method}..removeWhere((_, v) => v == null)));
}
