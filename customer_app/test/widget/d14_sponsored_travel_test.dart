import 'package:customer_app/features/page_builder/vertical_cards.dart';
import 'package:customer_app/routing/guards.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers.dart';

/// D14 "Travel: free listing, paid placement": promoted trips come first from
/// the server with `sponsored` + a localized `label`; the app labels them and
/// keeps the server order.
Map<String, dynamic> trip(String slug, {bool? sponsored, String? label, String price = '1500.00'}) => {
      'slug': slug,
      'title': 'Trip $slug',
      'destination': 'Makkah',
      'nights': 5,
      'agency': 'Gulf Holidays',
      'from_price': money(price),
      'sponsored': ?sponsored,
      'label': ?label,
    };

/// Server order: two sponsored (one with an empty label), then organic ones
/// that are deliberately not sorted by price or title.
final serverList = [
  trip('zeta', sponsored: true, label: 'Promoted', price: '3000.00'),
  trip('alpha', sponsored: true, label: '', price: '2500.00'),
  trip('mid', sponsored: false, price: '900.00'),
  trip('beta', price: '1200.00'),
];

Map<String, dynamic> detail({bool? sponsored, String? label, List<Map<String, dynamic>>? related}) => {
      ...trip('umrah', sponsored: sponsored, label: label),
      'title': 'Umrah 7 nights',
      'departures': [
        {'id': 1, 'departs_on': '2026-12-15', 'returns_on': '2026-12-22', 'seats_left': 20, 'status': 'open', 'rooms': [{'type': 'double', 'price_per_person': money('2000.00')}]},
      ],
      'related': ?related,
    };

List<String> cardSlugs(WidgetTester tester) => tester.widgetList<TravelCard>(find.byType(TravelCard)).map((c) => c.package['slug'] as String).toList();

void main() {
  test('old payloads without the fields are not sponsored', () {
    expect(isSponsoredTrip(trip('old')), isFalse);
    expect(isSponsoredTrip({'slug': 'x', 'sponsored': null}), isFalse);
    expect(isSponsoredTrip(trip('new', sponsored: true)), isTrue);
  });

  testWidgets('travel list labels sponsored trips and keeps the server order', (tester) async {
    final (state, _) = await makeState(routes: {'GET travel/packages': FakeTransport.ok(serverList, meta: {'sponsored': 2})});
    await pumpApp(tester, state);
    await pushRoute(tester, Routes.travel);
    await settle(tester);

    expect(cardSlugs(tester), ['zeta', 'alpha', 'mid', 'beta']);
    expect(find.descendant(of: find.byKey(const ValueKey('trip-sponsored-zeta')), matching: find.text('Promoted')), findsOneWidget);
    // Empty server label → our own "Sponsored".
    expect(find.descendant(of: find.byKey(const ValueKey('trip-sponsored-alpha')), matching: find.text('Sponsored')), findsOneWidget);
    expect(find.byKey(const ValueKey('trip-sponsored-mid')), findsNothing);
    expect(find.byKey(const ValueKey('trip-sponsored-beta')), findsNothing);
  });

  testWidgets('a search keeps the server order too', (tester) async {
    final (state, transport) = await makeState(routes: {'GET travel/packages': FakeTransport.ok(serverList.reversed.toList())});
    await pumpApp(tester, state);
    await pushRoute(tester, Routes.travel, {'destination': 'Makkah'});
    await settle(tester);
    expect(transport.requests.last.uri.queryParameters['destination'], 'Makkah');
    expect(cardSlugs(tester), ['beta', 'mid', 'alpha', 'zeta']);
  });

  testWidgets('old list payloads without sponsored / label still render, unlabelled', (tester) async {
    final (state, _) = await makeState(routes: {'GET travel/packages': [trip('one'), trip('two')]});
    await pumpApp(tester, state);
    await pushRoute(tester, Routes.travel);
    await settle(tester);
    expect(cardSlugs(tester), ['one', 'two']);
    expect(find.text('Sponsored'), findsNothing);
  });

  testWidgets('trip detail: sponsored label and a related row in server order', (tester) async {
    final (state, _) = await makeState(routes: {
      'GET travel/packages/umrah': detail(sponsored: true, label: 'Sponsored', related: [trip('rel-sp', sponsored: true, label: 'Sponsored'), trip('rel-1', sponsored: false), trip('rel-2')]),
    });
    await pumpApp(tester, state);
    await pushRoute(tester, Routes.travelPackage, {'slug': 'umrah'});
    await settle(tester);

    expect(find.descendant(of: find.byKey(const ValueKey('trip-sponsored')), matching: find.text('Sponsored')), findsOneWidget);
    await tester.scrollUntilVisible(find.byKey(const ValueKey('trip-related')), 300, scrollable: find.byType(Scrollable).first);
    await settle(tester, 2);
    expect(find.text('You may also like'), findsOneWidget);
    expect(tester.widget<TravelRail>(find.byType(TravelRail)).packages.map((p) => p['slug']), ['rel-sp', 'rel-1', 'rel-2']);
    expect(cardSlugs(tester).take(2), ['rel-sp', 'rel-1']);
    expect(find.byKey(const ValueKey('trip-sponsored-rel-sp')), findsOneWidget);
    expect(find.byKey(const ValueKey('trip-sponsored-rel-1')), findsNothing);
  });

  testWidgets('old detail payload: no label, no related row', (tester) async {
    final (state, _) = await makeState(routes: {'GET travel/packages/umrah': detail()});
    await pumpApp(tester, state);
    await pushRoute(tester, Routes.travelPackage, {'slug': 'umrah'});
    await settle(tester);
    expect(find.text('Umrah 7 nights'), findsOneWidget);
    expect(find.byKey(const ValueKey('trip-sponsored')), findsNothing);
    expect(find.byKey(const ValueKey('trip-related')), findsNothing);
  });

  testWidgets('page-builder travel carousel labels sponsored trips in server order', (tester) async {
    final (state, _) = await makeState(routes: {
      'GET pages/home': {
        'slug': 'home',
        'template': 'home',
        'zones': {
          'main': [
            {'type': 'travel_packages_carousel', 'settings': {'title_en': 'Trips', 'title_ar': 'رحلات'}},
          ],
        },
      },
      'GET travel/packages': FakeTransport.ok(serverList, meta: {'sponsored': 2}),
    });
    await pumpApp(tester, state);
    await settle(tester);

    expect(find.text('Trips'), findsOneWidget);
    expect(tester.widget<TravelRail>(find.byType(TravelRail)).packages.map((p) => p['slug']), ['zeta', 'alpha', 'mid', 'beta']);
    expect(cardSlugs(tester).take(2), ['zeta', 'alpha']);
    expect(find.descendant(of: find.byKey(const ValueKey('trip-sponsored-zeta')), matching: find.text('Promoted')), findsOneWidget);
    expect(find.byKey(const ValueKey('trip-sponsored-mid')), findsNothing);
  });

  testWidgets('Arabic falls back to مُموَّل without a server label', (tester) async {
    final (state, _) = await makeState(market: 'uae-ar', routes: {'GET travel/packages': [trip('ar', sponsored: true)]});
    await pumpApp(tester, state);
    await pushRoute(tester, Routes.travel);
    await settle(tester);
    expect(find.descendant(of: find.byKey(const ValueKey('trip-sponsored-ar')), matching: find.text('مُموَّل')), findsOneWidget);
  });
}
