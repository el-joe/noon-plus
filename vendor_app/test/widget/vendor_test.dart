import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nawy_core/nawy_core.dart';
import 'package:nawy_core/testing.dart';
import 'package:vendor_app/app.dart';
import 'package:vendor_app/screens/dashboard.dart';
import 'package:vendor_app/screens/orders.dart';
import 'package:vendor_app/screens/stock.dart';

import '../helpers.dart';

final dashboardJson = {
  'store': {'id': 1, 'name': 'SoundMax'},
  'kpis': {'to_confirm': 4, 'to_pack': 2, 'ready_to_ship': 1, 'orders_today': 6, 'sales_today': moneyJson('1249.50'), 'sales_7d': moneyJson('8020.00'), 'low_stock': 3},
};

void main() {
  testWidgets('sign in sends role vendor and opens the dashboard', (tester) async {
    final transport = FakeTransport({
      'POST ops/auth/login': {'token': 'tok', 'user': {'id': 1, 'name': 'Sara'}, 'workspace': {'role': 'vendor', 'id': 1, 'name': 'SoundMax'}},
      'GET ops/vendor/dashboard': dashboardJson,
    });
    final session = await testSession(transport, signedIn: false);
    await tester.pumpWidget(VendorApp(session: session));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('email')), 'sara@nawy.test');
    await tester.enterText(find.byKey(const Key('password')), 'secret');
    await tester.tap(find.byKey(const Key('sign-in')));
    await tester.pumpAndSettle();
    final body = FakeTransport.bodyOf(transport.sent('POST ops/auth/login').single);
    expect(body['role'], 'vendor');
    expect(body['device_name'], isNotEmpty);
    expect(session.profile?['workspace'], {'role': 'vendor', 'id': 1, 'name': 'SoundMax'});
    expect(find.byType(DashboardScreen), findsOneWidget);
  });

  testWidgets('no workspace error is shown on sign in', (tester) async {
    final transport = FakeTransport({'POST ops/auth/login': FakeTransport.error(403, 'no_workspace', 'This account has no vendor workspace in this country.')});
    final session = await testSession(transport, signedIn: false);
    await tester.pumpWidget(VendorApp(session: session));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('email')), 'x@nawy.test');
    await tester.enterText(find.byKey(const Key('password')), 'secret');
    await tester.tap(find.byKey(const Key('sign-in')));
    await tester.pumpAndSettle();
    expect(find.text('This account has no vendor workspace in this country.'), findsOneWidget);
    expect(session.signedIn, isFalse);
  });

  testWidgets('dashboard shows KPIs with money; actions open filtered orders', (tester) async {
    final transport = FakeTransport({'GET ops/vendor/dashboard': dashboardJson});
    String? opened = 'none';
    var lowStock = false;
    await pumpScreen(tester, DashboardScreen(onOrders: (s) => opened = s, onLowStock: () => lowStock = true), transport);
    expect(find.text('SoundMax'), findsOneWidget);
    expect(find.text('AED 1,249.50'), findsOneWidget);
    expect(find.text('AED 8,020.00'), findsOneWidget);
    expect(find.text('Confirm 4 new orders'), findsOneWidget);
    await tester.tap(find.byKey(const Key('action-confirm')));
    expect(opened, 'placed');
    await tester.tap(find.byKey(const Key('action-low-stock')));
    expect(lowStock, isTrue);
  });

  testWidgets('orders filter by status chip', (tester) async {
    final transport = FakeTransport({'GET ops/vendor/orders': [orderJson()]});
    await pumpScreen(tester, const OrdersScreen(), transport);
    expect(find.text('NW-AE-1000007-1'), findsOneWidget);
    await tester.ensureVisible(find.byKey(const Key('filter-packed')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('filter-packed')));
    await tester.pumpAndSettle();
    expect(transport.requests.map((r) => r.uri.queryParameters['status']), contains('packed'));
  });

  testWidgets('order detail advances to the next step', (tester) async {
    final transport = FakeTransport({
      'GET ops/vendor/orders/7': orderJson(),
      'POST ops/vendor/orders/7/advance': orderJson(status: 'confirmed', next: 'processing'),
    });
    await pumpScreen(tester, const OrderDetailScreen(id: 7), transport);
    expect(find.text('Confirm order'), findsOneWidget);
    await tester.tap(find.byKey(const Key('advance')));
    await tester.pumpAndSettle();
    expect(FakeTransport.bodyOf(transport.sent('POST ops/vendor/orders/7/advance').single), {'to': 'confirmed'});
  });

  testWidgets('cancel needs a reason of at least 5 characters', (tester) async {
    final transport = FakeTransport({
      'GET ops/vendor/orders/7': orderJson(),
      'POST ops/vendor/orders/7/cancel': orderJson(status: 'cancelled', next: null, canCancel: false),
    });
    await pumpScreen(tester, const OrderDetailScreen(id: 7), transport);
    await tester.tap(find.byKey(const Key('cancel-order')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('reason-field')), 'abc');
    await tester.pump();
    final submit = find.widgetWithText(FilledButton, 'Submit');
    expect(tester.widget<FilledButton>(submit).onPressed, isNull);
    await tester.enterText(find.byKey(const Key('reason-field')), 'Out of stock');
    await tester.pump();
    await tester.tap(submit);
    await tester.pumpAndSettle();
    expect(FakeTransport.bodyOf(transport.sent('POST ops/vendor/orders/7/cancel').single), {'reason': 'Out of stock'});
  });

  testWidgets('a refused transition shows the message', (tester) async {
    final transport = FakeTransport({
      'GET ops/vendor/orders/7': orderJson(),
      'POST ops/vendor/orders/7/advance': FakeTransport.error(422, 'transition_refused', 'This order was already confirmed.'),
    });
    await pumpScreen(tester, const OrderDetailScreen(id: 7), transport);
    await tester.tap(find.byKey(const Key('advance')));
    await tester.pumpAndSettle();
    expect(find.text('This order was already confirmed.'), findsOneWidget);
  });

  testWidgets('stock adjust sends a negative quantity and reason; shows insufficient stock', (tester) async {
    var calls = 0;
    final transport = FakeTransport({
      'GET ops/vendor/stock': [stockJson()],
      'POST ops/vendor/stock/31': (HttpRequestData r) {
        calls++;
        return calls == 1
            ? FakeTransport.error(422, 'insufficient_stock', 'Only 1 units are in stock.', {'quantity': ['Only 1 units are in stock.']})
            : FakeTransport.ok(stockJson(stock: 1));
      },
    });
    await pumpScreen(tester, const StockScreen(), transport);
    expect(find.text('Low'), findsOneWidget);
    await tester.tap(find.byKey(const Key('stock-31')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Remove units'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('quantity')), '2');
    await tester.tap(find.byKey(const Key('save-stock')));
    await tester.pumpAndSettle();
    expect(FakeTransport.bodyOf(transport.sent('POST ops/vendor/stock/31').first), {'quantity': -2, 'reason': 'damaged'});
    expect(find.byKey(const Key('stock-error')), findsOneWidget);
    expect(find.text('Only 1 units are in stock.'), findsOneWidget);
    await tester.tap(find.byKey(const Key('save-stock')));
    await tester.pumpAndSettle();
    expect(find.byType(AdjustStockSheet), findsNothing);
  });

  testWidgets('removing more than in stock is caught before sending', (tester) async {
    final transport = FakeTransport({'GET ops/vendor/stock': [stockJson(stock: 3)]});
    await pumpScreen(tester, const StockScreen(), transport);
    await tester.tap(find.byKey(const Key('stock-31')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Remove units'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('quantity')), '9');
    await tester.tap(find.byKey(const Key('save-stock')));
    await tester.pumpAndSettle();
    expect(find.text('Only 3 units are in stock.'), findsOneWidget);
    expect(transport.sent('POST ops/vendor/stock/31'), isEmpty);
  });

  testWidgets('Arabic is right to left', (tester) async {
    final transport = FakeTransport({'GET ops/vendor/dashboard': dashboardJson});
    await pumpScreen(tester, DashboardScreen(onOrders: (_) {}, onLowStock: () {}), transport, locale: 'ar');
    expect(find.text('مبيعات اليوم'), findsOneWidget);
    expect(find.text('1,249.50 AED'), findsOneWidget);
    expect(Directionality.of(tester.element(find.text('مبيعات اليوم'))), TextDirection.rtl);
  });
}
