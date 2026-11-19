import 'dart:convert';

import 'package:customer_app/core/api/api_client.dart';
import 'package:customer_app/core/money.dart';
import 'package:customer_app/core/sha256.dart';
import 'package:customer_app/features/auth/social_sign_in.dart';
import 'package:customer_app/routing/deep_links.dart';
import 'package:customer_app/routing/guards.dart';
import 'package:customer_app/screens/auth/sign_in_screen.dart';
import 'package:customer_app/screens/cart/cart_tab.dart';
import 'package:customer_app/screens/catalog/product_filters.dart';
import 'package:customer_app/screens/catalog/product_screen.dart';
import 'package:customer_app/screens/checkout/payment_flow.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers.dart';

String hex(List<int> bytes) => bytes.map((b) => b.toRadixString(16).padLeft(2, '0')).join();

void main() {
  group('SHA-256 / PKCE', () {
    test('FIPS 180-4 vectors', () {
      expect(hex(sha256(utf8.encode(''))), 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855');
      expect(hex(sha256(utf8.encode('abc'))), 'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad');
      expect(hex(sha256(utf8.encode('abcdbcdecdefdefgefghfghighijhijkijkljklmklmnlmnomnopnopq'))),
          '248d6a61d20638b8e5c026930c3e6039a33ce45964ff2167f6ecedd419db06c1');
      expect(hex(sha256(List.filled(1000, 0x61))), '41edece42d63e8d9bf515a9ba6932e1c20cbc9f5a5d134645adb5db1b9737ea3');
    });

    test('RFC 7636 appendix B challenge, and fresh verifiers', () {
      expect(SocialSignIn.challengeFor('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'), 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM');
      expect(SocialSignIn.isCompletionLink(Uri.parse('nawy://auth/social/complete?ticket=x')), isTrue);
      expect(SocialSignIn.isCompletionLink(Uri.parse('https://evil.test/auth/social/complete?ticket=x')), isFalse);
      expect(SocialSignIn.errorKey('social_email_unverified'), 'auth.socialEmailUnverified');
      expect(SocialSignIn.errorKey('anything'), 'auth.socialError');
    });
  });

  group('API client: 429, language, auth failures', () {
    test('Retry-After as seconds or an HTTP date', () {
      expect(parseRetryAfter('30'), const Duration(seconds: 30));
      expect(parseRetryAfter('Wed, 18 Nov 2026 10:00:30 GMT', now: DateTime.utc(2026, 11, 18, 10)), const Duration(seconds: 30));
      expect(parseRetryAfter('soon'), isNull);
      expect(contentLanguageOf({'content-language': 'ar-OM'}), 'ar');
    });

    test('a 429 carries Retry-After and blocks the same call until then', () async {
      var now = DateTime(2026, 11, 17, 10);
      final transport = FakeTransport({
        'POST cart/coupons': HttpResponseData(429, jsonEncode({'error': {'code': 'too_many_requests', 'message': 'Slow down.'}}), {'retry-after': '20'}),
      });
      final api = ApiClient(baseUrl: 'https://api.test/api/v1', transport: transport, clock: () => now)..market = 'uae-en';
      await expectLater(api.post('cart/coupons', body: {'code': 'A'}),
          throwsA(isA<ApiException>().having((e) => e.retryAfter, 'retryAfter', const Duration(seconds: 20)).having((e) => e.isRateLimited, 'limited', isTrue)));
      now = now.add(const Duration(seconds: 5));
      await expectLater(api.post('cart/coupons', body: {'code': 'B'}), throwsA(isA<ApiException>().having((e) => e.retryAfter, 'left', const Duration(seconds: 15))));
      expect(transport.sent('POST cart/coupons'), hasLength(1));
      now = now.add(const Duration(seconds: 16));
      await expectLater(api.post('cart/coupons', body: {'code': 'C'}), throwsA(isA<ApiException>()));
      expect(transport.sent('POST cart/coupons'), hasLength(2));
    });

    test('sends Accept-Language and reads Content-Language', () async {
      final transport = FakeTransport({'GET /countries': FakeTransport.ok([], headers: {'content-language': 'ar'})});
      final api = ApiClient(baseUrl: 'https://api.test/api/v1', transport: transport)..locale = 'ar';
      final res = await api.get('countries', global: true);
      expect(transport.requests.single.headers['Accept-Language'], 'ar');
      expect(res.language, 'ar');
      expect(api.contentLanguage, 'ar');
    });

    test('401 and 403 suspended / wrong_token call onAuthFailure; 403 forbidden does not', () async {
      final failures = <String>[];
      final transport = FakeTransport({
        'GET me': FakeTransport.error(401, 'unauthenticated', 'Sign in.'),
        'GET orders': FakeTransport.error(403, 'account_suspended', 'Suspended.'),
        'GET wallet': FakeTransport.error(403, 'forbidden', 'No.'),
      });
      final api = ApiClient(baseUrl: 'https://api.test/api/v1', transport: transport, onAuthFailure: (e) => failures.add(e.code))
        ..market = 'uae-en'
        ..token = 't';
      for (final path in ['me', 'orders', 'wallet']) {
        await expectLater(api.get(path), throwsA(isA<ApiException>()));
      }
      expect(failures, ['unauthenticated', 'account_suspended']);
    });
  });

  test('payment states: under_review is neither paid nor failed; voided is final', () {
    expect(isUnderReview('under_review'), isTrue);
    expect(isPaid('under_review'), isFalse);
    expect(isFailedPayment('under_review'), isFalse);
    expect(isFailedPayment('voided'), isTrue);
  });

  test('password rules, coupon warnings, bestselling sort, my reviews link', () {
    expect(passwordProblem('short', 'short'), 'auth.passwordShort');
    expect(passwordProblem('longenough', 'different'), 'auth.passwordMismatch');
    expect(passwordProblem('longenough', 'longenough'), isNull);
    expect(
      couponWarnings({
        'coupons': [{'code': 'BOGO1'}],
        'warnings': ['Add 2 eligible items to use coupon BOGO1.', 'The warranty plan for X is no longer available.'],
      }),
      ['Add 2 eligible items to use coupon BOGO1.'],
    );
    expect(ProductFilters.sorts, contains('bestselling'));
    expect(resolveStorefrontLink('/uae-en/account/reviews')?.route, Routes.myReviews);
    expect(Routes.authRequired, contains(Routes.myReviews));
  });

  test('BNPL instalment rounds up to the minor unit', () {
    expect(bnplInstalment(Money.parse('249.00', 'AED')).amount, '62.25');
    expect(bnplInstalment(Money.parse('100.01', 'AED')).amount, '25.01');
    expect(bnplInstalment(Money.parse('10.001', 'OMR')).amount, '2.501');
  });
}
