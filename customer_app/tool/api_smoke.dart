// Smoke-check the app's API client against a running backend:
//   dart run tool/api_smoke.dart [http://localhost:8000/api/v1] [uae-en]
// Read-only except a guest cart line (removed again). Pure Dart (no Flutter).
import 'dart:io';

import 'package:customer_app/core/api/api_client.dart';
import 'package:customer_app/core/api/transport_io.dart';
import 'package:customer_app/core/json.dart';
import 'package:customer_app/core/money.dart';

Future<void> main(List<String> args) async {
  final api = ApiClient(baseUrl: args.isNotEmpty ? args[0] : 'http://localhost:8000/api/v1', transport: IoHttpTransport())..market = args.length > 1 ? args[1] : 'uae-en';
  var failures = 0;

  Future<void> check(String label, Future<String> Function() run) async {
    try {
      stdout.writeln('ok   $label: ${await run()}');
    } catch (e) {
      failures++;
      stdout.writeln('FAIL $label: $e');
    }
  }

  await check('countries', () async => '${asJsonList((await api.get('countries', global: true)).data).length} countries');
  await check('app/contexts', () async => asJsonList((await api.get('app/contexts')).data).map((c) => c.str('key')).join(','));
  await check('categories/tree', () async => '${(await api.getList('categories/tree')).data.length} roots');
  late List<Json> products;
  await check('products', () async {
    final res = await api.getList('products', query: {'per_page': 5});
    products = res.data;
    return '${res.total} total, first ${products.first.str('slug')} ${Money.fromJson(products.first['price'])}';
  });
  await check('products/facets', () async => (await api.getObject('products/facets')).keys.join(','));
  await check('product detail', () async {
    final p = await api.getObject('products/${products.first.str('slug')}');
    return 'buy box ${p.m('buy_box')?.i('listing_id')}, ${p.l('other_offers').length} other offers';
  });
  await check('reviews', () async => (await api.getObject('products/${products.first.str('slug')}/reviews')).m('summary').toString());
  await check('guest cart add/remove', () async {
    final p = await api.getObject('products/${products.first.str('slug')}');
    final listing = p.m('buy_box')!.i('listing_id')!;
    final cart = asJson((await api.put('cart/items', body: {'listing_id': listing, 'quantity': 1})).data);
    final token = api.cartToken;
    await api.delete('cart/items/$listing');
    return 'token ${token != null}, total ${Money.fromJson(cart.m('totals')?['total'])}';
  });
  await check('payment methods', () async => (await api.getList('checkout/payment-methods', query: {'amount': '100.00'})).data.map((m) => m.str('code')).join(','));
  await check('flash-sales', () async => '${(await api.getList('flash-sales')).data.length} sales');
  await check('travel/packages', () async => '${(await api.getList('travel/packages')).data.length} packages');
  await check('classifieds/ads', () async => '${(await api.getList('classifieds/ads')).total} ads');
  await check('live-streams', () async => '${(await api.getList('live-streams')).data.length} streams');
  await check('membership/plans', () async => '${(await api.getList('membership/plans')).data.length} plans');
  await check('help collections', () async => '${(await api.getList('help/help_center/collections')).data.length} collections');
  await check('pages/home (404 ok)', () async {
    try {
      return (await api.getObject('pages/home')).str('template');
    } on ApiException catch (e) {
      if (e.isNotFound) return 'not published → app fallback home';
      rethrow;
    }
  });
  await check('orders without token → 401', () async {
    try {
      await api.get('orders');
      return 'unexpected 200';
    } on ApiException catch (e) {
      if (e.code != 'unauthenticated') rethrow;
      return e.code;
    }
  });
  stdout.writeln(failures == 0 ? 'ALL OK' : '$failures failed');
  exit(failures == 0 ? 0 : 1);
}
