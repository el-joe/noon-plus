import 'package:customer_app/core/api/api_client.dart';
import 'package:customer_app/routing/guards.dart';
import 'package:customer_app/screens/cart/free_delivery_bar.dart';
import 'package:customer_app/screens/live/live_chat.dart';
import 'package:customer_app/screens/travel/travel_screens.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers.dart';

Map<String, dynamic> packageFixture({bool childPrice = true}) => {
      'slug': 'umrah',
      'title': 'Umrah 7 nights',
      'agency': 'Gulf Holidays',
      'image': {'url': 'https://cdn.test/cover.jpg'},
      'gallery': [
        {'url': 'https://cdn.test/1.jpg', 'alt': 'Haram'},
        {'url': 'https://cdn.test/2.jpg', 'alt': 'Hotel'},
        {'url': 'https://cdn.test/3.jpg', 'alt': 'Bus'},
      ],
      'stats': {'rating': null, 'travellers': 1240, 'bookings': 310, 'agency_trips': 12},
      'departures': [
        {
          'id': 1,
          'departs_on': '2026-12-15',
          'returns_on': '2026-12-22',
          'seats_left': 20,
          'status': 'open',
          'child_price_per_person': childPrice ? money('800.00') : null,
          'rooms': [
            {'type': 'double', 'price_per_person': money('2000.00')},
            if (childPrice) {'type': 'child', 'price_per_person': money('800.00')},
          ],
        },
      ],
    };

/// The request screen's own list (TextFields add inner Scrollables).
Finder requestList() => find.descendant(of: find.byKey(const ValueKey('travel-request-list')), matching: find.byType(Scrollable)).first;

/// Remove the screen so polling timers are cancelled before the test ends.
Future<void> unmount(WidgetTester tester) async {
  await tester.pumpWidget(const SizedBox());
  await tester.pump();
}

