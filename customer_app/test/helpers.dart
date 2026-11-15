import 'dart:convert';

import 'package:customer_app/app.dart';
import 'package:customer_app/core/api/api_client.dart';
import 'package:customer_app/core/storage/secure_store.dart';
import 'package:customer_app/state/app_state.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

typedef Handler = HttpResponseData Function(HttpRequestData request);

/// In-memory transport: routes are `'GET products'` (path after the market)
/// or `'GET /countries'` for global paths. Records every request.
class FakeTransport implements HttpTransport {
  FakeTransport([Map<String, Object>? routes]) : routes = {...?routes};

  final Map<String, Object> routes;
  final List<HttpRequestData> requests = [];

  static HttpResponseData ok(Object? data, {Map<String, dynamic> meta = const {}, int status = 200, Map<String, String> headers = const {}}) =>
      HttpResponseData(status, jsonEncode({'data': data, 'meta': meta}), headers);

  static HttpResponseData error(int status, String code, String message, [Map<String, List<String>> fields = const {}]) =>
      HttpResponseData(status, jsonEncode({'error': {'code': code, 'message': message, 'fields': fields}}));

  String keyFor(HttpRequestData r) {
    final segments = r.uri.pathSegments;
    final v1 = segments.indexOf('v1');
    final rest = segments.sublist(v1 + 1);
    final market = rest.isNotEmpty && RegExp(r'^[a-z]{3}-(en|ar)$').hasMatch(rest.first);
    final path = market ? rest.sublist(1).join('/') : '/${rest.join('/')}';
    return '${r.method} $path';
  }

  Map<String, dynamic> bodyOf(HttpRequestData r) => r.body == null ? {} : (jsonDecode(utf8.decode(r.body!)) as Map).cast<String, dynamic>();

  List<HttpRequestData> sent(String key) => requests.where((r) => keyFor(r) == key).toList();

  @override
  Future<HttpResponseData> send(HttpRequestData request) async {
    requests.add(request);
    final key = keyFor(request);
    final route = routes[key];
    if (route is Handler) return route(request);
    if (route is HttpResponseData) return route;
    if (route != null) return ok(route);
    if (request.method == 'GET') return ok(const []);
    return error(404, 'not_found', 'Not found: $key');
  }
}

Map<String, dynamic> money(String amount, [String currency = 'AED']) => {'amount': amount, 'currency': currency};

final countriesFixture = [
  {
    'code': 'uae',
    'iso2': 'AE',
    'name': 'United Arab Emirates',
    'currency': {'code': 'AED', 'decimals': 2},
    'phone_code': '+971',
    'markets': ['uae-en', 'uae-ar'],
    'default_locale': 'en',
  },
  {
    'code': 'omn',
    'iso2': 'OM',
    'name': 'Oman',
    'currency': {'code': 'OMR', 'decimals': 3},
    'phone_code': '+968',
    'markets': ['omn-ar', 'omn-en'],
    'default_locale': 'ar',
  },
];

final contextsFixture = [
  {
    'key': 'main',
    'name': 'Nawy',
    'icon': 'home',
    'theme': {'header_bg': '#FEEE00', 'header_fg': '#1B1B1B', 'accent': '#3866DF'},
    'home_page': null,
    'tabs': [
      {'key': 'home', 'label': 'Home', 'icon': 'home', 'target': 'home'},
      {'key': 'categories', 'label': 'Categories', 'icon': 'grid', 'target': 'categories'},
      {'key': 'deals', 'label': 'Deals', 'icon': 'tag', 'target': 'deals'},
      {'key': 'account', 'label': 'Account', 'icon': 'user', 'target': 'account'},
      {'key': 'cart', 'label': 'Cart', 'icon': 'cart', 'target': 'cart'},
    ],
  },
  {
    'key': 'travel',
    'name': 'Travel',
    'icon': 'plane',
    'theme': {'header_bg': '#0B6E99', 'header_fg': '#FFFFFF', 'accent': '#0B6E99'},
    'home_page': null,
    'tabs': [
      {'key': 'home', 'label': 'Trips', 'icon': 'home', 'target': 'home'},
      {'key': 'account', 'label': 'Account', 'icon': 'user', 'target': 'account'},
    ],
  },
];

