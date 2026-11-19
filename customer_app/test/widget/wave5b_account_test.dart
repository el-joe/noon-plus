import 'package:customer_app/core/api/api_client.dart';
import 'package:customer_app/core/platform/platform_bridge.dart';
import 'package:customer_app/core/storage/secure_store.dart';
import 'package:customer_app/routing/deep_links.dart';
import 'package:customer_app/routing/guards.dart';
import 'package:customer_app/screens/account/static_page_screen.dart';
import 'package:customer_app/state/push_tokens.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers.dart';

Map<String, dynamic> shipment({String number = 'NW-AE-9-1', String status = 'delivered', Map<String, bool>? actions}) => {
      'number': number,
      'status': status,
      'seller': 'SoundMax Official',
      'delivered_at': status == 'delivered' ? '2026-11-16T10:00:00+04:00' : null,
      'return_until': '2026-11-30',
      'actions': {'track': true, 'invoice': false, 'cancel': false, 'return': false, 'review': false, 'buy_again': false, 'dispute': false, ...?actions},
      'items': [
        {'id': 11, 'listing_id': 5, 'title': 'SoundMax Earbuds', 'quantity': 2, 'unit_price': money('249.00'), 'image': null},
        {'id': 12, 'listing_id': 6, 'title': 'Case', 'quantity': 1, 'unit_price': money('19.00'), 'image': null},
      ],
    };

Map<String, dynamic> order({String number = 'NW-AE-9', List<Map<String, dynamic>>? shipments, bool invoice = false}) => {
      'number': number,
      'status': 'delivered',
      'payment_method': 'card',
      'payment_status': 'paid',
      'payment': null,
      'invoice_available': invoice,
      'placed_at': '2026-11-14T09:12:00+04:00',
      'totals': {'subtotal': money('517.00'), 'shipping': money('0.00'), 'total': money('517.00'), 'tax_included': money('24.62')},
      'shipments': shipments ?? [shipment()],
      'shipping_address': {'name': 'Sara', 'city': 'Dubai'},
    };

const counts = {'all': 7, 'in_progress': 2, 'delivered': 4, 'cancelled': 1, 'returns': 0};

Map<String, dynamic> ticket({String status = 'open', bool canReply = true, bool canReopen = false, bool canRate = false, Map<String, dynamic>? rating}) => {
      'number': 'TK-AE-000001',
      'category': 'order',
      'category_label': 'Order',
      'subject': 'Where is my parcel?',
      'priority': 'normal',
      'status': status,
      'status_label': status,
      'order': 'NW-AE-9',
      'shipment': null,
      'reply_expected_by': '2026-11-18T20:00:00+04:00',
      'first_responded_at': null,
      'can_reply': canReply,
      'can_reopen': canReopen,
      'reopen_until': canReopen ? '2026-11-25T10:00:00+04:00' : null,
      'can_rate': canRate,
      'rating': rating,
      'created_at': '2026-11-18T08:00:00+04:00',
      'last_message_at': '2026-11-18T09:00:00+04:00',
      'messages': [
        {'id': 1, 'from': 'requester', 'mine': true, 'body': 'My parcel is late.', 'attachments': [], 'created_at': '2026-11-18T08:00:00+04:00'},
        {'id': 2, 'from': 'support', 'mine': false, 'body': 'We are checking with the courier.', 'attachments': [], 'created_at': '2026-11-18T09:00:00+04:00'},
      ],
      'history': [],
    };