void main() {
  group('cart free-delivery bar (G-A5)', () {
    test('reads the API value; null hides it', () {
      expect(FreeDeliveryBar.read(null), isNull);
      final spend = FreeDeliveryBar.read({'threshold': money('100.00'), 'remaining': money('35.50'), 'progress_percent': 64, 'qualified': false})!;
      expect(spend.qualified, isFalse);
      expect(spend.remaining!.amount, '35.50');
      expect(spend.progress, closeTo(0.64, 0.001));
      final done = FreeDeliveryBar.read({'threshold': money('100.00'), 'remaining': money('0.00'), 'progress_percent': 100, 'qualified': true})!;
      expect(done.qualified, isTrue);
      expect(done.progress, 1.0);
    });

    testWidgets('shows "spend X more" with progress, and nothing without a threshold', (tester) async {
      final (state, _) = await makeState(routes: {
        'GET cart': {
          ...cartFixture(qty: 1),
          'free_delivery': {'threshold': money('300.00'), 'remaining': money('51.00'), 'progress_percent': 83, 'qualified': false},
        },
      });
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.cart);
      await settle(tester);
      expect(find.text('Spend AED 51.00 more for free delivery'), findsOneWidget);
      final bar = tester.widget<LinearProgressIndicator>(find.byKey(const ValueKey('cart-free-delivery-progress')));
      expect(bar.value, closeTo(0.83, 0.001));
    });

    testWidgets('qualified cart says so', (tester) async {
      final (state, _) = await makeState(routes: {
        'GET cart': {
          ...cartFixture(qty: 2),
          'free_delivery': {'threshold': money('300.00'), 'remaining': money('0.00'), 'progress_percent': 100, 'qualified': true},
        },
      });
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.cart);
      await settle(tester);
      expect(find.text('You qualified for free delivery'), findsOneWidget);
    });

    testWidgets('no free_delivery (market without a threshold) → no bar', (tester) async {
      final (state, _) = await makeState(routes: {'GET cart': {...cartFixture(qty: 1), 'free_delivery': null}});
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.cart);
      await settle(tester);
      expect(find.byKey(const ValueKey('cart-free-delivery')), findsNothing);
    });
  });

  group('live comments and viewers (QC-040)', () {
    Map<String, dynamic> stream({String status = 'live'}) => {
          'slug': 'demo',
          'title': 'Demo live',
          'status': status,
          'likes': 4,
          'viewers': 12,
          'playback': {'type': 'live', 'hls_url': 'https://cdn.test/live.m3u8'},
          'products': [],
        };

    testWidgets('loads comments, polls with after_id, heartbeats and shows viewers', (tester) async {
      var polls = 0;
      final (state, transport) = await makeState(routes: {
        'GET live-streams/demo': stream(),
        'GET live-streams/demo/comments': (HttpRequestData r) {
          polls++;
          return polls == 1
              ? FakeTransport.ok([
                  {'id': 7, 'author': 'Mona', 'body': 'Love it', 'created_at': '2026-11-18T08:00:00Z'},
                ], meta: {'viewers': 15, 'likes': 9})
              : FakeTransport.ok([
                  {'id': 8, 'author': 'Ali', 'body': 'Price?', 'created_at': '2026-11-18T08:00:05Z'},
                ], meta: {'viewers': 18, 'likes': 9});
        },
        'POST live-streams/demo/presence': {'viewers': 16},
      });
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.liveWatch, {'slug': 'demo'});
      await settle(tester);
      expect(find.textContaining('Love it', findRichText: true), findsOneWidget);
      final presence = transport.sent('POST live-streams/demo/presence');
      expect(presence, isNotEmpty);
      expect(transport.bodyOf(presence.first)['viewer_id'], LiveViewer.id);
      expect(find.byKey(const ValueKey('live-viewers')), findsOneWidget);

      await tester.pump(LiveTimings.comments);
      await settle(tester);
      final second = transport.sent('GET live-streams/demo/comments').last;
      expect(second.uri.queryParameters['after_id'], '7');
      expect(find.textContaining('Price?', findRichText: true), findsOneWidget);
      expect(find.text('18'), findsOneWidget);
      await unmount(tester);
    });

    testWidgets('a held comment is shown to its author only, marked for review', (tester) async {
      final (state, transport) = await makeState(token: 'tok', routes: {
        'GET live-streams/demo': stream(),
        'GET live-streams/demo/comments': FakeTransport.ok(const [], meta: {'viewers': 3}),
        'POST live-streams/demo/presence': {'viewers': 3},
        'POST live-streams/demo/comments': FakeTransport.ok({'id': 30, 'author': 'Sara', 'body': 'see my link', 'created_at': null, 'status': 'held'}, status: 201),
      });
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.liveWatch, {'slug': 'demo'});
      await settle(tester);
      await tester.enterText(find.byKey(const ValueKey('live-comment-input')), 'see my link');
      await tester.tap(find.byKey(const ValueKey('live-comment-send')));
      await settle(tester);
      expect(transport.bodyOf(transport.sent('POST live-streams/demo/comments').single), {'body': 'see my link'});
      expect(find.byKey(const ValueKey('held-30')), findsOneWidget);
      expect(find.text('Waiting for review: only you can see this'), findsOneWidget);
      await unmount(tester);
    });

    testWidgets('posting after the stream ended shows the API refusal', (tester) async {
      final (state, _) = await makeState(token: 'tok', routes: {
        'GET live-streams/demo': stream(),
        'GET live-streams/demo/comments': FakeTransport.ok(const []),
        'POST live-streams/demo/presence': {'viewers': 0},
        'POST live-streams/demo/comments': FakeTransport.error(422, 'not_allowed', 'Comments are open only while the stream is live.'),
      });
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.liveWatch, {'slug': 'demo'});
      await settle(tester);
      await tester.enterText(find.byKey(const ValueKey('live-comment-input')), 'hello');
      await tester.tap(find.byKey(const ValueKey('live-comment-send')));
      await settle(tester);
      expect(find.text('Comments are open only while the stream is live.'), findsOneWidget);
      await unmount(tester);
    });

    testWidgets('a guest is sent to sign in before commenting', (tester) async {
      final (state, transport) = await makeState(routes: {
        'GET live-streams/demo': stream(),
        'GET live-streams/demo/comments': FakeTransport.ok(const []),
        'POST live-streams/demo/presence': {'viewers': 1},
      });
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.liveWatch, {'slug': 'demo'});
      await settle(tester);
      await tester.enterText(find.byKey(const ValueKey('live-comment-input')), 'hi');
      await tester.tap(find.byKey(const ValueKey('live-comment-send')));
      await settle(tester);
      expect(find.byKey(const ValueKey('sign-in-email')), findsOneWidget);
      expect(transport.sent('POST live-streams/demo/comments'), isEmpty);
      await unmount(tester);
    });

    testWidgets('an ended stream has no comment box and no heartbeat', (tester) async {
      final (state, transport) = await makeState(routes: {
        'GET live-streams/demo': stream(status: 'ended'),
        'GET live-streams/demo/comments': FakeTransport.ok(const []),
      });
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.liveWatch, {'slug': 'demo'});
      await settle(tester);
      expect(find.byKey(const ValueKey('live-comment-input')), findsNothing);
      expect(find.text('No comments on this stream.'), findsOneWidget);
      expect(transport.sent('POST live-streams/demo/presence'), isEmpty);
      await unmount(tester);
    });

    testWidgets('the live list shows viewers on live streams', (tester) async {
      final (state, _) = await makeState(routes: {
        'GET live-streams': [
          {'slug': 'demo', 'title': 'Demo live', 'status': 'live', 'viewers': 1500},
          {'slug': 'old', 'title': 'Old show', 'status': 'ended', 'viewers': 0},
        ],
      });
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.live);
      await settle(tester);
      expect(find.byKey(const ValueKey('live-viewers-demo')), findsOneWidget);
      expect(find.text('1.5K'), findsOneWidget);
      expect(find.byKey(const ValueKey('live-viewers-old')), findsNothing);
    });
  });

  group('travel (QC-060, QA-037)', () {
    test('estimate adds children at the child price; adult rooms exclude `child`', () {
      final departure = (packageFixture()['departures'] as List).first as Map<String, dynamic>;
      expect(adultRooms(departure).map((r) => r['type']), ['double']);
      expect(childPriceOf(departure)!.amount, '800.00');
      expect(travelEstimate(departure, {'double': 2}, children: 1)!.amount, '4800.00');
      final noKids = (packageFixture(childPrice: false)['departures'] as List).first as Map<String, dynamic>;
      expect(childPriceOf(noKids), isNull);
      expect(travelEstimate(noKids, {'double': 2}, children: 3)!.amount, '4000.00');
    });

    testWidgets('trip detail: 1+2 gallery, stats line, child price; the viewer pages photos', (tester) async {
      final (state, _) = await makeState(routes: {'GET travel/packages/umrah': packageFixture()});
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.travelPackage, {'slug': 'umrah'});
      await settle(tester);
      expect(find.byKey(const ValueKey('trip-gallery-0')), findsOneWidget);
      expect(find.byKey(const ValueKey('trip-gallery-2')), findsOneWidget);
      expect(find.text('+1'), findsOneWidget);
      expect(find.text('1.2K travellers · 310 bookings · 12 trips by this agency'), findsOneWidget);
      expect(find.textContaining('Child AED 800.00'), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('trip-gallery-1')));
      await settle(tester);
      expect(find.text('2 / 4'), findsOneWidget);
    });

    testWidgets('request sends children and shows the child line in the estimate', (tester) async {
      final (state, transport) = await makeState(routes: {
        'POST travel/departures/1/bookings': FakeTransport.ok({'number': 'TB-AE-10', 'status': 'requested', 'adults': 1, 'children': 2}, status: 201),
      });
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.travelRequest, {'package': packageFixture(), 'departure': 1});
      await tester.enterText(find.byKey(const ValueKey('travel-name')), 'Sara');
      await tester.enterText(find.byKey(const ValueKey('travel-email')), 'sara@example.com');
      await tester.enterText(find.byKey(const ValueKey('travel-phone')), '+971501234567');
      expect(find.byKey(const ValueKey('room-child')), findsNothing);
      await tester.scrollUntilVisible(find.byKey(const ValueKey('travel-children')), 200, scrollable: requestList());
      await tester.ensureVisible(find.byKey(const ValueKey('travel-children')));
      await tester.pumpAndSettle();
      for (var i = 0; i < 2; i++) {
        await tester.tap(find.descendant(of: find.byKey(const ValueKey('travel-children')), matching: find.byIcon(Icons.add)));
        await tester.pump();
      }
      await tester.scrollUntilVisible(find.text('2 × Child'), 200, scrollable: requestList());
      expect(find.text('2 × Child'), findsOneWidget);
      expect(find.text('AED 3,600.00'), findsOneWidget);
      await tester.scrollUntilVisible(find.byKey(const ValueKey('travel-agree')), 300, scrollable: requestList());
      await tester.tap(find.byKey(const ValueKey('travel-agree')));
      await tester.pump();
      await tester.scrollUntilVisible(find.byKey(const ValueKey('travel-send')), 300, scrollable: requestList());
      await tester.tap(find.byKey(const ValueKey('travel-send')));
      await settle(tester);
      final body = transport.bodyOf(transport.sent('POST travel/departures/1/bookings').single);
      expect(body['rooms'], [
        {'room_type': 'double', 'travellers': 1},
      ]);
      expect(body['children'], 2);
    });

    testWidgets('no child price → no children stepper, nothing sent; 422 children_not_offered is shown', (tester) async {
      final (state, transport) = await makeState(routes: {
        'POST travel/departures/1/bookings': FakeTransport.error(422, 'children_not_offered', 'This departure does not take children.'),
      });
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.travelRequest, {'package': packageFixture(childPrice: false), 'departure': 1});
      await tester.enterText(find.byKey(const ValueKey('travel-name')), 'Sara');
      await tester.enterText(find.byKey(const ValueKey('travel-email')), 'sara@example.com');
      await tester.enterText(find.byKey(const ValueKey('travel-phone')), '+971501234567');
      expect(find.byKey(const ValueKey('travel-children')), findsNothing);
      await tester.scrollUntilVisible(find.byKey(const ValueKey('travel-agree')), 300, scrollable: requestList());
      await tester.tap(find.byKey(const ValueKey('travel-agree')));
      await tester.pump();
      await tester.scrollUntilVisible(find.byKey(const ValueKey('travel-send')), 300, scrollable: requestList());
      await tester.tap(find.byKey(const ValueKey('travel-send')));
      await settle(tester);
      expect(transport.bodyOf(transport.sent('POST travel/departures/1/bookings').single).containsKey('children'), isFalse);
      expect(find.text('This departure does not take children.'), findsWidgets);
    });

    testWidgets('booking detail: room lines with money objects and the adults / children split', (tester) async {
      final (state, _) = await makeState(token: 'tok', routes: {
        'GET travel/bookings/TB-AE-10': {
          'number': 'TB-AE-10',
          'status': 'confirmed',
          'package': {'slug': 'umrah', 'title': 'Umrah 7 nights'},
          'departs_on': '2026-12-15',
          'returns_on': '2026-12-22',
          'seats': 3,
          'adults': 1,
          'children': 2,
          'rooms': [
            {'room_type': 'double', 'travellers': 1, 'unit_price': money('2000.00'), 'line_total': money('2000.00')},
            {'room_type': 'child', 'travellers': 2, 'unit_price': money('800.00'), 'line_total': money('1600.00')},
          ],
          'quoted_total': money('3600.00'),
        },
      });
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.travelBooking, {'number': 'TB-AE-10'});
      await settle(tester);
      expect(find.text('1 × AED 2,000.00 = AED 2,000.00'), findsOneWidget);
      expect(find.text('2 × AED 800.00 = AED 1,600.00'), findsOneWidget);
      expect(find.text('1 adults · 2 children'), findsOneWidget);
    });
  });
}
