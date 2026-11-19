import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nawy_core/testing.dart';
import 'package:vendor_app/data/vendor_api.dart';
import 'package:vendor_app/screens/fbn.dart';
import 'package:vendor_app/screens/stock.dart';

import '../helpers.dart';

Map<String, dynamic> fbnStockJson({int id = 41, int available = 3}) => {
      'listing_id': id,
      'sku': 'NX12-FBN',
      'title': 'Nova X12',
      'on_hand': available + 1,
      'reserved': 1,
      'available': available,
      'damaged': 2,
      'low_stock': available <= 5,
      'long_term': false,
      'unsellable': true,
      'warehouses': [
        {'warehouse_id': 1, 'code': 'DXB-1', 'name': 'Dubai FC', 'bin': 'A-01', 'on_hand': available + 1, 'reserved': 1, 'available': available, 'damaged': 2},
      ],
    };

Map<String, dynamic> inboundJson({int id = 9, String status = 'draft'}) => {
      'id': id,
      'number': status == 'draft' ? null : 'FBN-IN-AE-000009',
      'reference': status == 'draft' ? 'Draft #9' : 'FBN-IN-AE-000009',
      'status': status,
      'warehouse': {'id': 1, 'code': 'DXB-1', 'name': 'Dubai FC'},
      'lines_count': 1,
      'units': 12,
      'cartons': 2,
      'can_submit': status == 'draft',
      'can_ship': status == 'submitted',
      'can_cancel': ['draft', 'submitted', 'in_transit'].contains(status),
      'lines': [
        {'id': 1, 'listing_id': 41, 'sku': 'NX12-FBN', 'title': 'Nova X12', 'quantity_expected': 12, 'cartons': 2, 'quantity_received': 0, 'quantity_damaged': 0, 'quantity_short': null},
      ],
    };

void main() {
  testWidgets('FBN stock shows availability per warehouse and alerts', (tester) async {
    final transport = FakeTransport({'GET ops/vendor/fbn/stock': [fbnStockJson()]});
    await pumpScreen(tester, const FbnScreen(), transport);

    expect(find.text('Nova X12'), findsOneWidget);
    expect(find.text('DXB-1: 3 available of 4'), findsOneWidget);
    expect(find.text('Unsellable'), findsOneWidget);
    expect(find.text('Low'), findsOneWidget);
  });

  testWidgets('inbound plans list and a new plan is created and submitted', (tester) async {
    final transport = FakeTransport({
      'GET ops/vendor/fbn/inbound': [inboundJson(status: 'submitted')],
      'GET ops/vendor/fbn/warehouses': [
        {'id': 1, 'code': 'DXB-1', 'name': 'Dubai FC'},
      ],
      'GET ops/vendor/fbn/stock': [fbnStockJson()],
      'POST ops/vendor/fbn/inbound': FakeTransport.ok(inboundJson(id: 10, status: 'submitted'), status: 201),
    });
    await pumpScreen(tester, const FbnScreen(), transport);
    await tester.tap(find.byKey(const Key('tab-fbn-inbound')));
    await tester.pumpAndSettle();
    expect(find.text('FBN-IN-AE-000009'), findsOneWidget);
    expect(find.text('Submitted'), findsOneWidget);

    await tester.tap(find.byKey(const Key('new-inbound')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('submit-inbound')));
    await tester.pumpAndSettle();
    expect(find.text('Enter the units of at least one product.'), findsOneWidget);

    await tester.enterText(find.byKey(const Key('inbound-units-41')), '12');
    await tester.tap(find.byKey(const Key('submit-inbound')));
    await tester.pumpAndSettle();

    final body = FakeTransport.bodyOf(transport.sent('POST ops/vendor/fbn/inbound').single);
    expect(body['warehouse_id'], 1);
    expect(body['submit'], true);
    expect(body['lines'], [
      {'listing_id': 41, 'quantity': 12},
    ]);
  });

  testWidgets('plan detail ships a submitted plan with the carrier', (tester) async {
    final transport = FakeTransport({
      'GET ops/vendor/fbn/inbound/9': inboundJson(status: 'submitted'),
      'POST ops/vendor/fbn/inbound/9/ship': inboundJson(status: 'in_transit'),
    });
    await pumpScreen(tester, const InboundDetailScreen(id: 9), transport);
    expect(find.text('12 expected · 0 received · 0 damaged'), findsOneWidget);

    await tester.tap(find.byKey(const Key('inbound-ship')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('ship-carrier')), 'Aramex');
    await tester.tap(find.byKey(const Key('ship-confirm')));
    await tester.pumpAndSettle();

    expect(FakeTransport.bodyOf(transport.sent('POST ops/vendor/fbn/inbound/9/ship').single), {'carrier_name': 'Aramex'});
  });

  testWidgets('FBN listings on the stock tab cannot be adjusted by hand', (tester) async {
    final transport = FakeTransport({
      'GET ops/vendor/stock': [
        {...stockJson(id: 41, stock: 3), 'fulfilment': 'fbn'},
      ],
    });
    await pumpScreen(tester, const StockScreen(), transport);
    expect(find.text('NX12-BLK · FBN'), findsOneWidget);
    await tester.tap(find.byKey(const Key('stock-41')));
    await tester.pumpAndSettle();
    expect(find.byType(AdjustStockSheet), findsNothing);
    expect(find.text('FBN stock is counted by the Nawy warehouse. Send units with an inbound plan.'), findsOneWidget);
  });

  test('inbound draft validation', () {
    expect(InboundDraft(warehouseId: null, units: {1: 2}).validate(), 'fbn.errWarehouse');
    expect(InboundDraft(warehouseId: 1, units: {1: 0}).validate(), 'fbn.errLines');
    expect(InboundDraft(warehouseId: 1, units: {1: 200000}).validate(), 'stock.errTooMany');
    expect(InboundDraft(warehouseId: 1, units: {1: 5, 2: 0}).json['lines'], [
      {'listing_id': 1, 'quantity': 5},
    ]);
  });
}
