import 'package:flutter_test/flutter_test.dart';
import 'package:vendor_app/data/vendor_api.dart';
import 'package:vendor_app/l10n/strings.dart';

import '../helpers.dart';

void main() {
  group('stock adjustment', () {
    test('adding is positive, removing negative', () {
      expect(StockAdjustment.from(raw: ' 12 ', remove: false, reason: 'restock').json, {'quantity': 12, 'reason': 'restock'});
      final remove = StockAdjustment.from(raw: '2', remove: true, reason: 'damaged');
      expect(remove.json, {'quantity': -2, 'reason': 'damaged'});
      expect(remove.applyTo(3), 1);
    });

    test('validation', () {
      expect(StockAdjustment.validate(raw: '0', remove: false, reason: 'restock', current: 3), 'stock.errQuantity');
      expect(StockAdjustment.validate(raw: '-4', remove: false, reason: 'restock', current: 3), 'stock.errQuantity');
      expect(StockAdjustment.validate(raw: '1.5', remove: false, reason: 'restock', current: 3), 'stock.errQuantity');
      expect(StockAdjustment.validate(raw: '100001', remove: false, reason: 'restock', current: 3), 'stock.errTooMany');
      expect(StockAdjustment.validate(raw: '1', remove: false, reason: 'gift', current: 3), 'stock.errReason');
      expect(StockAdjustment.validate(raw: '4', remove: true, reason: 'lost', current: 3), 'stock.errInsufficient');
      expect(StockAdjustment.validate(raw: '3', remove: true, reason: 'lost', current: 3), isNull);
    });

    test('low stock is 1 to the threshold', () {
      expect(isLowStock(stockJson(stock: 0)), isFalse);
      expect(isLowStock(stockJson(stock: 1)), isTrue);
      expect(isLowStock(stockJson(stock: 5)), isTrue);
      expect(isLowStock(stockJson(stock: 6)), isFalse);
    });
  });

  test('order filters are the to-do statuses plus all', () {
    expect(orderFilters, [null, 'placed', 'confirmed', 'processing', 'packed']);
  });

  test('EN and AR tables have the same keys', () {
    expect(vendorAr.keys.toSet(), vendorEn.keys.toSet());
  });
}
