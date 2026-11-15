import 'dart:convert';
import 'package:flutter/widgets.dart' show TextDirection;

import 'package:flutter_test/flutter_test.dart';
import 'package:nawy_core/nawy_core.dart';
import 'package:nawy_core/testing.dart';

void main() {
  group('envelope', () {
    test('data and meta', () {
      final r = parseEnvelope(200, jsonEncode({'data': {'a': 1}, 'meta': {'current_page': 1, 'last_page': 3}}));
      expect((r.data as Map)['a'], 1);
      expect(r.hasMore, isTrue);
    });

    test('error envelope becomes ApiException with fields', () {
      expect(
        () => parseEnvelope(422, jsonEncode({'error': {'code': 'transition_refused', 'message': 'No.', 'fields': {'quantity': ['Too many']}}})),
        throwsA(isA<ApiException>().having((e) => e.code, 'code', 'transition_refused').having((e) => e.fieldErrors['quantity'], 'field', 'Too many')),
      );
    });

    test('401 calls onUnauthenticated when a token is set', () async {
      var signedOut = false;
      final api = ApiClient(baseUrl: 'http://x/api/v1', transport: FakeTransport({'GET delivery/me': FakeTransport.error(401, 'unauthenticated', 'No')}), onUnauthenticated: () => signedOut = true)
        ..market = 'uae-en'
        ..token = 't';
      await expectLater(api.get('delivery/me'), throwsA(isA<ApiException>()));
      expect(signedOut, isTrue);
    });

    test('bearer token and market in the URL', () async {
      final transport = FakeTransport({'GET ops/vendor/dashboard': {'ok': true}});
      final api = ApiClient(baseUrl: 'http://x/api/v1/', transport: transport)
        ..market = 'omn-ar'
        ..token = 'abc';
      await api.get('ops/vendor/dashboard');
      final r = transport.requests.single;
      expect(r.uri.toString(), 'http://x/api/v1/omn-ar/ops/vendor/dashboard');
      expect(r.headers['Authorization'], 'Bearer abc');
    });
  });

  group('multipart', () {
    test('fields and files are encoded with the boundary', () {
      final encoded = encodeMultipart({'codes_match': '1'}, [const FilePart(field: 'photo', filename: 'p.png', bytes: [1, 2, 3])], boundary: 'B');
      expect(encoded.contentType, 'multipart/form-data; boundary=B');
      final text = latin1.decode(encoded.body);
      expect(text, contains('name="codes_match"\r\n\r\n1\r\n'));
      expect(text, contains('name="photo"; filename="p.png"\r\nContent-Type: image/png\r\n\r\n\x01\x02\x03\r\n'));
      expect(text.endsWith('--B--\r\n'), isTrue);
    });
  });

  group('l10n', () {
    test('Arabic falls back to English and flips direction', () {
      final l = L10n('ar', en: {'x.only': 'English only'});
      expect(l.t('x.only'), 'English only');
      expect(l.t('settings.signOut'), 'تسجيل الخروج');
      expect(l.direction, TextDirection.rtl);
      expect(l.status('out_for_delivery'), 'Out for delivery');
    });

    test('core tables have the same keys', () {
      expect(coreAr.keys.toSet(), coreEn.keys.toSet());
    });
  });

  group('session', () {
    test('stores the token and market and signs out', () async {
      final store = MemorySecureStore({'country': 'kwt', 'locale': 'ar'});
      final api = ApiClient(baseUrl: 'http://x/api/v1', transport: FakeTransport());
      final session = Session(api: api, store: store);
      await session.load();
      expect(session.market, 'kwt-ar');
      expect(session.currency, 'KWD');
      await session.signedInWith('tok', {'name': 'Rashid'});
      expect(store.values['token'], 'tok');
      await session.signOut(revoke: false);
      expect(session.signedIn, isFalse);
      expect(store.values.containsKey('token'), isFalse);
    });
  });
}
