import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nawy_core/nawy_core.dart';
import 'package:nawy_core/testing.dart';

Future<Session> session(FakeTransport transport, {bool signedIn = true, String locale = 'en'}) async {
  final s = Session(api: ApiClient(baseUrl: 'http://x/api/v1', transport: transport), store: MemorySecureStore({'locale': locale, if (signedIn) 'token': 't', 'profile': jsonEncode({'email': 'a@nawy.test'})}));
  await s.load();
  return s;
}

void main() {
  test('core EN / AR tables have the same keys and placeholders', () {
    expect(coreEn.keys.toSet(), coreAr.keys.toSet());
    for (final key in coreEn.keys) {
      final placeholders = RegExp(r'\{\w+\}').allMatches(coreEn[key]!).map((m) => m.group(0)).toSet();
      expect(RegExp(r'\{\w+\}').allMatches(coreAr[key]!).map((m) => m.group(0)).toSet(), placeholders, reason: key);
    }
  });

  group('429 Retry-After', () {
    test('parsed from seconds or a date', () {
      expect(parseRetryAfter('7'), const Duration(seconds: 7));
      expect(parseRetryAfter('Tue, 17 Nov 2026 10:01:00 GMT', now: DateTime.utc(2026, 11, 17, 10)), const Duration(minutes: 1));
      expect(parseRetryAfter(null), isNull);
    });

    test('the same call is not sent again before Retry-After', () async {
      var now = DateTime(2026, 11, 17, 10);
      final transport = FakeTransport({'POST ops/auth/login': HttpResponseData(429, jsonEncode({'error': {'code': 'too_many_requests', 'message': 'Slow down.'}}), {'retry-after': '30'})});
      final api = ApiClient(baseUrl: 'http://x/api/v1', transport: transport, clock: () => now)..market = 'uae-en';
      await expectLater(api.post('ops/auth/login'), throwsA(isA<ApiException>().having((e) => e.retryAfter, 'wait', const Duration(seconds: 30))));
      now = now.add(const Duration(seconds: 10));
      expect(api.retryWait('POST', 'ops/auth/login'), const Duration(seconds: 20));
      await expectLater(api.post('ops/auth/login'), throwsA(isA<ApiException>().having((e) => e.isRateLimited, 'limited', isTrue)));
      expect(transport.requests, hasLength(1));
      // Another path is not blocked.
      await api.get('ops/vendor/dashboard');
      expect(transport.requests, hasLength(2));
    });
  });

  test('Accept-Language follows the session language; Content-Language is read', () async {
    final transport = FakeTransport({'GET delivery/me': HttpResponseData(200, jsonEncode({'data': {}}), {'content-language': 'ar'})});
    final s = await session(transport);
    await s.setLocale('ar');
    final res = await s.api.get('delivery/me');
    expect(transport.requests.single.headers['Accept-Language'], 'ar');
    expect(res.language, 'ar');
    expect(s.api.contentLanguage, 'ar');
  });

  group('auth failures sign out with a notice', () {
    for (final (status, code, notice) in [
      (401, 'unauthenticated', 'auth.expired'),
      (403, 'wrong_token', 'auth.wrongToken'),
      (403, 'no_workspace', 'auth.wrongToken'),
      (403, 'account_suspended', 'auth.suspended'),
    ]) {
      test('$status $code → $notice', () async {
        final s = await session(FakeTransport({'GET delivery/me': FakeTransport.error(status, code, 'No.')}));
        await expectLater(s.api.get('delivery/me'), throwsA(isA<ApiException>()));
        await Future<void>.delayed(Duration.zero);
        expect(s.signedIn, isFalse);
        expect(s.signOutNotice, notice);
        expect(s.lastEmail, 'a@nawy.test');
        expect(await s.store.read(Session.keyToken), isNull);
      });
    }

    test('403 forbidden (staff role) keeps the session', () async {
      final s = await session(FakeTransport({'POST ops/vendor/stock/1': FakeTransport.error(403, 'forbidden', 'No.')}));
      await expectLater(s.api.post('ops/vendor/stock/1'), throwsA(isA<ApiException>().having((e) => e.isForbiddenRole, 'role', isTrue)));
      expect(s.signedIn, isTrue);
    });

    test('a manual sign-out clears the notice', () async {
      final s = await session(FakeTransport());
      s.signOutNotice = 'auth.expired';
      await s.signOut();
      expect(s.signOutNotice, isNull);
    });
  });

  testWidgets('apiErrorMessage: role refusal, rate limit, field error', (tester) async {
    late BuildContext ctx;
    await tester.pumpWidget(L10nScope(l10n: L10n('en'), child: Builder(builder: (c) {
      ctx = c;
      return const SizedBox();
    })));
    expect(apiErrorMessage(ctx, ApiException(403, 'forbidden', 'x')), 'Your role does not allow this. Ask the account owner for access.');
    expect(apiErrorMessage(ctx, ApiException(429, 'too_many_requests', 'x', retryAfter: const Duration(seconds: 9))), 'Too many attempts. Try again in 9 seconds.');
    expect(apiErrorMessage(ctx, ApiException(429, 'too_many_requests', 'x')), 'Too many attempts. Please wait a moment and try again.');
    expect(apiErrorMessage(ctx, ApiException(422, 'validation_failed', 'x', fields: {'q': ['Bad q']})), 'Bad q');
  });

  test('Figma type scale and radii (QC-065), with ar/* line heights', () {
    NawyText.arabic = false;
    expect(NawyText.h1.fontSize, 32);
    expect(NawyText.h1.height! * 32, closeTo(40, 0.001));
    expect(NawyText.h2.fontWeight, FontWeight.w700);
    expect(NawyText.display.fontSize, 40);
    expect(NawyRadius.xs, 4);
    expect(NawyRadius.sm, 6);
    expect(NawyRadius.lg, 12);
    buildNawyTheme(arabic: true);
    expect(NawyText.body.height! * 14, closeTo(23, 0.001));
    expect(NawyText.h3.height! * 20, closeTo(32, 0.001));
    buildNawyTheme();
    expect(NawyText.body.height! * 14, closeTo(20, 0.001));
  });

  testWidgets('sign-in screen shows the sign-out notice and prefills the email', (tester) async {
    final s = await session(FakeTransport({'GET x': FakeTransport.error(401, 'unauthenticated', 'No.')}));
    await expectLater(s.api.get('x'), throwsA(isA<ApiException>()));
    await tester.pumpWidget(NawyOpsApp(
      session: s,
      title: 'T',
      signIn: (_) => SignInScreen(title: 'T', subtitle: 'S', onSubmit: (_, _, _) async {}),
      home: (_) => const Text('home'),
    ));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('sign-out-notice')), findsOneWidget);
    expect(find.text('Your session has expired. Please sign in again.'), findsOneWidget);
    expect(find.text('a@nawy.test'), findsOneWidget);
  });
}
