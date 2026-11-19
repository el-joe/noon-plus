import 'package:customer_app/core/api/api_client.dart';
import 'package:customer_app/screens/live/live_screens.dart';
import 'package:customer_app/screens/souq/listing_map.dart';
import 'package:customer_app/screens/souq/souq_screens.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:latlong2/latlong.dart' show LatLng;

import '../helpers.dart';

void main() {
  group('map pins', () {
    test('GeoJSON features become pins; [lng, lat] order, bad points and duplicates are skipped', () {
      final pins = mapListingsFromGeoJson({
        'type': 'FeatureCollection',
        'features': [
          {
            'type': 'Feature',
            'geometry': {
              'type': 'Point',
              'coordinates': [55.27, 25.2],
            },
            'properties': {'number': 'CL-1', 'title': 'Marina flat', 'price': '1200000.00', 'currency': 'AED', 'featured': true},
          },
          {
            'type': 'Feature',
            'geometry': {
              'type': 'Point',
              'coordinates': ['55.30', '25.10'],
            },
            'properties': {'number': 'CL-2', 'title': 'Studio', 'price': null, 'currency': 'AED'},
          },
          {
            'type': 'Feature',
            'geometry': {
              'type': 'Point',
              'coordinates': [55.27, 25.2],
            },
            'properties': {'number': 'CL-1'},
          },
          {
            'type': 'Feature',
            'geometry': {
              'type': 'Point',
              'coordinates': [0, 0],
            },
            'properties': {'number': 'CL-3'},
          },
          {
            'type': 'Feature',
            'geometry': {
              'type': 'Point',
              'coordinates': [200, 25],
            },
            'properties': {'number': 'CL-4'},
          },
          {
            'type': 'Feature',
            'geometry': {'type': 'Polygon', 'coordinates': []},
            'properties': {'number': 'CL-5'},
          },
          {
            'type': 'Feature',
            'geometry': {
              'type': 'Point',
              'coordinates': [55.1, 25.1],
            },
            'properties': {},
          },
        ],
      });
      expect(pins.map((p) => p.number), ['CL-1', 'CL-2']);
      expect(pins.first.point, const LatLng(25.2, 55.27));
      expect(pins.first.price, {'amount': '1200000.00', 'currency': 'AED'});
      expect(pins.first.featured, isTrue);
      expect(pins.last.point, const LatLng(25.1, 55.3));
      expect(pins.last.price, isNull);
      expect(mapListingsFromGeoJson(null), isEmpty);
      expect(mapListingsFromGeoJson({'features': 'x'}), isEmpty);
    });

    test('ad cards with location become pins; cards without one are left out', () {
      final pins = mapListingsFromCards([
        {
          'number': 'CL-1',
          'title': 'Villa',
          'price': money('3500000.00'),
          'city': 'Dubai',
          'area': 'Jumeirah',
          'image': 'https://cdn.test/v.jpg',
          'is_featured': true,
          'location': {'lat': 25.2, 'lng': 55.25},
        },
        {'number': 'CL-2', 'title': 'No location', 'location': null},
        {
          'number': 'CL-3',
          'title': 'Bad',
          'location': {'lat': 95, 'lng': 55},
        },
      ]);
      expect(pins.single.number, 'CL-1');
      expect(pins.single.place, 'Jumeirah, Dubai');
      expect(pins.single.image, 'https://cdn.test/v.jpg');
      expect(pins.single.featured, isTrue);
    });

    test('bounds wrap every pin and serialise as south,west,north,east', () {
      expect(listingBounds(const []), isNull);
      final bounds = listingBounds(const [
        MapListing(number: 'a', point: LatLng(25.0, 55.5)),
        MapListing(number: 'b', point: LatLng(25.3, 55.1)),
        MapListing(number: 'c', point: LatLng(24.9, 55.2)),
      ])!;
      expect((bounds.south, bounds.west, bounds.north, bounds.east), (24.9, 55.1, 25.3, 55.5));
      expect(boundsQuery(bounds), '24.900000,55.100000,25.300000,55.500000');
    });

    test('pin prices are short, currency before (EN) / after (AR)', () {
      expect(compactPrice(money('1250000.00')), 'AED 1.3M');
      expect(compactPrice(money('3000000.00')), 'AED 3M');
      expect(compactPrice(money('85000.00')), 'AED 85K');
      expect(compactPrice(money('125000.00')), 'AED 125K');
      expect(compactPrice(money('950.00')), 'AED 950');
      expect(compactPrice(money('85000.00'), locale: 'ar'), '85K AED');
      expect(compactPrice(null), '');
    });

    test('tile config defaults to OpenStreetMap with attribution', () {
      expect(MapConfig.tileUrl, 'https://tile.openstreetmap.org/{z}/{x}/{y}.png');
      expect(MapConfig.attribution, '© OpenStreetMap contributors');
      expect(MapConfig.userAgentPackageName, isNotEmpty);
    });
  });

  test('the map endpoint answers without the envelope: raw JSON as data', () {
    expect(parseRaw(200, '{"type":"FeatureCollection","features":[]}').data, {'type': 'FeatureCollection', 'features': []});
    expect(parseRaw(200, '').data, isNull);
    expect(() => parseRaw(200, '<html>'), throwsA(isA<ApiException>()));
  });

  group('ad photo limits', () {
    test('attributes win over the category node; defaults 20 / 8192', () {
      expect((const AdPhotoLimits()).maxPhotos, 20);
      final none = AdPhotoLimits.of(null);
      expect((none.maxPhotos, none.photoMaxKb), (20, 8192));
      final node = AdPhotoLimits.of({'attributes': []}, {'max_photos': 8, 'photo_max_kb': 4096});
      expect((node.maxPhotos, node.photoMaxKb), (8, 4096));
      final attrs = AdPhotoLimits.of({'max_photos': 5, 'photo_max_kb': '2048'}, {'max_photos': 8, 'photo_max_kb': 4096});
      expect((attrs.maxPhotos, attrs.photoMaxKb), (5, 2048));
      final zero = AdPhotoLimits.of({'max_photos': 0, 'photo_max_kb': -1});
      expect((zero.maxPhotos, zero.photoMaxKb), (20, 8192));
    });

    test('rules count the photos already uploaded', () {
      const limits = AdPhotoLimits(maxPhotos: 5, photoMaxKb: 1024);
      expect(limits.rules().maxCount, 5);
      expect(limits.rules(uploaded: 3).maxCount, 2);
      expect(limits.rules(uploaded: 9).maxCount, 0);
      expect(limits.rules().maxBytes, 1024 * 1024);
    });
  });

  test('live fallback follows the stream state', () {
    expect(liveFallback({'status': 'scheduled'}), LiveFallback.scheduled);
    expect(liveFallback({'status': 'idle'}), LiveFallback.notStarted);
    expect(liveFallback({'status': 'ended', 'playback': {'type': 'live'}}), LiveFallback.ended);
    expect(liveFallback({'status': 'live', 'playback': {'webrtc': true, 'hls_url': null}}), LiveFallback.browserOnly);
    expect(liveFallback({'status': 'live', 'playback': {'webrtc': false, 'hls_url': null}}), LiveFallback.unavailable);
    expect(liveFallback({'status': 'live'}), LiveFallback.unavailable);
    expect(liveWebUrl('uae-ar', 'big show'), endsWith('/uae-ar/live/big%20show'));
  });
}
