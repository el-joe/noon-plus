import 'dart:convert';

import 'package:customer_app/core/api/api_client.dart' show HttpRequestData;
import 'package:customer_app/core/storage/secure_store.dart';
import 'package:customer_app/routing/guards.dart';
import 'package:customer_app/screens/catalog/product_filters.dart';
import 'package:customer_app/screens/catalog/product_list_screen.dart';
import 'package:customer_app/screens/home/home_extras.dart';
import 'package:customer_app/state/compare_list.dart';
import 'package:customer_app/state/recently_viewed.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers.dart';
import 'screens_test.dart' show productFixture;

Map<String, dynamic> card(String slug, {int? listing, List<Map<String, String>> badges = const []}) => {
      'slug': slug,
      'title': 'Product $slug',
      'price': money('100.00'),
      'image': null,
      'rating': {'average': '4.2', 'count': 5},
      'listing_id': ?listing,
      'badges': badges,
    };

Map<String, dynamic> sellerFixture({bool following = false, int followers = 41}) => {
      'slug': 's1',
      'name': 'SoundMax Official',
      'logo': null,
      'banner': null,
      'description': 'Audio gear',
      'city': 'Dubai',
      'joined_at': '2025-02-01T00:00:00+04:00',
      'rating': {'average': '4.6', 'count': 120, 'histogram': {'5': 100}, 'positive_percent': 93},
      'product_count': 2,
      'followers': followers,
      'following': following,
      'badges': [
        {'key': 'top_rated', 'label': 'Top rated'},
      ],
      'policies': {
        'returns': {'window_days': 15, 'note': null},
        'shipping': {'fee': money('10.00'), 'free_over': money('100.00'), 'fulfilled_by_nawy': true},
        'warranty': {'note': '1-year brand warranty'},
      },
      'reviews_url': null,
    };

