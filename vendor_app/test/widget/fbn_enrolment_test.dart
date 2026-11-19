import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nawy_core/nawy_core.dart';
import 'package:nawy_core/testing.dart';
import 'package:vendor_app/screens/fbn.dart';

import '../helpers.dart';

Map<String, dynamic> listingRow(int id, {String fulfilment = 'fbm', int units = 0}) => {
      'listing_id': id,
      'sku': 'SKU-$id',
      'title': 'Product $id',
      'status': 'active',
      'fulfilment': fulfilment,
      'warehouse_units': units,
      'can_enrol': fulfilment != 'fbn',
      'can_leave': fulfilment == 'fbn' && units == 0,
    };

Map<String, dynamic> planJson({String? labelsPath}) => {
      'id': 9,
      'number': 'FBN-IN-AE-000009',
      'reference': 'FBN-IN-AE-000009',
      'status': 'submitted',
      'warehouse': {'id': 1, 'code': 'DXB-1', 'name': 'Dubai FC'},
      'units': 4,
      'cartons': 2,
      'can_submit': false,
      'can_ship': true,
      'can_cancel': true,
      'labels_path': labelsPath,
      'lines': <dynamic>[],
    };

Future<void> openEnrolment(WidgetTester tester, FakeTransport transport) async {
  await pumpScreen(tester, const FbnScreen(), transport);
  await tester.tap(find.byKey(const Key('tab-fbn-enrol')));
  await tester.pumpAndSettle();
}

void main() {
  testWidgets('enrols a listing after confirming and reloads', (tester) async {
    var enrolled = false;
    final transport = FakeTransport({
      'GET ops/vendor/fbn/listings': (HttpRequestData _) => FakeTransport.ok([listingRow(41, fulfilment: enrolled ? 'fbn' : 'fbm')], meta: {'has_warehouses': true}),
      'POST ops/vendor/fbn/listings/41/enrol': (HttpRequestData _) {
        enrolled = true;
        return FakeTransport.ok(listingRow(41, fulfilment: 'fbn'));
      },
    });
    await openEnrolment(tester, transport);
    expect(find.text('Product 41'), findsOneWidget);
    expect(find.text('By you'), findsOneWidget);

    await tester.tap(find.byKey(const Key('fbn-enrol-41')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('fbn-change-confirm')));
    await tester.pumpAndSettle();

    expect(transport.sent('POST ops/vendor/fbn/listings/41/enrol'), hasLength(1));
    expect(find.text('SKU-41 is now fulfilled by Nawy.'), findsOneWidget);
    expect(find.byKey(const Key('fbn-leave-41')), findsOneWidget);
    expect(find.text('0 units in warehouse'), findsOneWidget);
  });

  testWidgets('leaving with warehouse stock shows the server reason', (tester) async {
    final transport = FakeTransport({
      'GET ops/vendor/fbn/listings': FakeTransport.ok([listingRow(42, fulfilment: 'fbn', units: 3)], meta: {'has_warehouses': true}),
      'POST ops/vendor/fbn/listings/42/leave': FakeTransport.error(422, 'transition_refused', 'Remove the warehouse stock first.'),
    });
    await openEnrolment(tester, transport);
    expect(find.text('3 units in warehouse'), findsOneWidget);

    await tester.tap(find.byKey(const Key('fbn-leave-42')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('fbn-change-confirm')));
    await tester.pumpAndSettle();
    expect(find.text('Remove the warehouse stock first.'), findsOneWidget);
  });

  testWidgets('filters FBN / fulfilled by you and hints when no warehouse is open', (tester) async {
    final transport = FakeTransport({
      'GET ops/vendor/fbn/listings': (HttpRequestData r) {
        final f = r.uri.queryParameters['fulfilment'];
        final rows = [listingRow(1), listingRow(2, fulfilment: 'fbn')].where((row) => f == null || row['fulfilment'] == f).toList();
        return FakeTransport.ok(rows, meta: {'has_warehouses': false});
      },
    });
    await openEnrolment(tester, transport);
    expect(find.byKey(const Key('fbn-no-warehouse')), findsOneWidget);
    expect(find.text('Product 1'), findsOneWidget);
    expect(find.text('Product 2'), findsOneWidget);

    await tester.tap(find.byKey(const Key('fbn-filter-fbn')));
    await tester.pumpAndSettle();
    expect(find.text('Product 1'), findsNothing);
    expect(find.text('Product 2'), findsOneWidget);
    expect(transport.sent('GET ops/vendor/fbn/listings').last.uri.queryParameters['fulfilment'], 'fbn');

    await tester.tap(find.byKey(const Key('fbn-filter-fbm')));
    await tester.pumpAndSettle();
    expect(find.text('Product 1'), findsOneWidget);
    expect(find.text('Product 2'), findsNothing);
  });

  testWidgets('downloads the carton labels PDF of a submitted plan', (tester) async {
    final saved = <String, List<int>>{};
    PlatformBridge.debugSaveDocument = (name, bytes) async {
      saved[name] = bytes;
      return '/docs/$name';
    };
    addTearDown(() => PlatformBridge.debugSaveDocument = null);
    final transport = FakeTransport({
      'GET ops/vendor/fbn/inbound/9': planJson(labelsPath: 'ops/vendor/fbn/inbound/9/labels.pdf'),
      'GET ops/vendor/fbn/inbound/9/labels.pdf': (HttpRequestData _) =>
          HttpResponseData(200, '', {'content-type': 'application/pdf', 'content-disposition': 'attachment; filename="labels-FBN-IN-AE-000009.pdf"'}, [0x25, 0x50, 0x44, 0x46]),
    });
    await pumpScreen(tester, const InboundDetailScreen(id: 9), transport);
    expect(find.textContaining('Seller Center'), findsNothing);

    await tester.tap(find.byKey(const Key('inbound-labels')));
    await tester.pumpAndSettle();
    expect(saved.keys, ['labels-FBN-IN-AE-000009.pdf']);
    expect(saved.values.single, [0x25, 0x50, 0x44, 0x46]);
    expect(find.text('Saved to /docs/labels-FBN-IN-AE-000009.pdf'), findsOneWidget);
  });

  testWidgets('no labels button before the plan is submitted', (tester) async {
    await pumpScreen(tester, const InboundDetailScreen(id: 9), FakeTransport({'GET ops/vendor/fbn/inbound/9': planJson()}));
    expect(find.byKey(const Key('inbound-labels')), findsNothing);
  });
}
