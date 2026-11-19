import 'dart:convert';

import 'package:customer_app/core/api/api_client.dart';
import 'package:customer_app/core/platform/platform_bridge.dart';
import 'package:customer_app/routing/guards.dart';
import 'package:customer_app/screens/souq/listing_map.dart';
import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nawy_core/device.dart' show MediaPicker;
import 'package:nawy_core/testing.dart' show FakeMediaPicker, fakePdfFile, fakePhoto;

import '../helpers.dart';

/// `GET classifieds/map` answers plain GeoJSON (no `{data}` envelope).
HttpResponseData geoJson(List<Map<String, dynamic>> pins) => HttpResponseData(
    200,
    jsonEncode({
      'type': 'FeatureCollection',
      'features': [
        for (final p in pins)
          {
            'type': 'Feature',
            'geometry': {
              'type': 'Point',
              'coordinates': [p['lng'], p['lat']],
            },
            'properties': {'number': p['number'], 'title': p['title'], 'price': p['price'], 'currency': 'AED', 'featured': p['featured'] ?? false},
          },
      ],
    }));

void main() {
  final opened = <String>[];
  late MediaPicker realPicker;
  setUp(() {
    opened.clear();
    realPicker = MediaPicker.current;
    PlatformBridge.debugOpenUrl = (url) async {
      opened.add(url);
      return true;
    };
  });
  tearDown(() {
    MediaPicker.current = realPicker;
    PlatformBridge.debugOpenUrl = null;
  });

  group('property map', () {
    testWidgets('list ↔ map toggle; price pins; tap → card → ad detail; attribution shown', (tester) async {
      final (state, transport) = await makeState(routes: {
        'GET classifieds/map': geoJson([
          {'number': 'CL-1', 'title': 'Marina 2BR', 'price': '1250000.00', 'lat': 25.08, 'lng': 55.14, 'featured': true},
          {'number': 'CL-2', 'title': 'Downtown studio', 'price': '85000.00', 'lat': 25.19, 'lng': 55.27},
        ]),
        'GET classifieds/ads/CL-1': {'number': 'CL-1', 'title': 'Marina 2BR', 'status': 'active', 'media': [], 'seller': {}, 'similar': []},
      });
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.property);
      await settle(tester);
      expect(find.byType(FlutterMap), findsNothing);

      await tester.tap(find.byKey(const ValueKey('souq-view-toggle')));
      await settle(tester);
      final request = transport.sent('GET classifieds/map').single;
      expect(request.uri.queryParameters['vertical'], 'property');
      expect(find.byType(FlutterMap), findsOneWidget);
      expect(find.text('AED 1.3M'), findsOneWidget);
      expect(find.text('AED 85K'), findsOneWidget);
      expect(find.text('2 on the map'), findsOneWidget);
      expect(find.text('© OpenStreetMap contributors'), findsOneWidget);
      expect(tester.widget<TileLayer>(find.byType(TileLayer)).urlTemplate, MapConfig.tileUrl);

      await tester.tap(find.text('AED 1.3M'));
      await settle(tester);
      expect(find.byKey(const ValueKey('map-listing-card')), findsOneWidget);
      expect(find.text('Marina 2BR'), findsOneWidget);
      expect(find.text('AED 1,250,000.00'), findsOneWidget);
      expect(find.text('Featured'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('map-listing-open')));
      await settle(tester);
      expect(transport.sent('GET classifieds/ads/CL-1'), hasLength(1));

      // Back on the map, the list toggle returns to the list.
      Navigator.of(tester.element(find.byType(Scaffold).last)).pop();
      await settle(tester, 10);
      await tester.tap(find.byKey(const ValueKey('souq-view-toggle')));
      await settle(tester);
      expect(find.byType(FlutterMap), findsNothing);
    });

    testWidgets('the attribution opens the tile copyright page', (tester) async {
      final (state, _) = await makeState(routes: {
        'GET classifieds/map': geoJson([
          {'number': 'CL-1', 'title': 'Flat', 'price': '950.00', 'lat': 25.08, 'lng': 55.14},
        ]),
      });
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.property, {'view': 'map'});
      await settle(tester);
      expect(find.text('AED 950'), findsOneWidget);
      await tester.tap(find.text('© OpenStreetMap contributors'));
      await settle(tester);
      expect(opened, [MapConfig.attributionUrl]);
    });

    testWidgets('moving the map offers "Search this area", which sends bounds', (tester) async {
      final (state, transport) = await makeState(routes: {
        'GET classifieds/map': geoJson([
          {'number': 'CL-1', 'title': 'A', 'price': '1000.00', 'lat': 25.08, 'lng': 55.14},
          {'number': 'CL-2', 'title': 'B', 'price': '2000.00', 'lat': 25.19, 'lng': 55.27},
        ]),
      });
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.property, {'view': 'map'});
      await settle(tester);
      expect(find.byKey(const ValueKey('map-search-area')), findsNothing);
      await tester.drag(find.byType(FlutterMap), const Offset(-120, 40));
      await settle(tester);
      await tester.tap(find.byKey(const ValueKey('map-search-area')));
      await settle(tester);
      final requests = transport.sent('GET classifieds/map');
      expect(requests, hasLength(2));
      expect(requests.first.uri.queryParameters.containsKey('bounds'), isFalse);
      expect(requests.last.uri.queryParameters['bounds'], matches(RegExp(r'^-?\d+\.\d{6},-?\d+\.\d{6},-?\d+\.\d{6},-?\d+\.\d{6}$')));
    });

    testWidgets('no listing with a location: empty state that goes back to the list', (tester) async {
      final (state, _) = await makeState(routes: {'GET classifieds/map': geoJson(const [])});
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.property, {'view': 'map'});
      await settle(tester);
      expect(find.byType(FlutterMap), findsNothing);
      expect(find.text('No listings with a location'), findsOneWidget);
      await tester.tap(find.text('List'));
      await settle(tester);
      expect(find.byKey(const ValueKey('map-empty')), findsNothing);
    });

    testWidgets('Arabic: RTL map with Arabic copy and the attribution on the left', (tester) async {
      final (state, _) = await makeState(market: 'uae-ar', routes: {
        'GET classifieds/map': geoJson([
          {'number': 'CL-1', 'title': 'شقة', 'price': '85000.00', 'lat': 25.08, 'lng': 55.14},
        ]),
      });
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.property, {'view': 'map'});
      await settle(tester);
      expect(find.text('85K AED'), findsOneWidget);
      expect(find.text('1 على الخريطة'), findsOneWidget);
      // Bottom end = bottom left in Arabic.
      final attribution = tester.getRect(find.text('© OpenStreetMap contributors'));
      final map = tester.getRect(find.byType(FlutterMap));
      expect(attribution.left - map.left, lessThan(map.right - attribution.right));
    });
  });

  group('ad photos', () {
    final category = {'id': 3, 'slug': 'flats', 'name': 'Flats', 'children': [], 'max_photos': 3, 'photo_max_kb': 4096};

    Future<void> fillAd(WidgetTester tester) async {
      await tester.tap(find.text('Flats'));
      await settle(tester);
      await tester.enterText(find.byType(TextField).at(0), 'Sea view flat');
      await tester.enterText(find.byType(TextField).at(3), 'Dubai');
    }

    /// Scrolls the post-ad form so [finder] sits well inside the screen.
    Future<void> reveal(WidgetTester tester, Finder finder) async {
      await tester.ensureVisible(finder);
      await tester.drag(find.byType(ListView).last, const Offset(0, -300));
      await settle(tester, 2);
    }

    Future<void> tapSubmit(WidgetTester tester) async {
      await tester.ensureVisible(find.byKey(const ValueKey('post-ad-submit')));
      await tester.drag(find.byType(ListView).last, const Offset(0, -300));
      await settle(tester, 2);
      await tester.tap(find.byKey(const ValueKey('post-ad-submit')));
      await settle(tester);
    }

    testWidgets('post ad: the category limits apply (count + size) and the picker is sized to them', (tester) async {
      final picker = FakeMediaPicker(galleryResults: [
        [fakePhoto('a.jpg'), fakePhoto('big.jpg', size: 2 * 1024 * 1024), fakePhoto('b.jpg')],
      ]);
      MediaPicker.current = picker;
      final (state, _) = await makeState(token: 'tok', routes: {
        'GET classifieds/categories': [category],
        // The attributes answer wins: 2 photos of at most 1 MB.
        'GET classifieds/categories/flats/attributes': {'attributes': [], 'max_photos': 2, 'photo_max_kb': 1024},
      });
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.postAd);
      await settle(tester);
      await fillAd(tester);
      expect(find.text('Up to 2 · 1 MB each'), findsOneWidget);
      await reveal(tester, find.byKey(const Key('attach-gallery')));
      await tester.tap(find.byKey(const Key('attach-gallery')));
      await settle(tester);
      expect(picker.galleryLimits, [2]);
      expect(picker.fittingRequests, [1024 * 1024]);
      expect(find.text('2 of 2'), findsOneWidget);
      expect(find.textContaining('big.jpg is larger than 1 MB.'), findsOneWidget);
    });

    testWidgets('post ad: a failed photo keeps the ad a draft; retry updates it, uploads the rest, then submits', (tester) async {
      MediaPicker.current = FakeMediaPicker(galleryResults: [
        [fakePhoto('front.jpg'), fakePhoto('back.jpg')],
      ]);
      var photoCalls = 0;
      final (state, transport) = await makeState(token: 'tok', routes: {
        'GET classifieds/categories': [category],
        'GET classifieds/categories/flats/attributes': {'attributes': []},
        'POST classifieds/ads': FakeTransport.ok({'number': 'AD-7', 'status': 'draft'}, status: 201),
        'PUT classifieds/ads/AD-7': {'number': 'AD-7', 'status': 'draft'},
        'POST classifieds/ads/AD-7/photos': (HttpRequestData r) => ++photoCalls == 2
            ? FakeTransport.error(422, 'validation_failed', 'The photo field must not be greater than 4096 kilobytes.')
            : FakeTransport.ok({'id': photoCalls, 'type': 'photo'}, status: 201),
        'POST classifieds/ads/AD-7/submit': {'number': 'AD-7', 'status': 'pending_review', 'contract': null},
      });
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.postAd);
      await settle(tester);
      await fillAd(tester);
      await reveal(tester, find.byKey(const Key('attach-gallery')));
      await tester.tap(find.byKey(const Key('attach-gallery')));
      await settle(tester);
      await tapSubmit(tester);

      expect(transport.sent('POST classifieds/ads/AD-7/submit'), isEmpty);
      expect(find.byKey(const ValueKey('post-ad-photos-error')), findsOneWidget);
      expect(find.textContaining('saved as a draft'), findsOneWidget);
      expect(find.textContaining('must not be greater than 4096 kilobytes'), findsOneWidget);
      expect(find.text('1 of 3 photos uploaded'), findsOneWidget);
      await tester.drag(find.byType(ListView).last, const Offset(0, -400));
      await settle(tester, 2);
      expect(find.text('Retry photos and submit'), findsOneWidget);

      await tapSubmit(tester);
      final keys = transport.requests.map(transport.keyFor).where((k) => k.contains('classifieds/ads')).toList();
      expect(keys, [
        'POST classifieds/ads',
        'POST classifieds/ads/AD-7/photos',
        'POST classifieds/ads/AD-7/photos',
        'PUT classifieds/ads/AD-7',
        'POST classifieds/ads/AD-7/photos',
        'POST classifieds/ads/AD-7/submit',
      ]);
      expect(MultipartBody.of(transport.sent('POST classifieds/ads/AD-7/photos').last).files.single.$2, 'back.jpg');
      expect(find.text('Ad submitted'), findsOneWidget);
    });

    testWidgets('post ad: the location picked on the map is sent as latitude / longitude', (tester) async {
      MediaPicker.current = FakeMediaPicker();
      final (state, transport) = await makeState(token: 'tok', routes: {
        'GET classifieds/categories': [category],
        'GET classifieds/categories/flats/attributes': {'attributes': []},
        'POST classifieds/ads': FakeTransport.ok({'number': 'AD-8', 'status': 'draft'}, status: 201),
        'POST classifieds/ads/AD-8/submit': {'number': 'AD-8', 'status': 'pending_review', 'contract': null},
      });
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.postAd);
      await settle(tester);
      await fillAd(tester);
      expect(find.text('Not set: the ad will not show on the map'), findsOneWidget);
      await reveal(tester, find.byKey(const ValueKey('post-ad-location-set')));
      await tester.tap(find.byKey(const ValueKey('post-ad-location-set')));
      await settle(tester);
      expect(tester.widget<FilledButton>(find.byKey(const ValueKey('location-confirm'))).onPressed, isNull);
      await tester.tap(find.byKey(const ValueKey('location-map')));
      await settle(tester);
      expect(find.byKey(const ValueKey('location-pin')), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('location-confirm')));
      await settle(tester);
      expect(find.text('Not set: the ad will not show on the map'), findsNothing);
      await tapSubmit(tester);
      final body = transport.bodyOf(transport.sent('POST classifieds/ads').single);
      expect(body['latitude'], closeTo(25.2048, 0.5));
      expect(body['longitude'], closeTo(55.2708, 0.5));
    });

    testWidgets('my ads: a draft gets photos (within the limit, remove works) and is submitted from the list', (tester) async {
      MediaPicker.current = FakeMediaPicker(galleryResults: [
        [fakePhoto('new.jpg')],
      ]);
      var media = [
        {'id': 41, 'type': 'photo', 'url': 'https://cdn.test/1.jpg'},
        {'id': 42, 'type': 'photo', 'url': 'https://cdn.test/2.jpg'},
      ];
      final (state, transport) = await makeState(token: 'tok', routes: {
        'GET classifieds/mine': [
          {'number': 'AD-3', 'title': 'Draft flat', 'status': 'draft', 'category': 'flats', 'price': null},
        ],
        'GET classifieds/ads/AD-3': (HttpRequestData r) => FakeTransport.ok({'number': 'AD-3', 'title': 'Draft flat', 'status': 'draft', 'category': 'flats', 'media': media}),
        'GET classifieds/categories/flats/attributes': {'attributes': [], 'max_photos': 3, 'photo_max_kb': 8192},
        'DELETE classifieds/ads/AD-3/media/41': (HttpRequestData r) {
          media = [media.last];
          return FakeTransport.ok({'deleted': true});
        },
        'POST classifieds/ads/AD-3/photos': FakeTransport.ok({'id': 43, 'type': 'photo'}, status: 201),
        'POST classifieds/ads/AD-3/submit': {'number': 'AD-3', 'status': 'pending_review', 'contract': null},
      });
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.myAds);
      await settle(tester);
      await tester.tap(find.byKey(const ValueKey('my-ad-actions-AD-3')));
      await settle(tester);
      expect(find.text('Photos'), findsOneWidget);
      await tester.tap(find.text('Photos'));
      await settle(tester);

      expect(find.text('2 of 3 photos uploaded'), findsOneWidget);
      expect(find.text('Up to 1 · 8 MB each'), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('ad-media-remove-41')));
      await settle(tester);
      expect(transport.sent('DELETE classifieds/ads/AD-3/media/41'), hasLength(1));
      expect(find.text('1 of 3 photos uploaded'), findsOneWidget);

      await tester.tap(find.byKey(const Key('attach-gallery')));
      await settle(tester);
      await tester.tap(find.byKey(const ValueKey('ad-photos-upload')));
      await settle(tester);
      expect(MultipartBody.of(transport.sent('POST classifieds/ads/AD-3/photos').single).files.single.$1, 'photo');
      expect(find.text('Photos added'), findsOneWidget);

      Navigator.of(tester.element(find.byType(Scaffold).last)).pop();
      await settle(tester);
      await tester.tap(find.byKey(const ValueKey('my-ad-actions-AD-3')));
      await settle(tester);
      await tester.tap(find.text('Submit for review'));
      await settle(tester);
      expect(transport.sent('POST classifieds/ads/AD-3/submit'), hasLength(1));
    });
  });

  testWidgets('support: a PDF can be attached from the device files', (tester) async {
    final picker = FakeMediaPicker(documentResults: [fakePdfFile('statement.pdf')]);
    MediaPicker.current = picker;
    final ticket = {'number': 'TK-1', 'subject': 'Refund', 'status': 'open', 'category': 'payment', 'priority': 'normal', 'can_reply': true, 'messages': [], 'history': []};
    final (state, transport) = await makeState(token: 'tok', routes: {
      'GET support/categories': {
        'categories': [
          {'value': 'payment', 'label': 'Payment'},
        ],
        'priorities': ['normal'],
      },
      'POST support/tickets': FakeTransport.ok(ticket, status: 201),
      'GET support/tickets/TK-1': ticket,
    });
    await pumpApp(tester, state);
    await pushRoute(tester, Routes.supportNew);
    await settle(tester);
    await tester.tap(find.byKey(const ValueKey('support-category')));
    await settle(tester);
    await tester.tap(find.text('Payment').last);
    await settle(tester);
    await tester.enterText(find.byKey(const ValueKey('support-subject')), 'Refund');
    await tester.enterText(find.byKey(const ValueKey('support-message')), 'Bank statement attached.');
    await tester.ensureVisible(find.byKey(const Key('attach-file')));
    expect(find.text('Choose file (PDF)'), findsOneWidget);
    await tester.tap(find.byKey(const Key('attach-file')));
    await settle(tester);
    expect(picker.documentExtensionRequests.single, contains('pdf'));
    await tester.ensureVisible(find.byKey(const ValueKey('support-submit')));
    // The attachment row has a third button ("Choose file (PDF)") now.
    await tester.drag(find.byType(ListView).last, const Offset(0, -300));
    await settle(tester, 2);
    await tester.tap(find.byKey(const ValueKey('support-submit')));
    await settle(tester);
    final created = MultipartBody.of(transport.sent('POST support/tickets').single);
    expect(created.files.single.$1, 'attachments[]');
    expect(created.files.single.$2, 'statement.pdf');
    expect(created.files.single.$3, 'application/pdf');
  });

  group('live watch without a video', () {
    Future<void> open(WidgetTester tester, Map<String, dynamic> stream) async {
      final (state, _) = await makeState(routes: {
        'GET live-streams/demo': {'slug': 'demo', 'title': 'Demo show', 'products': [], ...stream},
      });
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.liveWatch, {'slug': 'demo'});
      await settle(tester);
    }

    testWidgets('idle: not started yet, with refresh', (tester) async {
      await open(tester, {'status': 'idle', 'playback': {'type': 'live', 'webrtc': false, 'hls_url': null}});
      expect(find.text('The host hasn’t started the stream yet. Check back in a few minutes.'), findsOneWidget);
      expect(find.byKey(const ValueKey('live-refresh')), findsOneWidget);
      expect(find.textContaining('not available yet'), findsNothing);
    });

    testWidgets('ended without a recording: no replay', (tester) async {
      await open(tester, {'status': 'ended', 'playback': {'type': 'live', 'hls_url': null}});
      expect(find.text('This show has ended. No replay is available.'), findsOneWidget);
      expect(find.byKey(const ValueKey('live-refresh')), findsNothing);
    });

    testWidgets('live over WebRTC only: watch in the browser (storefront LiveKit viewer)', (tester) async {
      await open(tester, {'status': 'live', 'playback': {'type': 'live', 'webrtc': true, 'hls_url': null, 'room': 'r1'}});
      expect(find.text('This live stream plays in your browser.'), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('live-open-web')));
      await settle(tester);
      expect(opened.single, endsWith('/uae-en/live/demo'));
    });

    testWidgets('live with nothing to play: not available right now, refresh reloads', (tester) async {
      await open(tester, {'status': 'live', 'playback': {'type': 'live', 'webrtc': false, 'hls_url': null}});
      expect(find.text('The live video isn’t available right now. Try again in a moment.'), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('live-refresh')));
      await settle(tester);
      expect(find.byKey(const ValueKey('live-fallback-unavailable')), findsOneWidget);
    });
  });
}
