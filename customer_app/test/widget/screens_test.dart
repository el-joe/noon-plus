import 'package:customer_app/app.dart';
import 'package:customer_app/core/api/api_client.dart';
import 'package:customer_app/core/storage/secure_store.dart';
import 'package:customer_app/features/page_builder/blocks.dart';
import 'package:customer_app/l10n/l10n.dart';
import 'package:customer_app/routing/guards.dart';
import 'package:customer_app/screens/auth/sign_in_screen.dart';
import 'package:customer_app/screens/startup/startup_screens.dart';
import 'package:customer_app/state/app_state.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers.dart';

Map<String, dynamic> productFixture() => {
      'slug': 'soundmax-earbuds-pro',
      'title': 'SoundMax Wireless Earbuds Pro',
      'brand': 'SoundMax',
      'brand_slug': 'soundmax',
      'category': {'id': 2, 'slug': 'mobiles', 'name': 'Mobiles', 'image': null},
      'breadcrumbs': [],
      'description': null,
      'highlights': ['Fast delivery', '1-year warranty'],
      'specifications': [
        {'label': 'warranty', 'value': '1 year'},
      ],
      'images': [],
      'rating': {'average': '4.50', 'count': 12},
      'fulfilment_mode': 'ready_to_ship',
      'made_to_order': false,
      'measurement_fields': [],
      'has_size_guide': false,
      'buy_box': {
        'listing_id': 5,
        'options': {'colour': 'Black'},
        'seller': {'slug': 's1', 'name': 'SoundMax Official', 'rating': '4.50', 'rating_count': 3},
        'price': money('249.00'),
        'was_price': money('399.00'),
        'flash_sale': null,
        'delivery': {'from': '2026-11-19', 'by': '2026-11-23', 'order_before': null},
        'condition': 'new',
        'in_stock': 10,
      },
      'other_offers': [
        {
          'listing_id': 7,
          'options': {'colour': 'White'},
          'seller': {'slug': 's1', 'name': 'SoundMax Official', 'rating': '4.50'},
          'price': money('259.00'),
          'was_price': null,
          'flash_sale': null,
          'delivery': {'from': '2026-11-19', 'by': '2026-11-23'},
          'condition': 'new',
          'in_stock': 3,
        },
      ],
      'from_price': money('249.00'),
      'warranty_plans': [],
    };

