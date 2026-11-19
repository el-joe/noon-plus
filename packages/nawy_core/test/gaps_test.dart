import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:nawy_core/nawy_core.dart';

/// Pure helpers that the wave suites only reach indirectly: the tolerant
/// JSON readers, Laravel-style error bodies, query/language helpers and
/// Money edge cases (3-decimal currencies, carries, negatives).
void main() {
  group('JsonRead (tolerant readers)', () {
    final json = <String, dynamic>{
      'str': 'x',
      'num': 12,
      'dbl': 2.5,
      'flag': true,
      'intStr': '42',
      'dblStr': '1.25',
      'bad': 'abc',
      'one': 1,
      'zero': 0,
      'oneStr': '1',
      'trueStr': 'true',
      'map': {'a': 1},
      'list': [
        {'id': 1},
        'skip',
        null,
        {'id': 2},
      ],
      'csv': 'a, b,\n c ,,',
      'tags': ['a', null, ' ', 3],
      'nested': [1, 2],
    };

    test('s / str stringify scalars and ignore maps and lists', () {
      expect(json.s('str'), 'x');
      expect(json.s('num'), '12');
      expect(json.s('flag'), 'true');
      expect(json.s('map'), isNull);
      expect(json.s('missing'), isNull);
      expect(json.str('missing'), '');
      expect(json.str('missing', '—'), '—');
    });

    test('i / d read numbers and numeric strings, null otherwise', () {
      expect(json.i('num'), 12);
      expect(json.i('dbl'), 2);
      expect(json.i('intStr'), 42);
      expect(json.i('bad'), isNull);
      expect(json.i('map'), isNull);
      expect(json.d('num'), 12.0);
      expect(json.d('dblStr'), 1.25);
      expect(json.d('bad'), isNull);
    });

    test('b reads bools, 0/1 and "1"/"true", with a fallback', () {
      expect(json.b('flag'), isTrue);
      expect(json.b('one'), isTrue);
      expect(json.b('zero'), isFalse);
      expect(json.b('oneStr'), isTrue);
      expect(json.b('trueStr'), isTrue);
      expect(json.b('bad'), isFalse);
      expect(json.b('missing'), isFalse);
      expect(json.b('missing', true), isTrue);
      expect(json.b('map', true), isTrue);
    });

    test('m / l / asJsonList / asJson skip anything that is not an object', () {
      expect(json.m('map'), {'a': 1});
      expect(json.m('str'), isNull);
      expect(json.l('list').map((e) => e['id']), [1, 2]);
      expect(json.l('str'), isEmpty);
      expect(asJsonList('nope'), isEmpty);
      expect(asJson(null), isEmpty);
      expect(asJson([1]), isEmpty);
      expect(asJson({'k': 'v'}), {'k': 'v'});
    });

    test('strings reads lists and comma / newline separated text', () {
      expect(json.strings('csv'), ['a', 'b', 'c']);
      expect(json.strings('tags'), ['a', '3']);
      expect(json.strings('num'), isEmpty);
      expect(json.strings('missing'), isEmpty);
    });
  });

  group('parseEnvelope edge cases', () {
    test("Laravel's own {message, errors} body becomes validation_failed with fields", () {
      expect(
        () => parseEnvelope(422, jsonEncode({'message': 'The email field is required.', 'errors': {'email': ['Required'], 'name': 'Too short'}})),
        throwsA(isA<ApiException>()
            .having((e) => e.code, 'code', 'validation_failed')
            .having((e) => e.message, 'message', 'The email field is required.')
            .having((e) => e.fieldErrors, 'fields', {'email': 'Required', 'name': 'Too short'})),
      );
    });

    test('status-based codes and a default message for empty or non-JSON bodies', () {
      for (final (status, code) in [(401, 'unauthenticated'), (403, 'forbidden'), (404, 'not_found'), (429, 'too_many_requests'), (500, 'http_error'), (502, 'http_error')]) {
        expect(
          () => parseEnvelope(status, status == 502 ? '<html>Bad gateway</html>' : ''),
          throwsA(isA<ApiException>().having((e) => e.code, 'code', code).having((e) => e.message, 'message', 'Something went wrong.')),
          reason: '$status',
        );
      }
    });

    test('error envelope: string field values, extra details, defaults and language', () {
      expect(
        () => parseEnvelope(409, jsonEncode({'error': {'message': 'Outdated.', 'fields': {'version': 'Changed', 'gone': null}, 'current_version': 3}}),
            headers: {'content-language': 'ar-OM'}),
        throwsA(isA<ApiException>()
            .having((e) => e.code, 'code', 'http_error')
            .having((e) => e.fields, 'fields', {'version': ['Changed']})
            .having((e) => e.details, 'details', {'current_version': 3})
            .having((e) => e.language, 'language', 'ar')),
      );
      expect(
        () => parseEnvelope(429, jsonEncode({'error': {'message': 'Slow down.'}}), headers: {'retry-after': '30'}),
        throwsA(isA<ApiException>().having((e) => e.code, 'code', 'too_many_requests').having((e) => e.retryAfter, 'retryAfter', const Duration(seconds: 30))),
      );
    });

    test('204 / empty body is null data; a body without data is invalid_response', () {
      final empty = parseEnvelope(204, '');
      expect(empty.data, isNull);
      expect(empty.meta, isEmpty);
      expect(parseEnvelope(200, '').data, isNull);
      expect(() => parseEnvelope(200, jsonEncode([1, 2])), throwsA(isA<ApiException>().having((e) => e.code, 'code', 'invalid_response')));
      expect(() => parseEnvelope(201, jsonEncode({'ok': true})), throwsA(isA<ApiException>().having((e) => e.code, 'code', 'invalid_response')));
    });

    test('paging meta: page or current_page, last_page, total', () {
      final a = parseEnvelope(200, jsonEncode({'data': [], 'meta': {'page': 2, 'last_page': 2, 'total': 31}}));
      expect((a.page, a.lastPage, a.total, a.hasMore), (2, 2, 31, false));
      final b = parseEnvelope(200, jsonEncode({'data': [], 'meta': {'current_page': '1', 'last_page': '3'}}));
      expect((b.page, b.hasMore), (1, true));
      final c = parseEnvelope(200, jsonEncode({'data': [], 'meta': []}));
      expect((c.page, c.lastPage, c.total, c.hasMore), (1, 1, null, false));
    });
  });

  group('ApiException predicates', () {
    test('auth rejection, staff role refusal and network', () {
      for (final code in ApiException.authRejectedCodes) {
        expect(ApiException(403, code, '').isAuthRejected, isTrue, reason: code);
        expect(ApiException(401, code, '').isAuthRejected, isFalse, reason: code);
      }
      expect(ApiException(403, 'forbidden', '').isAuthRejected, isFalse);
      expect(ApiException(403, 'forbidden', '').isForbiddenRole, isTrue);
      expect(ApiException(403, 'seller_agreement_required', '').isForbiddenRole, isFalse);
      expect(ApiException(0, 'network', '').isNetwork, isTrue);
      expect(ApiException(422, 'x', '', fields: {'a': [], 'b': ['B1', 'B2']}).fieldErrors, {'b': 'B1'});
    });
  });

  group('query and header helpers', () {
    test('buildQuery drops nulls and empties, encodes bools as 1/0', () {
      expect(buildQuery(null), '');
      expect(buildQuery({'a': null, 'b': ''}), '');
      expect(buildQuery({'q': 'a&b c', 'low': true, 'all': false, 'page': 2}), '?q=a%26b+c&low=1&all=0&page=2');
    });

    test('contentLanguageOf takes the primary tag of the first language', () {
      expect(contentLanguageOf({'content-language': 'ar-OM, en'}), 'ar');
      expect(contentLanguageOf({'content-language': 'EN'}), 'en');
      expect(contentLanguageOf({'content-language': ' '}), isNull);
      expect(contentLanguageOf({}), isNull);
    });

    test('enc escapes path segments', () {
      expect(enc('DA-1/2 x'), 'DA-1%2F2%20x');
    });

    test('uri: global routes skip the market, slashes are normalised', () {
      final api = ApiClient(baseUrl: 'http://x/api/v1/', transport: _NoTransport())..market = 'kwt-ar';
      expect(api.uri('/me/notifications', query: {'page': 1}).toString(), 'http://x/api/v1/kwt-ar/me/notifications?page=1');
      expect(api.uri('auth/login', global: true).toString(), 'http://x/api/v1/auth/login');
      api.market = null;
      expect(api.uri('countries').toString(), 'http://x/api/v1/countries');
    });
  });

  group('Money edge cases', () {
    test('rounding carries into the whole part (3 and 2 decimals)', () {
      expect(Money.parse('1.9995', 'OMR').amount, '2.000');
      expect(Money.parse('9.995', 'AED').amount, '10.00');
      expect(Money.parse('-0.0005', 'KWD').amount, '-0.001');
    });

    test('lower-case codes are normalised and keep 3 decimals', () {
      final m = Money.parse('1.5', 'bhd');
      expect(m.currency, 'BHD');
      expect(m.amount, '1.500');
      expect(Money.fromJson({'amount': '2', 'currency': 'jod'})!.format(), 'JOD 2.000');
    });

    test('minor units format sub-unit and negative amounts', () {
      expect(Money.minorUnits(BigInt.from(5), 'OMR').amount, '0.005');
      expect(Money.minorUnits(BigInt.from(-5), 'OMR').amount, '-0.005');
      expect(Money.minorUnits(BigInt.from(-123456789), 'KWD').format(locale: 'ar'), '-123,456.789 KWD');
      expect(Money.minorUnits(BigInt.from(7), 'AED').amount, '0.07');
      expect(Money.zero('kwd').format(), 'KWD 0.000');
    });

    test('numeric API amounts and whitespace parse; bad shapes do not', () {
      expect(Money.fromJson({'amount': 12.5, 'currency': 'OMR'})!.amount, '12.500');
      expect(Money.parse(' 3.1 ', 'AED').amount, '3.10');
      expect(Money.parse('1,000', 'AED').isZero, isTrue);
      expect(Money.parse('-', 'AED').isZero, isTrue);
      expect(Money.fromJson('12.00'), isNull);
    });

    test('progress / percent off guard zero and negative targets', () {
      expect(Money.parse('5', 'AED').progressTo(Money.zero('AED')), 1);
      expect(Money.parse('-5', 'AED').progressTo(Money.parse('10', 'AED')), 0);
      expect(Money.parse('0.333', 'OMR').progressTo(Money.parse('1', 'OMR')), 0.333);
      expect(Money.parse('5', 'AED').percentOffFrom(Money.zero('AED')), 0);
      expect(() => Money.parse('1', 'AED').compareTo(Money.parse('1', 'OMR')), throwsArgumentError);
    });
  });

  group('L10n', () {
    test('placeholders, unknown keys and status fallbacks', () {
      final l = L10n('en', en: {'x.hello': 'Hi {name}, {n} new'});
      expect(l.t('x.hello', {'name': 'Sara', 'n': 3}), 'Hi Sara, 3 new');
      expect(l.t('x.hello', {'name': null}), 'Hi , {n} new');
      expect(l.t('no.such.key'), 'no.such.key');
      expect(l.status(null), '');
      expect(l.status(''), '');
      expect(l.status('clawback-pending'), 'Clawback pending');
      expect(L10n('ar', ar: {'status.paid': 'مدفوعة'}).status('paid'), 'مدفوعة');
    });
  });

  test('StatusChip colours: unknown statuses use the neutral info colours', () {
    expect(StatusChip.colors('something_new'), (NawyColors.bgInfoSubtle, NawyColors.textLink));
    expect(StatusChip.colors('declined').$2, NawyColors.textDanger);
    expect(StatusChip.colors('rescheduled').$2, NawyColors.textBrand);
  });
}

class _NoTransport implements HttpTransport {
  @override
  Future<HttpResponseData> send(HttpRequestData request) => throw UnimplementedError();
}
