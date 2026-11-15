import 'package:customer_app/core/money.dart';
import 'package:customer_app/screens/catalog/product_screen.dart';
import 'package:customer_app/screens/travel/travel_screens.dart';
import 'package:customer_app/state/cart_store.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers.dart';

void main() {
  group('CartMath', () {
    test('counts units across shipments', () {
      final cart = cartFixture(qty: 3);
      (cart['shipments'] as List).add({
        'items': [
          {'listing_id': 9, 'quantity': 2, 'line_total': money('20.00'), 'unit_price': money('10.00'), 'regular_price': money('10.00')},
        ],
      });
      expect(CartMath.itemCount(cart), 5);
      expect(CartMath.itemCount(null), 0);
      expect(CartMath.lines(cart).length, 2);
    });

    test('subtotal sums line totals in money, not floats', () {
      final cart = cartFixture(qty: 3);
      expect(CartMath.subtotal(cart)!.amount, '747.00');
      expect(CartMath.subtotal(emptyCart), isNull);
    });

    test('savings against the regular price', () {
      expect(CartMath.savings(cartFixture(qty: 2))!.amount, '100.00');
    });

    test('line total and remaining spend for free delivery (3-decimal currency)', () {
      expect(CartMath.lineTotal(Money.parse('1.255', 'OMR'), 4).amount, '5.020');
      expect(CartMath.remainingFor(Money.parse('7.500', 'OMR'), Money.parse('10', 'OMR')).amount, '2.500');
      expect(CartMath.remainingFor(Money.parse('12', 'OMR'), Money.parse('10', 'OMR')).isZero, isTrue);
    });

    test('quantity stepper bounds', () {
      expect(CartMath.clampQuantity(0), 1);
      expect(CartMath.clampQuantity(12), 10);
      expect(CartMath.clampQuantity(5, inStock: 3), 3);
      expect(CartMath.clampQuantity(5, inStock: 0), 0);
    });
  });

  group('PDP offers', () {
    final product = {
      'buy_box': {'listing_id': 5, 'options': {'colour': 'Black'}, 'price': money('249.00'), 'in_stock': 10},
      'other_offers': [
        {'listing_id': 7, 'options': {'colour': 'White'}, 'price': money('259.00'), 'in_stock': 10},
        {'listing_id': 6, 'options': {'colour': 'Black'}, 'price': money('239.00'), 'in_stock': 0},
        {'listing_id': 8, 'options': {'colour': 'White'}, 'price': money('249.00'), 'in_stock': 4},
      ],
    };

    test('variant dimensions come from offer options', () {
      expect(variantOptions(product), {'colour': ['Black', 'White']});
    });

    test('picks the cheapest in-stock offer for the variant', () {
      expect(offerFor(product, {'colour': 'White'})!['listing_id'], 8);
      expect(offerFor(product, {'colour': 'Black'})!['listing_id'], 5); // 239 is out of stock
      expect(offerFor(product, {})!['listing_id'], 5);
      expect(offerFor(product, {'colour': 'Red'}), isNull);
    });
  });

  group('travel estimate (D8)', () {
    final departure = {
      'rooms': [
        {'type': 'double', 'price_per_person': money('2000.00')},
        {'type': 'single', 'price_per_person': money('2600.00')},
      ],
    };

    test('sums price per person × travellers', () {
      expect(travelEstimate(departure, {'double': 2, 'single': 1})!.amount, '6600.00');
      expect(travelEstimate(departure, {'double': 0}), isNull);
    });

    test('keeps 3 decimals for OMR', () {
      final omr = {
        'rooms': [
          {'type': 'triple', 'price_per_person': money('245.000', 'OMR')},
        ],
      };
      expect(travelEstimate(omr, {'triple': 3})!.format(), 'OMR 735.000');
    });
  });
}