void main() {
  testWidgets('market picker lists countries and stores the chosen market', (tester) async {
    final transport = FakeTransport(baseRoutes());
    final state = AppState(api: ApiClient(baseUrl: 'https://api.test/api/v1', transport: transport), store: MemorySecureStore());
    await state.init();
    await pumpApp(tester, state, route: Routes.market);
    await settle(tester);

    expect(find.text('Oman'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('country-omn')));
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('lang-ar')));
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('market-continue')));
    await settle(tester);

    expect(state.market, 'omn-ar');
    expect(await state.store.read(StoreKeys.market), 'omn-ar');
    expect(state.currency, 'OMR');
    await settle(tester);
    expect(tester.widget<Directionality>(find.byType(Directionality).last).textDirection, TextDirection.rtl);
  });

  testWidgets('splash goes to the picker when no market is stored', (tester) async {
    final transport = FakeTransport(baseRoutes());
    final state = AppState(api: ApiClient(baseUrl: 'https://api.test/api/v1', transport: transport), store: MemorySecureStore());
    await tester.pumpWidget(NawyApp(state: state));
    expect(find.byType(SplashScreen), findsOneWidget);
    await tester.pump(const Duration(seconds: 1));
    await settle(tester);
    expect(find.byType(MarketScreen), findsOneWidget);
  });

  testWidgets('shell: context switcher re-themes and swaps the tab bar (ADR 16)', (tester) async {
    final (state, _) = await makeState(routes: {
      'GET categories': [
        {'slug': 'electronics', 'name': 'Electronics', 'image': null},
      ],
      'GET travel/packages': [
        {'slug': 'umrah', 'title': 'Umrah 7 nights', 'agency': 'Gulf Holidays', 'destination': 'Makkah', 'nights': 7, 'from_price': money('1700.00'), 'status': 'active'},
      ],
    });
    await pumpApp(tester, state);
    await settle(tester);

    expect(find.byKey(const ValueKey('context-switcher')), findsOneWidget);
    expect(find.byKey(const ValueKey('tab-categories')), findsOneWidget);
    expect(find.byKey(const ValueKey('tab-cart')), findsOneWidget);
    expect(find.text('Electronics'), findsWidgets);

    await tester.tap(find.byKey(const ValueKey('context-travel')));
    await settle(tester);
    expect(state.activeContext, 'travel');
    expect(find.text('Trips'), findsOneWidget);
    expect(find.byKey(const ValueKey('tab-cart')), findsNothing);
    expect(find.text('Umrah 7 nights'), findsOneWidget);
  });

  testWidgets('sign-in by email code stores the token and merges the guest cart', (tester) async {
    final (state, transport) = await makeState(routes: {
      'POST auth/email-code/send': FakeTransport.ok({'sent': true}, status: 202),
      'POST auth/email-code/verify': {'token': 'tok-123', 'user': {'id': 1, 'name': 'Sara Ali', 'email': 'sara@example.com'}},
      'POST cart/merge': emptyCart,
    }, store: {'${StoreKeys.cartToken}:uae': 'guest-cart'});
    await pumpApp(tester, state, route: Routes.signIn);

    expect(find.byKey(const ValueKey('sign-in-guest')), findsOneWidget);
    await tester.enterText(find.byKey(const ValueKey('sign-in-email')), 'sara@example.com');
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('sign-in-send')));
    await settle(tester);
    expect(find.byKey(const ValueKey('sign-in-code')), findsOneWidget);

    await tester.enterText(find.byKey(const ValueKey('sign-in-code')), '123456');
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('sign-in-verify')));
    await settle(tester);

    expect(state.signedIn, isTrue);
    expect(await state.store.read(StoreKeys.token), 'tok-123');
    expect(transport.sent('POST cart/merge').single.headers['X-Cart-Token'], 'guest-cart');
    expect(state.api.cartToken, isNull);
    expect(transport.bodyOf(transport.sent('POST auth/email-code/verify').single)['device_name'], isNotEmpty);
  });

  testWidgets('sign-in shows the API error for a wrong code', (tester) async {
    final (state, _) = await makeState(routes: {
      'POST auth/email-code/send': FakeTransport.ok({'sent': true}, status: 202),
      'POST auth/email-code/verify': FakeTransport.error(422, 'code_invalid', 'That code is not right.'),
    });
    await pumpApp(tester, state, route: Routes.signIn);
    await tester.enterText(find.byKey(const ValueKey('sign-in-email')), 'sara@example.com');
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('sign-in-send')));
    await settle(tester);
    await tester.enterText(find.byKey(const ValueKey('sign-in-code')), '000000');
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('sign-in-verify')));
    await settle(tester);
    expect(find.text('That code is not right.'), findsOneWidget);
    expect(state.signedIn, isFalse);
  });

  testWidgets('checkout while signed out is guarded by sign-in', (tester) async {
    final (state, _) = await makeState();
    await pumpApp(tester, state);
    await pushRoute(tester, Routes.checkout);
    expect(find.byType(SignInScreen), findsOneWidget);
  });

  testWidgets('PDP: price, % off, variants and add to cart updates the badge', (tester) async {
    final (state, transport) = await makeState(routes: {
      'GET products/soundmax-earbuds-pro': productFixture(),
      'GET products/soundmax-earbuds-pro/reviews': {'summary': {'average': '4.50', 'count': 12, 'histogram': {'5': 8, '4': 4, '3': 0, '2': 0, '1': 0}}, 'reviews': []},
      'PUT cart/items': (HttpRequestData r) => FakeTransport.ok(cartFixture(qty: 1), headers: {'x-cart-token': 'guest-1'}),
    });
    await pumpApp(tester, state);
    await pushRoute(tester, Routes.product, {'slug': 'soundmax-earbuds-pro'});
    await settle(tester);

    expect(find.text('SoundMax Wireless Earbuds Pro'), findsOneWidget);
    expect(find.text('249.00'), findsOneWidget);
    expect(find.text('37% OFF'), findsOneWidget);
    expect(find.byKey(const ValueKey('variant-colour-White')), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('variant-colour-White')));
    await tester.pump();
    expect(find.text('259.00'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('pdp-add')));
    await settle(tester);
    expect(transport.bodyOf(transport.sent('PUT cart/items').single), {'listing_id': 7, 'quantity': 1});
    expect(state.cart.count, 1);
    expect(state.api.cartToken, 'guest-1');
    expect(find.byKey(const ValueKey('added-view-cart')), findsOneWidget);
    expect(await state.store.read('${StoreKeys.cartToken}:uae'), 'guest-1');
  });

  testWidgets('cart: lines, totals, quantity change and empty state', (tester) async {
    var qty = 2;
    final (state, transport) = await makeState(routes: {
      'GET cart': (HttpRequestData r) => FakeTransport.ok(cartFixture(qty: qty)),
      'PUT cart/items': (HttpRequestData r) {
        qty = 3;
        return FakeTransport.ok(cartFixture(qty: 3));
      },
      'DELETE cart/items/5': (HttpRequestData r) => FakeTransport.ok(emptyCart),
    });
    await pumpApp(tester, state);
    await pushRoute(tester, Routes.cart);
    await settle(tester);

    expect(find.text('Cart (2)'), findsOneWidget);
    expect(find.text('Shipment 1 of 1'), findsOneWidget);
    expect(find.text('AED 508.00'), findsWidgets);

    await tester.tap(find.descendant(of: find.byKey(const ValueKey('qty-5')), matching: find.byIcon(Icons.add)));
    await settle(tester);
    expect(transport.bodyOf(transport.sent('PUT cart/items').single)['quantity'], 3);
    expect(find.text('Cart (3)'), findsOneWidget);

    await tester.tap(find.byTooltip('Remove'));
    await settle(tester);
    expect(find.text('Your cart is empty'), findsOneWidget);
  });

  testWidgets('order detail shows the large delivery code to read aloud (ADR 15)', (tester) async {
    final (state, _) = await makeState(token: 'tok', routes: {
      'GET orders/NW-AE-1': {
        'number': 'NW-AE-1',
        'status': 'shipped',
        'payment_method': 'cod',
        'payment_status': 'pending',
        'payment': null,
        'placed_at': '2026-11-14T09:12:00+04:00',
        'totals': {'subtotal': money('249.00'), 'shipping': money('10.00'), 'total': money('259.00'), 'tax_included': money('12.33')},
        'shipments': [
          {
            'number': 'NW-AE-1-1',
            'status': 'out_for_delivery',
            'seller': 'SoundMax Official',
            'total': money('259.00'),
            'delivery': {'from': null, 'by': '2026-11-16'},
            'delivery_code': '4817',
            'items': [
              {'id': 11, 'title': 'SoundMax Wireless Earbuds Pro', 'quantity': 1, 'unit_price': money('249.00'), 'line_total': money('249.00')},
            ],
          },
        ],
        'shipping_address': {'name': 'Sara', 'city': 'Dubai', 'area': 'Marina', 'street': 'Marina Gate 2'},
      },
    });
    await pumpApp(tester, state);
    await pushRoute(tester, Routes.order, {'number': 'NW-AE-1'});
    await settle(tester);

    expect(find.byKey(const ValueKey('delivery-code')), findsOneWidget);
    expect(find.text('4 8 1 7'), findsOneWidget);
    expect(find.textContaining('Read this code aloud'), findsOneWidget);
    expect(find.text('Out for delivery'), findsWidgets);
  });

  testWidgets('travel request (D8): guest form, estimate, no payment, token kept', (tester) async {
    final package = {
      'slug': 'umrah',
      'title': 'Umrah 7 nights',
      'agency': 'Gulf Holidays',
      'departures': [
        {
          'id': 1,
          'departs_on': '2026-12-15',
          'returns_on': '2026-12-22',
          'seats_left': 20,
          'status': 'open',
          'rooms': [
            {'type': 'double', 'price_per_person': money('2000.00')},
            {'type': 'single', 'price_per_person': money('2600.00')},
          ],
        },
      ],
    };
    final (state, transport) = await makeState(routes: {
      'POST travel/departures/1/bookings': (HttpRequestData r) => FakeTransport.ok({
            'number': 'TB-AE-9',
            'status': 'requested',
            'agency': 'Gulf Holidays',
            'package': {'slug': 'umrah', 'title': 'Umrah 7 nights'},
            'quoted_total': money('4000.00'),
            'hold_expires_at': null,
            'access_token': 'body-token',
          }, status: 201, headers: {'x-booking-token': 'header-token'}),
    });
    await pumpApp(tester, state);
    await pushRoute(tester, Routes.travelRequest, {'package': package, 'departure': 1});

    expect(find.text('Booking as a guest is fine — we email you your reference.'), findsOneWidget);
    await tester.enterText(find.byKey(const ValueKey('travel-name')), 'Sara Al Balushi');
    await tester.enterText(find.byKey(const ValueKey('travel-email')), 'sara@example.com');
    await tester.enterText(find.byKey(const ValueKey('travel-phone')), '+971501234567');
    await tester.scrollUntilVisible(find.byKey(const ValueKey('room-double')), 200, scrollable: find.byType(Scrollable).last);
    await tester.tap(find.descendant(of: find.byKey(const ValueKey('room-double')), matching: find.byIcon(Icons.add)));
    await tester.pump();
    expect(find.text('AED 4,000.00'), findsWidgets);

    await tester.scrollUntilVisible(find.byKey(const ValueKey('travel-agree')), 300, scrollable: find.byType(Scrollable).last);
    await tester.tap(find.byKey(const ValueKey('travel-agree')));
    await tester.pump();
    await tester.scrollUntilVisible(find.byKey(const ValueKey('travel-send')), 300, scrollable: find.byType(Scrollable).last);
    await tester.tap(find.byKey(const ValueKey('travel-send')));
    await settle(tester);

    final body = transport.bodyOf(transport.sent('POST travel/departures/1/bookings').single);
    expect(body['rooms'], [
      {'room_type': 'double', 'travellers': 2},
    ]);
    expect(body['submit'], true);
    expect(body.containsKey('payment_method'), isFalse);
    expect(find.text('Request sent'), findsOneWidget);
    final guests = await state.readList(StoreKeys.guestBookings);
    expect(guests.single['token'], 'header-token');
  });

  testWidgets('page builder renders known blocks, skips unknown ones and labels ads', (tester) async {
    final (state, _) = await makeState(routes: {
      'GET pages/home': {
        'slug': 'home',
        'template': 'home',
        'zones': {
          'main': [
            {'type': 'mystery_block_v9', 'settings': {}},
            {
              'type': 'quick_links',
              'settings': {
                'title': 'Shortcuts',
                'items': [
                  {'label': 'Mobiles', 'link': '/category/mobiles'},
                ],
              },
            },
            {
              'type': 'product_carousel',
              'settings': {'title_en': 'Picked for you', 'title_ar': 'اخترنا لك', 'source': 'manual'},
              'data': {
                'products': [
                  {'slug': 'a', 'title': 'Sponsored Phone', 'price': money('999.00'), 'sponsored': true, 'label': 'Ad', 'ad_token': 'x', 'rating': {'average': null, 'count': 0}},
                  {'slug': 'b', 'title': 'Organic Phone', 'price': money('899.00'), 'sponsored': false, 'rating': {'average': '4.2', 'count': 5}},
                ],
              },
            },
          ],
        },
      },
    });
    await pumpApp(tester, state);
    await settle(tester);

    expect(find.text('Shortcuts'), findsOneWidget);
    expect(find.text('Picked for you'), findsOneWidget);
    expect(find.text('Sponsored Phone'), findsOneWidget);
    expect(find.text('Organic Phone'), findsOneWidget);
    expect(find.text('Ad'), findsOneWidget);
    expect(knownBlockTypes.length, 51);
  });

  testWidgets('Arabic market renders right-to-left with Arabic strings', (tester) async {
    final (state, _) = await makeState(market: 'uae-ar');
    await pumpApp(tester, state);
    await settle(tester);
    final context = tester.element(find.byType(Scaffold).first);
    expect(Directionality.of(context), TextDirection.rtl);
    expect(L10n.of(context).isAr, isTrue);
    expect(find.text('عمّ تبحث؟'), findsOneWidget);
  });

  testWidgets('errors show a retry state', (tester) async {
    final (state, _) = await makeState(routes: {
      'GET flash-sales': FakeTransport.error(500, 'server_error', 'Server exploded'),
    });
    await pumpApp(tester, state);
    await tester.tap(find.byKey(const ValueKey('tab-deals')));
    await settle(tester);
    expect(find.text('Server exploded'), findsOneWidget);
    expect(find.text('Try again'), findsOneWidget);
  });

  testWidgets('account tab: guest sees sign-in; signed-in sees name and unread badge', (tester) async {
    final (guest, _) = await makeState();
    await pumpApp(tester, guest);
    await tester.tap(find.byKey(const ValueKey('tab-account')));
    await settle(tester);
    expect(find.byKey(const ValueKey('account-sign-in')), findsOneWidget);

    final (state, _) = await makeState(token: 'tok', routes: {'GET me/notifications/unread-count': {'unread': 3}});
    await pumpApp(tester, state);
    await settle(tester);
    await tester.tap(find.byKey(const ValueKey('tab-account')));
    await settle(tester);
    expect(find.text('Hala, Sara Ali'), findsOneWidget);
    expect(state.unread, 3);
    expect(find.text('3'), findsWidgets);
  });

  testWidgets('PLP: results count, sort sheet and filter sheet with live count', (tester) async {
    final (state, transport) = await makeState(routes: {
      'GET products': (HttpRequestData r) => FakeTransport.ok([
            {'slug': 'a', 'title': 'Air Fryer XL', 'price': money('349.00'), 'rating': {'average': '4.6', 'count': 120}},
            {'slug': 'b', 'title': 'Air Fryer Mini', 'price': money('199.00'), 'rating': {'average': null, 'count': 0}},
          ], meta: {'total': 2, 'page': 1, 'last_page': 1, 'per_page': 20}),
      'GET products/facets': {
        'brands': [
          {'slug': 'chefpro', 'name': 'ChefPro', 'count': 2},
        ],
        'sellers': [],
        'price': {'min': money('199.00'), 'max': money('349.00')},
        'ratings': [],
        'attributes': [
          {'key': 'colour', 'values': [
            {'value': 'Black', 'count': 2},
          ]},
        ],
      },
    });
    await pumpApp(tester, state);
    await pushRoute(tester, Routes.products, {'q': 'air fryer'});
    await settle(tester);
    expect(find.text('Air Fryer XL'), findsOneWidget);
    expect(find.text('2 results for air fryer'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('chip-sort')));
    await settle(tester);
    await tester.tap(find.byKey(const ValueKey('sort-price_asc')));
    await settle(tester);
    expect(transport.sent('GET products').last.uri.queryParameters['sort'], 'price_asc');

    await tester.tap(find.byKey(const ValueKey('chip-filter')));
    await settle(tester);
    await tester.tap(find.byKey(const ValueKey('facet-brand')));
    await settle(tester);
    await tester.tap(find.text('ChefPro').last);
    await settle(tester);
    expect(find.text('Show 2 results'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('filters-apply')));
    await settle(tester);
    expect(transport.sent('GET products').last.uri.queryParameters['brand'], 'chefpro');
    await tester.tap(find.byKey(const ValueKey('toggle-layout')));
    await settle(tester);
    expect(find.text('Air Fryer Mini'), findsOneWidget);
  });

  testWidgets('checkout: saved address, payment methods from the API, COD order → success', (tester) async {
    final (state, transport) = await makeState(token: 'tok', routes: {
      'GET cart': (HttpRequestData r) => FakeTransport.ok(cartFixture(qty: 1)),
      'PUT cart/shipping': (HttpRequestData r) => FakeTransport.ok(cartFixture(qty: 1)),
      'GET addresses': [
        {'id': 3, 'label': 'home', 'name': 'Sara', 'phone': '+971501234567', 'city': 'Dubai', 'area': 'Marina', 'street': 'Marina Gate 2', 'is_default': true},
      ],
      'GET wallet': {'currency': 'AED', 'available': money('120.00'), 'spendable': money('120.00'), 'promotional': money('0.00'), 'frozen': money('0.00'), 'promotional_expiring': []},
      'GET checkout/payment-methods': [
        {'code': 'stripe', 'type': 'card', 'name': 'Credit / debit card', 'description': 'Visa', 'recommended': true, 'fee': null},
        {'code': 'cod', 'type': 'cod', 'name': 'Cash on delivery', 'description': null, 'recommended': false, 'fee': money('10.00')},
      ],
      'POST orders': (HttpRequestData r) => FakeTransport.ok({
            'number': 'NW-AE-77',
            'status': 'placed',
            'payment_method': 'cod',
            'payment_status': 'pending',
            'payment': {'reference': 'r', 'method': 'cod', 'status': 'pending', 'next_action': {'type': 'none'}},
            'shipments': [],
            'totals': {},
          }, status: 201),
    });
    await pumpApp(tester, state);
    await pushRoute(tester, Routes.checkout);
    await settle(tester);
    expect(find.text('Home · Sara'), findsOneWidget);
    expect(find.text('Credit / debit card'), findsOneWidget);
    expect(find.text('You have AED 120.00 in Nawy Credits'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('pay-cod')));
    await settle(tester);
    expect(transport.sent('GET cart').last.uri.queryParameters['payment_method'], 'cod');
    await tester.tap(find.byKey(const ValueKey('place-order')));
    await settle(tester, 8);
    expect(transport.bodyOf(transport.sent('POST orders').single), {'payment_method': 'cod', 'address_id': 3});
    expect(find.text('Order placed!'), findsOneWidget);
  });

  testWidgets('souq viewing request works for guests (D8)', (tester) async {
    final ad = {
      'number': 'CL-AE-4',
      'title': 'Furnished 1-bed — Marina Gate',
      'price': money('98000.00'),
      'image': null,
      'status': 'active',
      'request_types': ['viewing', 'reserve'],
      'seller': {'type': 'individual', 'shop': null, 'broker': null},
      'property': {'purpose': 'rent', 'bedrooms': 1},
      'media': [],
      'similar': [],
    };
    final (state, transport) = await makeState(routes: {
      'GET classifieds/ads/CL-AE-4': ad,
      'POST classifieds/ads/CL-AE-4/viewings': FakeTransport.ok({'id': 9, 'type': 'reserve', 'status': 'requested'}, status: 201),
    });
    await pumpApp(tester, state);
    await pushRoute(tester, Routes.souqAd, {'number': 'CL-AE-4'});
    await settle(tester);
    expect(find.text('Request a viewing'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('request-reserve')));
    await settle(tester);
    final fields = find.byType(TextField);
    await tester.enterText(fields.at(0), 'Sara');
    await tester.enterText(fields.at(1), 'sara@example.com');
    await tester.enterText(fields.at(2), '+971501234567');
    await tester.scrollUntilVisible(find.byKey(const ValueKey('souq-send')), 300, scrollable: find.byType(Scrollable).last);
    await tester.tap(find.byKey(const ValueKey('souq-send')));
    await settle(tester);
    final body = transport.bodyOf(transport.sent('POST classifieds/ads/CL-AE-4/viewings').single);
    expect(body['type'], 'reserve');
    expect(body['email'], 'sara@example.com');
    expect(body['preferred_at'], isNotEmpty);
    expect(find.text('Request sent'), findsOneWidget);
  });

  testWidgets('notifications: list, unread filter and mark all read', (tester) async {
    var read = false;
    final (state, transport) = await makeState(token: 'tok', routes: {
      'GET me/notifications/unread-count': {'unread': 1},
      'GET me/notifications': (HttpRequestData r) => FakeTransport.ok([
            {'id': 1, 'type': 'order.shipped', 'title': 'Your order shipped', 'body': 'NW-1 is on its way', 'link': '/account/orders/NW-1', 'read': read, 'created_at': '2026-11-14T10:00:00Z'},
          ], meta: {'total': 1, 'page': 1, 'last_page': 1, 'unread': read ? 0 : 1}),
      'POST me/notifications/read-all': (HttpRequestData r) {
        read = true;
        return FakeTransport.ok({'marked': 1, 'unread': 0});
      },
    });
    await pumpApp(tester, state);
    await pushRoute(tester, Routes.notifications);
    await settle(tester);
    expect(find.text('Your order shipped'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('read-all')));
    await settle(tester);
    expect(transport.sent('POST me/notifications/read-all'), hasLength(1));
    expect(state.unread, 0);
  });

  testWidgets('live watch: placeholder player and shoppable products', (tester) async {
    final (state, _) = await makeState(routes: {
      'GET live-streams/demo': {
        'slug': 'demo',
        'type': 'recorded',
        'title': 'Demo Nawy TV show',
        'status': 'ended',
        'likes': 4,
        'playback': {'type': 'recorded', 'media_url': 'https://cdn.test/v.m3u8'},
        'products': [
          {'listing_id': 5, 'slug': 'a', 'title': 'Linked Earbuds', 'price': money('249.00')},
        ],
      },
    });
    await pumpApp(tester, state);
    await pushRoute(tester, Routes.liveWatch, {'slug': 'demo'});
    await settle(tester);
    expect(find.text('Demo Nawy TV show'), findsOneWidget);
    expect(find.byKey(const ValueKey('live-play')), findsOneWidget);
    expect(find.text('Linked Earbuds'), findsOneWidget);
  });
}