void main() {
  setUp(() {
    CompareList.instance.clear();
    FloatingLivePlayer.dismissed = false;
  });

  group('search (QC-043)', () {
    testWidgets('trending and popular chips; suggest as you type', (tester) async {
      final (state, transport) = await makeState(routes: {
        'GET search/trending': [
          {'type': 'category', 'label': 'Phones', 'query': null, 'category': 'mobiles'},
          {'type': 'query', 'label': 'air fryer', 'query': 'air fryer', 'category': null},
        ],
        'GET search/popular': [
          {'query': 'iphone 16'},
        ],
        'GET search/suggest': {
          'queries': ['earbuds pro', 'earbuds case'],
          'products': [
            {'slug': 'soundmax-earbuds-pro', 'title': 'SoundMax Earbuds Pro', 'image': null, 'price': money('249.00')},
          ],
          'brands': [
            {'slug': 'soundmax', 'name': 'SoundMax', 'logo': null},
          ],
          'categories': [
            {'slug': 'earbuds', 'name': 'Earbuds'},
          ],
        },
      });
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.search);
      await settle(tester);
      expect(find.byKey(const ValueKey('trending-Phones')), findsOneWidget);
      expect(find.byKey(const ValueKey('popular-iphone 16')), findsOneWidget);

      await tester.enterText(find.byKey(const ValueKey('search-input')), 'earb');
      await tester.pump(const Duration(milliseconds: 350));
      await settle(tester);
      expect(transport.sent('GET search/suggest').single.uri.queryParameters['q'], 'earb');
      expect(find.byKey(const ValueKey('suggest-query-earbuds pro')), findsOneWidget);
      expect(find.byKey(const ValueKey('suggest-product-soundmax-earbuds-pro')), findsOneWidget);
      expect(find.text('AED 249.00'), findsOneWidget);
      // The old workaround (products?q= / brands?q=) is gone.
      expect(transport.sent('GET brands'), isEmpty);
    });

    testWidgets('a popular chip searches for its query', (tester) async {
      final (state, transport) = await makeState(routes: {
        'GET search/popular': [
          {'query': 'iphone 16'},
        ],
      });
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.search);
      await settle(tester);
      await tester.tap(find.byKey(const ValueKey('popular-iphone 16')));
      await settle(tester);
      expect(transport.sent('GET products').last.uri.queryParameters['q'], 'iphone 16');
    });
  });

  group('PDP (QC-057, QC-064, QC-043)', () {
    Map<String, Object> pdpRoutes() => {
          'GET products/soundmax-earbuds-pro': {
            ...productFixture(),
            'model_number': 'SM-EB200',
            'badges': [
              {'key': 'bestseller', 'label': 'Bestseller'},
            ],
          },
          'GET products/soundmax-earbuds-pro/coupons': [
            {'code': 'AUDIO10', 'label': null, 'type': 'percent', 'percent': '10.00', 'min_order': money('100.00')},
          ],
          'GET products/soundmax-earbuds-pro/frequently-bought-together': {
            'product': {'slug': 'soundmax-earbuds-pro', 'listing_id': 5},
            'items': [card('case', listing: 21), card('cable', listing: 22)],
            'bundle_total': money('449.00'),
          },
          'PUT cart/items': cartFixture(),
        };

    testWidgets('badges, model number, coupon chip copies the code', (tester) async {
      final copied = <String>[];
      tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(SystemChannels.platform, (call) async {
        if (call.method == 'Clipboard.setData') copied.add((call.arguments as Map)['text'] as String);
        return null;
      });
      addTearDown(() => tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(SystemChannels.platform, null));
      final (state, _) = await makeState(routes: pdpRoutes());
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.product, {'slug': 'soundmax-earbuds-pro'});
      await settle(tester);
      expect(find.byKey(const ValueKey('pdp-badge-bestseller')), findsOneWidget);
      expect(find.text('Model: SM-EB200'), findsOneWidget);
      expect(find.text('10% off'), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('coupon-AUDIO10')));
      await settle(tester);
      expect(copied, ['AUDIO10']);
      expect(find.text('Code AUDIO10 copied'), findsOneWidget);
    });

    testWidgets('frequently bought together: add all puts every listing in the cart', (tester) async {
      final (state, transport) = await makeState(routes: pdpRoutes());
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.product, {'slug': 'soundmax-earbuds-pro'});
      await settle(tester);
      await tester.scrollUntilVisible(find.byKey(const ValueKey('fbt-add-all')), 300, scrollable: find.byType(Scrollable).first);
      await tester.drag(find.byType(Scrollable).first, const Offset(0, -200));
      await settle(tester);
      expect(find.text('3 items · AED 449.00'), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('fbt-add-all')));
      await settle(tester);
      final listings = transport.sent('PUT cart/items').map((r) => transport.bodyOf(r)['listing_id']).toList();
      expect(listings, [5, 21, 22]);
    });

    testWidgets('compare toggle, then the compare screen', (tester) async {
      final (state, transport) = await makeState(routes: {
        ...pdpRoutes(),
        'GET products/compare': {
          'products': [
            {...card('soundmax-earbuds-pro', listing: 5), 'model_number': 'SM-EB200', 'in_stock': true, 'seller': 'SoundMax Official'},
            {...card('other', listing: 9), 'model_number': null, 'in_stock': false, 'seller': 'Other'},
          ],
          'specifications': [
            {'label': 'Battery', 'values': ['30 h', '20 h']},
          ],
        },
      });
      CompareList.instance.toggle('other');
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.product, {'slug': 'soundmax-earbuds-pro'});
      await settle(tester);
      await tester.tap(find.byKey(const ValueKey('pdp-compare')));
      await settle(tester);
      expect(CompareList.instance.value, ['other', 'soundmax-earbuds-pro']);
      await tester.tap(find.text('Compare (2)'));
      await settle(tester);
      expect(transport.sent('GET products/compare').single.uri.queryParameters['slugs'], 'other,soundmax-earbuds-pro');
      expect(find.text('Battery'), findsOneWidget);
      expect(find.text('30 h'), findsOneWidget);
      expect(find.text('SM-EB200'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('compare-remove-soundmax-earbuds-pro')));
      await settle(tester);
      expect(CompareList.instance.value, ['other']);
      expect(find.text('Pick at least 2 products'), findsOneWidget);
    });

    test('the compare list holds at most 4 products', () {
      final list = CompareList();
      for (final s in ['a', 'b', 'c', 'd']) {
        expect(list.toggle(s), isTrue);
      }
      expect(list.toggle('e'), isFalse);
      expect(list.toggle('a'), isTrue);
      expect(list.value, ['b', 'c', 'd']);
    });

    testWidgets('"Sold by" opens the seller store', (tester) async {
      final (state, _) = await makeState(routes: {...pdpRoutes(), 'GET sellers/s1': sellerFixture()});
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.product, {'slug': 'soundmax-earbuds-pro'});
      await settle(tester);
      await tester.scrollUntilVisible(find.byKey(const ValueKey('pdp-visit-store')), 300, scrollable: find.byType(Scrollable).first);
      await tester.tap(find.byKey(const ValueKey('pdp-visit-store')));
      await settle(tester);
      expect(find.text('41 followers'), findsOneWidget);
    });
  });

  group('seller store (QC-008)', () {
    testWidgets('header, badges, policies, products; follow updates followers', (tester) async {
      final (state, transport) = await makeState(token: 'tok', routes: {
        'GET sellers/s1': sellerFixture(),
        'GET sellers/s1/products': (HttpRequestData r) => FakeTransport.ok([card('a'), card('b')], meta: {'total': 2, 'page': 1, 'per_page': 20, 'last_page': 1}),
        'POST sellers/s1/follow': {'following': true, 'followers': 42},
        'DELETE sellers/s1/follow': {'following': false, 'followers': 41},
      });
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.seller, {'slug': 's1'});
      await settle(tester);
      expect(find.text('SoundMax Official'), findsWidgets);
      expect(find.byKey(const ValueKey('seller-badge-top_rated')), findsOneWidget);
      expect(find.text('93% positive'), findsOneWidget);
      expect(find.text('Returns within 15 days'), findsOneWidget);
      expect(find.text('Free delivery over AED 100.00'), findsOneWidget);
      expect(find.text('Product a'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('seller-follow')));
      await settle(tester);
      expect(transport.sent('POST sellers/s1/follow'), hasLength(1));
      expect(find.text('42 followers'), findsOneWidget);
      expect(find.text('Following'), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('seller-follow')));
      await settle(tester);
      expect(transport.sent('DELETE sellers/s1/follow'), hasLength(1));
      expect(find.text('41 followers'), findsOneWidget);

      // "Filter" opens the PLP over the store's own listing.
      await tester.tap(find.byKey(const ValueKey('seller-filter')));
      await settle(tester);
      expect(transport.sent('GET sellers/s1/products/facets'), hasLength(1));
    });

    testWidgets('a guest is asked to sign in before following', (tester) async {
      final (state, transport) = await makeState(routes: {'GET sellers/s1': sellerFixture()});
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.seller, {'slug': 's1'});
      await settle(tester);
      await tester.tap(find.byKey(const ValueKey('seller-follow')));
      await settle(tester);
      expect(transport.sent('POST sellers/s1/follow'), isEmpty);
      expect(find.byKey(const ValueKey('sign-in-email')), findsOneWidget);
    });

    testWidgets('followed stores list opens a store', (tester) async {
      final (state, _) = await makeState(token: 'tok', routes: {
        'GET me/followed-sellers': (HttpRequestData r) => FakeTransport.ok([
              {'slug': 's1', 'name': 'SoundMax Official', 'logo': null, 'rating': {'average': '4.6', 'count': 120}, 'followed_at': '2026-11-10T10:00:00+04:00'},
            ], meta: {'total': 1, 'page': 1, 'per_page': 20, 'last_page': 1}),
        'GET sellers/s1': sellerFixture(following: true),
      });
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.followedStores);
      await settle(tester);
      expect(find.text('Followed stores'), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('followed-s1')));
      await settle(tester);
      expect(find.text('Following'), findsOneWidget);
    });
  });

  group('PLP (QC-027)', () {
    Map<String, Object> plpRoutes() => {
          'GET products': (HttpRequestData r) => FakeTransport.ok([card('a')], meta: {'total': 1, 'page': 1, 'last_page': 1, 'per_page': 20}),
          'GET products/facets': {
            'brands': [],
            'sellers': [],
            'attributes': [],
            'fulfilment': [
              {'value': 'fbn', 'count': 3},
              {'value': 'fbm', 'count': 5},
            ],
            'conditions': [
              {'value': 'new', 'count': 7},
              {'value': 'refurbished', 'count': 1},
            ],
            'deals': 2,
          },
        };

    testWidgets('quick chips send deals and fulfilment', (tester) async {
      final (state, transport) = await makeState(routes: plpRoutes());
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.products, {'q': 'phone'});
      await settle(tester);
      await tester.ensureVisible(find.byKey(const ValueKey('quick-deals')));
      await settle(tester);
      await tester.tap(find.byKey(const ValueKey('quick-deals')));
      await settle(tester);
      expect(transport.sent('GET products').last.uri.queryParameters['deals'], '1');
      await tester.ensureVisible(find.byKey(const ValueKey('quick-fbn')));
      await settle(tester);
      await tester.tap(find.byKey(const ValueKey('quick-fbn')));
      await settle(tester);
      expect(transport.sent('GET products').last.uri.queryParameters['fulfilment'], 'fbn');
      expect(transport.sent('GET products/facets').last.uri.queryParameters['deals'], '1');
    });

    testWidgets('filter sheet: Fulfilled by and Condition with counts', (tester) async {
      final (state, transport) = await makeState(routes: plpRoutes());
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.products, {'q': 'phone'});
      await settle(tester);
      await tester.tap(find.byKey(const ValueKey('chip-filter')));
      await settle(tester);
      await tester.tap(find.byKey(const ValueKey('facet-condition')));
      await settle(tester);
      expect(find.text('Refurbished'), findsWidgets);
      await tester.tap(find.byKey(const ValueKey('facet-condition-refurbished')));
      await settle(tester);
      await tester.tap(find.byKey(const ValueKey('filters-apply')));
      await settle(tester);
      expect(transport.sent('GET products').last.uri.queryParameters['condition'], 'refurbished');
    });

    test('filters query and quick chips', () {
      final f = ProductFilters(q: 'tv')
        ..deals = true
        ..fulfilment.addAll({'fbn', 'fbm'})
        ..conditions.add('new');
      final q = f.query(page: 1, forFacets: true);
      expect(q['fulfilment'], 'fbm,fbn');
      expect(q['condition'], 'new');
      expect(q['deals'], 1);
      expect(f.activeCount, 4);
      f.clear();
      expect(f.query().containsKey('deals'), isFalse);
      expect(ProductFilters.fromArgs({'deals': '1', 'fulfilment': 'fbn'}).query()['fulfilment'], 'fbn');

      // Without matching facets, deals / FBN chips hide; condition chips need both values.
      final chips = quickChips({'deals': 0, 'fulfilment': [], 'conditions': [
        {'value': 'new', 'count': 3},
      ]});
      expect(chips.map((c) => c.key), ['rating4']);
      expect(quickChips(null).map((c) => c.key), ['deals', 'fbn', 'rating4']);
    });
  });

  group('home (QC-064, QC-063)', () {
    testWidgets('Offers for you and the floating player entry', (tester) async {
      final (state, _) = await makeState(routes: {
        'GET offers': {
          'coupons': [
            {'code': 'WELCOME', 'label': 'AED 20 off your first order', 'type': 'fixed', 'amount': money('20.00')},
          ],
          'flash_sales': [
            {'id': 3, 'slug': 'white-friday', 'name': 'White Friday', 'state': 'live', 'starts_at': '2026-11-18T00:00:00Z', 'ends_at': '2026-11-20T00:00:00Z', 'deals_count': 12},
          ],
        },
        'GET live-streams/floating': {'slug': 'tech-live', 'title': 'Tech deals live', 'status': 'live', 'thumbnail_url': null, 'viewers': 37},
      });
      await pumpApp(tester, state);
      await settle(tester);
      expect(find.byKey(const ValueKey('home-offers')), findsOneWidget);
      expect(find.text('AED 20 off your first order'), findsOneWidget);
      expect(find.text('White Friday · 12 deals live'), findsOneWidget);
      expect(find.byKey(const ValueKey('floating-player')), findsOneWidget);
      expect(find.textContaining('37 watching'), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('floating-player-close')));
      await settle(tester);
      expect(find.byKey(const ValueKey('floating-player')), findsNothing);
    });

    testWidgets('no floating stream → no player', (tester) async {
      final (state, _) = await makeState(routes: {'GET live-streams/floating': FakeTransport.ok(null)});
      await pumpApp(tester, state);
      await settle(tester);
      expect(find.byKey(const ValueKey('floating-player')), findsNothing);
    });
  });

  group('recently viewed (QC-043)', () {
    testWidgets('sign-in merges the device list and stores the account list', (tester) async {
      final (state, transport) = await makeState(
        token: 'tok',
        routes: {
          'POST me/recently-viewed': [
            {...card('a'), 'viewed_at': '2026-11-18T08:00:00Z'},
            {...card('z'), 'viewed_at': '2026-11-17T08:00:00Z'},
          ],
        },
        store: {
          StoreKeys.recentlyViewed: jsonEncode([
            {'slug': 'a', 'title': 'Product a', 'viewed_at': '2026-11-18T08:00:00Z'},
          ]),
        },
      );
      await syncRecentlyViewed(state);
      expect(transport.bodyOf(transport.sent('POST me/recently-viewed').single), {
        'items': [
          {'slug': 'a', 'viewed_at': '2026-11-18T08:00:00Z'},
        ],
      });
      final local = await state.readList(StoreKeys.recentlyViewed);
      expect(local.map((e) => e['slug']), ['a', 'z']);
    });

    testWidgets('signed in: a product view is sent to the account; the list comes from the API', (tester) async {
      final (state, transport) = await makeState(token: 'tok', routes: {
        'GET products/soundmax-earbuds-pro': productFixture(),
        'GET me/recently-viewed': [card('from-api')],
        'POST me/recently-viewed': const <Object>[],
      });
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.product, {'slug': 'soundmax-earbuds-pro'});
      await settle(tester);
      expect(transport.bodyOf(transport.sent('POST me/recently-viewed').single)['items'][0]['slug'], 'soundmax-earbuds-pro');
      final cards = await loadRecentlyViewed(state);
      expect(cards.single['slug'], 'from-api');
    });

    testWidgets('guests keep the device list only', (tester) async {
      final (state, transport) = await makeState(store: {
        StoreKeys.recentlyViewed: jsonEncode([
          {'slug': 'local'},
        ]),
      });
      final cards = await loadRecentlyViewed(state);
      expect(cards.single['slug'], 'local');
      expect(transport.sent('GET me/recently-viewed'), isEmpty);
      await syncRecentlyViewed(state);
      expect(transport.sent('POST me/recently-viewed'), isEmpty);
    });
  });

}