Map<String, dynamic> cartFixture({int qty = 2}) => {
      'destination': {'city': null, 'area': null},
      'shipments': [
        {
          'seller': 'SoundMax Official',
          'items': [
            {
              'listing_id': 5,
              'sku': 'NW-1',
              'title': 'SoundMax Wireless Earbuds Pro',
              'product_slug': 'soundmax-earbuds-pro',
              'image': null,
              'quantity': qty,
              'unit_price': money('249.00'),
              'regular_price': money('299.00'),
              'line_total': money((249 * qty).toStringAsFixed(2)),
              'flash_sale': null,
              'warranty': null,
              'warranty_options': [],
            },
          ],
          'subtotal': money((249 * qty).toStringAsFixed(2)),
          'discount': money('0.00'),
          'shipping': money('10.00'),
          'method': 'standard',
          'delivery': {'from': '2026-11-19', 'by': '2026-11-23'},
          'shipping_options': [
            {'method': 'standard', 'fee': money('10.00'), 'delivery': {'from': '2026-11-19', 'by': '2026-11-23'}},
          ],
          'free_shipping_threshold_applied': false,
          'total': money((249 * qty + 10).toStringAsFixed(2)),
        },
      ],
      'coupons': [],
      'totals': {
        'subtotal': money((249 * qty).toStringAsFixed(2)),
        'discount': money('0.00'),
        'shipping': money('10.00'),
        'shipping_subsidy': money('0.00'),
        'cod_fee': money('0.00'),
        'warranty': money('0.00'),
        'total': money((249 * qty + 10).toStringAsFixed(2)),
        'tax_included': money('24.19'),
        'tax_rate': '5.00',
      },
      'warnings': [],
      'hold_minutes': 15,
    };

final emptyCart = {
  'destination': {'city': null, 'area': null},
  'shipments': [],
  'coupons': [],
  'totals': {'total': money('0.00')},
  'warnings': [],
  'hold_minutes': 15,
};

Map<String, Object> baseRoutes() => {
      'GET /countries': countriesFixture,
      'GET app/contexts': contextsFixture,
      'GET cart': emptyCart,
      'GET me/notifications/unread-count': {'unread': 0},
      'GET pages/home': FakeTransport.error(404, 'not_found', 'Not found.'),
    };

/// A ready AppState (market chosen) over a fake transport.
Future<(AppState, FakeTransport)> makeState({Map<String, Object>? routes, String market = 'uae-en', String? token, Map<String, String>? store}) async {
  final transport = FakeTransport({...baseRoutes(), ...?routes});
  final api = ApiClient(baseUrl: 'https://api.test/api/v1', transport: transport);
  final state = AppState(
    api: api,
    store: MemorySecureStore({
      StoreKeys.market: market,
      StoreKeys.token: ?token,
      if (token != null) StoreKeys.user: jsonEncode({'id': 1, 'name': 'Sara Ali', 'email': 'sara@example.com'}),
      ...?store,
    }),
  );
  await state.init();
  return (state, transport);
}

/// Pump the app at [route] (skips the splash) and settle the first frames.
Future<void> pumpApp(WidgetTester tester, AppState state, {String route = '/app', Object? arguments}) async {
  tester.view.physicalSize = const Size(1080, 2340);
  tester.view.devicePixelRatio = 3;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(NawyApp(state: state, initialRoute: route));
  await tester.pump();
  await tester.pump(const Duration(milliseconds: 50));
  await tester.pump(const Duration(milliseconds: 50));
}

/// Push a named route on the app navigator.
Future<void> pushRoute(WidgetTester tester, String name, [Object? arguments]) async {
  final navigator = tester.state<NavigatorState>(find.byType(Navigator).first);
  navigator.pushNamed(name, arguments: arguments);
  await tester.pump();
  await tester.pump(const Duration(milliseconds: 400));
  await tester.pump(const Duration(milliseconds: 50));
}

/// Let async loads (fake transport futures) and a few frames run.
Future<void> settle(WidgetTester tester, [int frames = 5]) async {
  for (var i = 0; i < frames; i++) {
    await tester.pump(const Duration(milliseconds: 100));
  }
}