void main() {
  final opened = <String>[];
  setUp(() {
    opened.clear();
    PlatformBridge.debugOpenUrl = (url) async {
      opened.add(url);
      return true;
    };
  });
  tearDown(() => PlatformBridge.debugOpenUrl = null);

  group('orders (QC-026)', () {
    testWidgets('tabs show counts, switching a tab and searching send tab and q', (tester) async {
      final (state, transport) = await makeState(token: 'tok', routes: {
        'GET orders': (HttpRequestData r) => FakeTransport.ok([order()], meta: {'total': 1, 'page': 1, 'per_page': 20, 'last_page': 1, 'tab': r.uri.queryParameters['tab'], 'counts': counts}),
      });
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.orders);
      await settle(tester);
      expect(find.text('All (7)'), findsOneWidget);
      expect(find.text('In progress (2)'), findsOneWidget);
      expect(transport.sent('GET orders').last.uri.queryParameters['tab'], 'all');

      await tester.tap(find.byKey(const ValueKey('orders-tab-in_progress')));
      await settle(tester);
      expect(transport.sent('GET orders').last.uri.queryParameters['tab'], 'in_progress');

      await tester.enterText(find.byKey(const ValueKey('orders-search')), 'earbuds');
      await tester.testTextInput.receiveAction(TextInputAction.search);
      await settle(tester);
      final last = transport.sent('GET orders').last.uri.queryParameters;
      expect(last['q'], 'earbuds');
      expect(last['tab'], 'in_progress');
    });

    testWidgets('per-shipment buttons follow `actions`', (tester) async {
      final (state, _) = await makeState(token: 'tok', routes: {
        'GET orders': FakeTransport.ok([
          order(shipments: [shipment(actions: {'invoice': true, 'review': true, 'buy_again': true, 'dispute': true})]),
        ], meta: {'page': 1, 'last_page': 1, 'counts': counts}),
      });
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.orders);
      await settle(tester);
      for (final key in ['track', 'invoice', 'review', 'buy-again', 'dispute']) {
        expect(find.byKey(ValueKey('ship-$key-NW-AE-9-1')), findsOneWidget, reason: key);
      }
      expect(find.byKey(const ValueKey('ship-cancel-NW-AE-9-1')), findsNothing);
      expect(find.byKey(const ValueKey('ship-return-NW-AE-9-1')), findsNothing);
      expect(find.text('Delivered 16 Nov 2026'), findsOneWidget);
    });

    testWidgets('Invoice asks for a signed link and opens it in the system browser', (tester) async {
      final (state, transport) = await makeState(token: 'tok', routes: {
        'GET orders': FakeTransport.ok([order(shipments: [shipment(actions: {'invoice': true})])], meta: {'page': 1, 'last_page': 1, 'counts': counts}),
        'POST orders/NW-AE-9/invoice-link': {'url': 'https://api.test/api/v1/uae-en/orders/NW-AE-9/invoice/signed?signature=abc', 'expires_at': '2026-11-18T10:00:00Z'},
      });
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.orders);
      await settle(tester);
      await tester.tap(find.byKey(const ValueKey('ship-invoice-NW-AE-9-1')));
      await settle(tester);
      expect(transport.sent('POST orders/NW-AE-9/invoice-link'), hasLength(1));
      expect(opened.single, contains('signature=abc'));
    });

    testWidgets('an invoice that is not ready shows the API message', (tester) async {
      final (state, _) = await makeState(token: 'tok', routes: {
        'GET orders': FakeTransport.ok([order(shipments: [shipment(actions: {'invoice': true})])], meta: {'page': 1, 'last_page': 1, 'counts': counts}),
        'POST orders/NW-AE-9/invoice-link': FakeTransport.error(409, 'invoice_not_ready', 'The invoice is ready once the order is paid.'),
      });
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.orders);
      await settle(tester);
      await tester.tap(find.byKey(const ValueKey('ship-invoice-NW-AE-9-1')));
      await settle(tester);
      expect(find.text('The invoice is ready once the order is paid.'), findsOneWidget);
      expect(opened, isEmpty);
    });

    testWidgets('cancel uses the reason codes; `other` needs a note', (tester) async {
      final (state, transport) = await makeState(token: 'tok', routes: {
        'GET orders': FakeTransport.ok([order(shipments: [shipment(status: 'confirmed', actions: {'cancel': true})])], meta: {'page': 1, 'last_page': 1, 'counts': counts}),
        'GET orders/cancel-reasons': [
          {'code': 'changed_mind', 'label': 'I changed my mind', 'needs_note': false},
          {'code': 'other', 'label': 'Other reason', 'needs_note': true},
        ],
        'POST orders/NW-AE-9/shipments/NW-AE-9-1/cancel': order(),
      });
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.orders);
      await settle(tester);
      await tester.tap(find.byKey(const ValueKey('ship-cancel-NW-AE-9-1')));
      await settle(tester);
      expect(find.text('I changed my mind'), findsOneWidget);
      await tester.tap(find.text('Other reason'));
      await tester.pump();
      await tester.tap(find.byKey(const ValueKey('cancel-confirm')));
      await tester.pump();
      expect(find.text('Please add a short note'), findsOneWidget);
      expect(transport.sent('POST orders/NW-AE-9/shipments/NW-AE-9-1/cancel'), isEmpty);

      await tester.enterText(find.byKey(const ValueKey('cancel-note')), 'Ordered twice');
      await tester.tap(find.byKey(const ValueKey('cancel-confirm')));
      await settle(tester);
      final body = transport.bodyOf(transport.sent('POST orders/NW-AE-9/shipments/NW-AE-9-1/cancel').single);
      expect(body, {'reason': 'other', 'note': 'Ordered twice'});
      expect(find.text('Shipment cancelled'), findsOneWidget);
    });

    testWidgets('Buy again puts every item back into the cart and opens it', (tester) async {
      final (state, transport) = await makeState(token: 'tok', routes: {
        'GET orders': FakeTransport.ok([order(shipments: [shipment(actions: {'buy_again': true})])], meta: {'page': 1, 'last_page': 1, 'counts': counts}),
        'PUT cart/items': cartFixture(),
      });
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.orders);
      await settle(tester);
      await tester.tap(find.byKey(const ValueKey('ship-buy-again-NW-AE-9-1')));
      await settle(tester);
      final bodies = transport.sent('PUT cart/items').map(transport.bodyOf).toList();
      expect(bodies.map((b) => b['listing_id']), [5, 6]);
      // Back on the shell, on the cart tab.
      await settle(tester);
      expect(find.text('2 item(s) added to your cart'), findsOneWidget);
      expect(find.byKey(const ValueKey('orders-search')), findsNothing);
    });

    testWidgets('Report a problem opens a dispute on the shipment', (tester) async {
      final (state, transport) = await makeState(token: 'tok', routes: {
        'GET orders': FakeTransport.ok([order(shipments: [shipment(actions: {'dispute': true})])], meta: {'page': 1, 'last_page': 1, 'counts': counts}),
        'POST disputes': {'number': 'DS-1'},
      });
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.orders);
      await settle(tester);
      await tester.tap(find.byKey(const ValueKey('ship-dispute-NW-AE-9-1')));
      await settle(tester);
      await tester.enterText(find.byKey(const ValueKey('dispute-message')), 'The box arrived empty.');
      await tester.tap(find.text('Send'));
      await settle(tester);
      expect(transport.bodyOf(transport.sent('POST disputes').single), {'shipment': 'NW-AE-9-1', 'reason': 'not_received', 'message': 'The box arrived empty.'});
    });

    testWidgets('order detail: invoice row and "Get help" opens a ticket for the order', (tester) async {
      final (state, _) = await makeState(token: 'tok', routes: {
        'GET orders/NW-AE-9': order(invoice: true),
        'GET support/categories': {'categories': [{'value': 'order', 'label': 'Order'}], 'priorities': ['low', 'normal', 'high']},
      });
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.order, {'number': 'NW-AE-9'});
      await settle(tester);
      await tester.scrollUntilVisible(find.byKey(const ValueKey('order-invoice')), 200, scrollable: find.byType(Scrollable).last);
      expect(find.byKey(const ValueKey('order-invoice')), findsOneWidget);
      await tester.scrollUntilVisible(find.byKey(const ValueKey('order-get-help')), 200, scrollable: find.byType(Scrollable).last);
      await tester.tap(find.byKey(const ValueKey('order-get-help')));
      await settle(tester);
      expect(find.text('NW-AE-9'), findsOneWidget);
    });
  });

  group('support tickets (QC-007)', () {
    testWidgets('list filters by status and opens a ticket', (tester) async {
      final (state, transport) = await makeState(token: 'tok', routes: {
        'GET support/tickets': FakeTransport.ok([ticket(status: 'waiting_customer')], meta: {'total': 1, 'page': 1, 'per_page': 20, 'last_page': 1}),
        'GET support/tickets/TK-AE-000001': ticket(),
      });
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.support);
      await settle(tester);
      expect(find.text('Where is my parcel?'), findsOneWidget);
      expect(find.text('Waiting for your reply'), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('support-filter-open')));
      await settle(tester);
      expect(transport.sent('GET support/tickets').last.uri.queryParameters['status'], 'open');
      await tester.tap(find.byKey(const ValueKey('ticket-TK-AE-000001')));
      await settle(tester);
      expect(find.text('We are checking with the courier.'), findsOneWidget);
    });

    testWidgets('new ticket validates, then posts category, priority, subject, message and order', (tester) async {
      final (state, transport) = await makeState(token: 'tok', routes: {
        'GET support/categories': {
          'categories': [{'value': 'order', 'label': 'Order'}, {'value': 'payment', 'label': 'Payment'}],
          'priorities': ['low', 'normal', 'high'],
        },
        'POST support/tickets': FakeTransport.ok(ticket(), status: 201),
        'GET support/tickets/TK-AE-000001': ticket(),
      });
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.supportNew, {'order': 'NW-AE-9'});
      await settle(tester);
      expect(find.byKey(const ValueKey('support-attachments')), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('support-submit')));
      await settle(tester);
      expect(find.text('Choose a topic'), findsOneWidget);
      expect(transport.sent('POST support/tickets'), isEmpty);

      await tester.tap(find.byKey(const ValueKey('support-category')));
      await settle(tester);
      await tester.tap(find.text('Payment').last);
      await settle(tester);
      await tester.tap(find.byKey(const ValueKey('support-priority-high')));
      await tester.enterText(find.byKey(const ValueKey('support-subject')), 'Charged twice');
      await tester.enterText(find.byKey(const ValueKey('support-message')), 'I see two charges for one order.');
      await tester.tap(find.byKey(const ValueKey('support-submit')));
      await settle(tester);
      expect(transport.bodyOf(transport.sent('POST support/tickets').single), {
        'category': 'payment',
        'priority': 'high',
        'subject': 'Charged twice',
        'message': 'I see two charges for one order.',
        'order': 'NW-AE-9',
      });
      // Opens the new ticket.
      expect(find.text('My parcel is late.'), findsOneWidget);
    });

    testWidgets('reply posts the body and reloads', (tester) async {
      final (state, transport) = await makeState(token: 'tok', routes: {
        'GET support/tickets/TK-AE-000001': ticket(),
        'POST support/tickets/TK-AE-000001/messages': FakeTransport.ok({'id': 3}, status: 201),
      });
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.supportTicket, {'number': 'TK-AE-000001'});
      await settle(tester);
      await tester.enterText(find.byKey(const ValueKey('support-reply')), 'Thanks, any update?');
      await tester.tap(find.byKey(const ValueKey('support-reply-send')));
      await settle(tester);
      expect(transport.bodyOf(transport.sent('POST support/tickets/TK-AE-000001/messages').single), {'body': 'Thanks, any update?'});
      expect(transport.sent('GET support/tickets/TK-AE-000001'), hasLength(2));
    });

    testWidgets('a resolved ticket can be reopened and rated; refusals show the API message', (tester) async {
      final (state, transport) = await makeState(token: 'tok', routes: {
        'GET support/tickets/TK-AE-000001': ticket(status: 'resolved', canReply: false, canReopen: true, canRate: true),
        'POST support/tickets/TK-AE-000001/reopen': FakeTransport.error(422, 'not_allowed', 'The reopen window has passed.'),
        'POST support/tickets/TK-AE-000001/rating': ticket(status: 'resolved', rating: {'score': 4}),
      });
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.supportTicket, {'number': 'TK-AE-000001'});
      await settle(tester);
      expect(find.byKey(const ValueKey('support-reply')), findsNothing);
      expect(find.textContaining('Reopen it until 25 Nov 2026'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('support-reopen')));
      await settle(tester);
      await tester.enterText(find.byKey(const ValueKey('support-reopen-note')), 'Still not delivered');
      await tester.tap(find.byKey(const ValueKey('support-reopen-confirm')));
      await settle(tester);
      expect(transport.bodyOf(transport.sent('POST support/tickets/TK-AE-000001/reopen').single), {'message': 'Still not delivered'});
      expect(find.text('The reopen window has passed.'), findsOneWidget);
      await tester.pump(const Duration(seconds: 5)); // let the snack bar go
      await settle(tester);

      await tester.ensureVisible(find.byKey(const ValueKey('support-star-4')));
      await tester.pump();
      await tester.tap(find.byKey(const ValueKey('support-star-4')));
      await tester.pump();
      await tester.ensureVisible(find.byKey(const ValueKey('support-rate-send')));
      await tester.pump();
      await tester.tap(find.byKey(const ValueKey('support-rate-send')));
      await settle(tester);
      expect(transport.bodyOf(transport.sent('POST support/tickets/TK-AE-000001/rating').single), {'rating': 4});
    });

    test('support screens need a signed-in customer', () {
      for (final route in [Routes.support, Routes.supportNew, Routes.supportTicket, Routes.cards, Routes.followedStores]) {
        expect(guardRedirect(route, hasMarket: true, signedIn: false), Routes.signIn, reason: route);
      }
    });
  });

  group('saved cards (G-A6)', () {
    final cards = [
      {'id': 1, 'gateway': 'fake', 'brand': 'visa', 'last4': '4242', 'exp_month': 4, 'exp_year': 2029, 'expired': false, 'is_default': true},
      {'id': 2, 'gateway': 'fake', 'brand': 'mastercard', 'last4': '5454', 'exp_month': 1, 'exp_year': 2025, 'expired': true, 'is_default': false},
      {'id': 3, 'gateway': 'fake', 'brand': 'visa', 'last4': '1111', 'exp_month': 12, 'exp_year': 2030, 'expired': false, 'is_default': false},
    ];

    testWidgets('lists cards, sets the default and deletes after confirming', (tester) async {
      final (state, transport) = await makeState(token: 'tok', routes: {
        'GET me/cards': cards,
        'POST me/cards/3/default': {'id': 3, 'is_default': true},
        'DELETE me/cards/2': {'deleted': true},
      });
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.cards);
      await settle(tester);
      expect(find.text('•••• 4242'), findsOneWidget);
      expect(find.byKey(const ValueKey('card-default-1')), findsOneWidget);
      expect(find.text('Expired 01/25'), findsOneWidget);
      expect(find.text('Expires 12/30'), findsOneWidget);
      // An expired card cannot become the default.
      expect(find.byKey(const ValueKey('card-make-default-2')), findsNothing);

      await tester.tap(find.byKey(const ValueKey('card-make-default-3')));
      await settle(tester);
      expect(transport.sent('POST me/cards/3/default'), hasLength(1));

      await tester.tap(find.byKey(const ValueKey('card-delete-2')));
      await settle(tester);
      await tester.tap(find.byKey(const ValueKey('confirm-yes')));
      await settle(tester);
      expect(transport.sent('DELETE me/cards/2'), hasLength(1));
      expect(transport.sent('GET me/cards').length, greaterThanOrEqualTo(3));
    });

    testWidgets('no cards → empty state', (tester) async {
      final (state, _) = await makeState(token: 'tok', routes: {'GET me/cards': <Object>[]});
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.cards);
      await settle(tester);
      expect(find.text('No saved cards'), findsOneWidget);
    });
  });

  group('push token (G-A2)', () {
    testWidgets('registered on sign-in with a stable install id, unregistered on sign-out', (tester) async {
      final (state, transport) = await makeState(routes: {
        'POST me/push-tokens': FakeTransport.ok({'registered': true}, status: 201),
        'DELETE me/push-tokens': {'deleted': true},
        'POST auth/logout': {'ok': true},
        'POST me/recently-viewed': <Object>[],
      });
      await state.signIn('tok', {'id': 1, 'name': 'Sara', 'email': 'sara@example.com', 'locale': 'en'});
      await tester.pump();
      final sent = transport.bodyOf(transport.sent('POST me/push-tokens').single);
      final id = await state.store.read(StoreKeys.installId);
      expect(id, matches(RegExp(r'^[0-9a-f]{32}$')));
      expect(sent['token'], 'nawy-install-$id');
      expect(sent['platform'], PushTokens.platform);
      expect(sent['app'], 'customer');

      await state.signOut();
      expect(transport.bodyOf(transport.sent('DELETE me/push-tokens').single), {'token': 'nawy-install-$id'});
      // The unregister goes out while the token is still valid.
      final keys = transport.requests.map(transport.keyFor).toList();
      expect(keys.indexOf('DELETE me/push-tokens'), lessThan(keys.indexOf('POST auth/logout')));

      // The same install keeps its id.
      expect(await PushTokens(api: state.api, store: state.store).deviceToken(), 'nawy-install-$id');
    });
  });

  group('market data (G-A8)', () {
    testWidgets('the market picker shows Arabic country names from names.ar', (tester) async {
      final countries = [
        for (final c in countriesFixture) {...c, 'names': {'en': c['name'], 'ar': c['code'] == 'omn' ? 'عُمان' : 'الإمارات'}},
      ];
      final (state, _) = await makeState(market: 'omn-ar', routes: {'GET /countries': countries});
      await pumpApp(tester, state, route: Routes.market);
      await settle(tester);
      expect(find.text('عُمان'), findsOneWidget);
      expect(state.countryName(state.country), 'عُمان');
    });
  });

  group('static pages (QA-032)', () {
    testWidgets('a placeholder page shows the banner', (tester) async {
      final (state, _) = await makeState(routes: {
        'GET static-pages/terms-and-conditions': {'slug': 'terms-and-conditions', 'title': 'Terms and conditions', 'body': '<p>Not published yet.</p><p>Second &amp; last.</p>', 'placeholder': true},
      });
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.staticPage, {'slug': 'terms-and-conditions'});
      await settle(tester);
      expect(find.byKey(const ValueKey('static-page-placeholder')), findsOneWidget);
      expect(find.text('Not published yet.\n\nSecond & last.'), findsOneWidget);
    });

    testWidgets('a published page has no banner', (tester) async {
      final (state, _) = await makeState(routes: {
        'GET static-pages/privacy-policy': {'slug': 'privacy-policy', 'title': 'Privacy policy', 'body': 'Final text', 'placeholder': false},
      });
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.staticPage, {'slug': 'privacy-policy'});
      await settle(tester);
      expect(find.byKey(const ValueKey('static-page-placeholder')), findsNothing);
      expect(find.text('Final text'), findsOneWidget);
    });

    test('plain text from simple HTML', () {
      expect(plainText('<h2>Title</h2><ul><li>One</li><li>Two</li></ul>Line<br>break'), 'Title\n\n• One\n\n• Two\n\nLine\nbreak');
    });
  });

  test('deep links for wave 5b screens', () {
    expect(resolveStorefrontLink('/uae-en/account/support/TK-AE-000001')!.args, {'number': 'TK-AE-000001'});
    expect(resolveStorefrontLink('/account/support')!.route, Routes.support);
    expect(resolveStorefrontLink('/account/cards')!.route, Routes.cards);
    expect(resolveStorefrontLink('/account/followed-stores')!.route, Routes.followedStores);
    expect(resolveStorefrontLink('/seller/soundmax')!.args, {'slug': 'soundmax'});
    expect(resolveStorefrontLink('/compare')!.route, Routes.compare);
    final page = resolveStorefrontLink('/uae-ar/pages/privacy-policy')!;
    expect(page.route, Routes.staticPage);
    expect(page.args, {'slug': 'privacy-policy'});
  });

  testWidgets('paging follows meta.last_page', (tester) async {
    final (state, transport) = await makeState(token: 'tok', routes: {
      'GET support/tickets': (HttpRequestData r) {
        final page = int.parse(r.uri.queryParameters['page'] ?? '1');
        return FakeTransport.ok([for (var i = 0; i < 20; i++) {...ticket(), 'number': 'TK-$page-$i', 'subject': 'Ticket $page-$i'}],
            meta: {'total': 40, 'page': page, 'per_page': 20, 'last_page': 2});
      },
    });
    await pumpApp(tester, state);
    await pushRoute(tester, Routes.support);
    await settle(tester);
    for (var i = 0; i < 6; i++) {
      await tester.drag(find.byType(Scrollable).last, const Offset(0, -3000));
      await settle(tester, 2);
    }
    final pages = transport.sent('GET support/tickets').map((r) => r.uri.queryParameters['page']).toList();
    expect(pages, ['1', '2']);
  });
}
