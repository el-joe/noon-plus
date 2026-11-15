import 'dart:convert';

import 'package:customer_app/core/api/api_client.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers.dart';

void main() {
  group('parseEnvelope', () {
    test('reads data and meta', () {
      final res = parseEnvelope(200, jsonEncode({'data': [1, 2], 'meta': {'page': 2, 'last_page': 3, 'total': 41}}));
      expect(res.data, [1, 2]);
      expect(res.page, 2);
      expect(res.lastPage, 3);
      expect(res.total, 41);
      expect(res.hasMore, isTrue);
    });

    test('meta as an empty list is treated as empty', () {
      final res = parseEnvelope(200, jsonEncode({'data': {'a': 1}, 'meta': []}));
      expect(res.meta, isEmpty);
      expect(res.page, 1);
      expect(res.hasMore, isFalse);
    });

    test('maps the error envelope with fields and extra details', () {
      expect(
        () => parseEnvelope(422, jsonEncode({
          'error': {
            'code': 'validation_failed',
            'message': 'The given data was invalid.',
            'fields': {'email': ['The email field is required.'], 'phone': ['Bad', 'Worse']},
          },
        })),
        throwsA(isA<ApiException>()
            .having((e) => e.code, 'code', 'validation_failed')
            .having((e) => e.status, 'status', 422)
            .having((e) => e.fieldErrors, 'fieldErrors', {'email': 'The email field is required.', 'phone': 'Bad'})),
      );
    });

    test('keeps extra error keys (contract gate details)', () {
      try {
        parseEnvelope(409, jsonEncode({
          'error': {'code': 'customer_contract_required', 'message': 'Accept first', 'fields': {}, 'contracts': [{'version_id': 7}]},
        }));
        fail('should throw');
      } on ApiException catch (e) {
        expect(e.code, 'customer_contract_required');
        expect(e.details['contracts'], [{'version_id': 7}]);
        expect(e.fields, isEmpty);
      }
    });

    test('falls back for Laravel bodies without the envelope', () {
      expect(() => parseEnvelope(401, jsonEncode({'message': 'Unauthenticated.'})),
          throwsA(isA<ApiException>().having((e) => e.code, 'code', 'unauthenticated').having((e) => e.isUnauthenticated, 'isUnauthenticated', true)));
      expect(() => parseEnvelope(404, '<html>not found</html>'), throwsA(isA<ApiException>().having((e) => e.code, 'code', 'not_found')));
      expect(() => parseEnvelope(429, ''), throwsA(isA<ApiException>().having((e) => e.code, 'code', 'too_many_requests')));
    });

    test('rejects a success body without data', () {
      expect(() => parseEnvelope(200, jsonEncode({'ok': true})), throwsA(isA<ApiException>().having((e) => e.code, 'code', 'invalid_response')));
    });

    test('204 / empty body yields null data', () {
      expect(parseEnvelope(204, '').data, isNull);
    });
  });

  group('buildQuery', () {
    test('drops nulls and empty strings, encodes values and booleans', () {
      expect(buildQuery({'q': 'air fryer', 'brand': null, 'x': '', 'attr[colour]': 'Red,Blue', 'with_photos': true}), '?q=air+fryer&attr%5Bcolour%5D=Red%2CBlue&with_photos=1');
      expect(buildQuery({}), '');
    });
  });

  group('ApiClient', () {
    test('sends market path, bearer token and guest cart token', () async {
      final transport = FakeTransport({'GET cart': emptyCart});
      final api = ApiClient(baseUrl: 'https://api.test/api/v1/', transport: transport)
        ..market = 'uae-en'
        ..token = 't0k'
        ..cartToken = 'cart-1';
      await api.get('cart', query: {'payment_method': 'cod'});
      final r = transport.requests.single;
      expect(r.uri.toString(), 'https://api.test/api/v1/uae-en/cart?payment_method=cod');
      expect(r.headers['Authorization'], 'Bearer t0k');
      expect(r.headers['X-Cart-Token'], 'cart-1');
      expect(r.headers['Accept'], 'application/json');
    });

    test('global paths skip the market', () async {
      final transport = FakeTransport({'GET /countries': countriesFixture});
      final api = ApiClient(baseUrl: 'https://api.test/api/v1', transport: transport)..market = 'uae-en';
      final res = await api.get('countries', global: true);
      expect((res.data as List).length, 2);
      expect(transport.requests.single.uri.path, '/api/v1/countries');
    });

    test('stores a new guest cart token from the response header', () async {
      String? saved;
      final transport = FakeTransport({'PUT cart/items': FakeTransport.ok(emptyCart, headers: {'x-cart-token': 'new-token'})});
      final api = ApiClient(baseUrl: 'https://api.test/api/v1', transport: transport)
        ..market = 'uae-en'
        ..onCartToken = (t) => saved = t;
      await api.put('cart/items', body: {'listing_id': 5, 'quantity': 1});
      expect(api.cartToken, 'new-token');
      expect(saved, 'new-token');
      expect(transport.bodyOf(transport.requests.single), {'listing_id': 5, 'quantity': 1});
      expect(transport.requests.single.headers['Content-Type'], 'application/json');
    });

    test('a 401 with a token calls onUnauthenticated', () async {
      var signedOut = false;
      final transport = FakeTransport({'GET me': FakeTransport.error(401, 'unauthenticated', 'Unauthenticated.')});
      final api = ApiClient(baseUrl: 'https://api.test/api/v1', transport: transport, onUnauthenticated: () => signedOut = true)
        ..market = 'uae-en'
        ..token = 'expired';
      await expectLater(api.get('me'), throwsA(isA<ApiException>()));
      expect(signedOut, isTrue);
    });

    test('transport failures become network_error (status 0)', () async {
      final api = ApiClient(baseUrl: 'https://api.test/api/v1', transport: _Throwing())..market = 'uae-en';
      await expectLater(api.get('products'), throwsA(isA<ApiException>().having((e) => e.isNetwork, 'isNetwork', true).having((e) => e.code, 'code', 'network_error')));
    });
  });
}

class _Throwing implements HttpTransport {
  @override
  Future<HttpResponseData> send(HttpRequestData request) => Future.error(Exception('socket closed'));
}
