import 'package:customer_app/core/api/api_client.dart';
import 'package:customer_app/core/storage/secure_store.dart';
import 'package:customer_app/features/auth/social_sign_in.dart';
import 'package:customer_app/routing/guards.dart';
import 'package:customer_app/state/app_state.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers.dart';

const signedInUser = {'id': 1, 'name': 'Sara Ali', 'email': 'sara@example.com', 'has_password': false};

Map<String, dynamic> orderFixture({String status = 'delivered', String paymentStatus = 'paid'}) => {
      'number': 'NW-AE-9',
      'status': status,
      'payment_method': 'card',
      'payment_status': paymentStatus,
      'payment': null,
      'placed_at': '2026-11-14T09:12:00+04:00',
      'totals': {'subtotal': money('249.00'), 'shipping': money('10.00'), 'total': money('259.00'), 'tax_included': money('12.33')},
      'shipments': [],
      'shipping_address': {'name': 'Sara', 'city': 'Dubai', 'area': 'Marina', 'street': 'Marina Gate 2'},
    };

void main() {
  tearDown(() {
    SocialSignIn.debugSupported = null;
  });

  group('auth failures sign the customer out with a message', () {
    testWidgets('401 (expired token) → signed out, "sign in again" notice with a sign-in action', (tester) async {
      final (state, _) = await makeState(token: 'old', routes: {'GET orders': FakeTransport.error(401, 'unauthenticated', 'Unauthenticated.')});
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.orders);
      await settle(tester);
      expect(state.signedIn, isFalse);
      expect(await state.store.read(StoreKeys.token), isNull);
      expect(state.lastEmail, 'sara@example.com');
      expect(find.text('Your session has expired. Please sign in again.'), findsOneWidget);
      await tester.tap(find.text('Sign in').last);
      await settle(tester);
      expect(find.byKey(const ValueKey('sign-in-email')), findsOneWidget);
      expect(find.text('sara@example.com'), findsOneWidget);
    });

    testWidgets('403 account_suspended → signed out with the suspended message', (tester) async {
      final (state, _) = await makeState(token: 'tok', routes: {'GET orders': FakeTransport.error(403, 'account_suspended', 'Suspended.')});
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.orders);
      await settle(tester);
      expect(state.signedIn, isFalse);
      expect(find.text('Your account is suspended. Contact Nawy support.'), findsOneWidget);
    });

    testWidgets('429 shows the wait from Retry-After', (tester) async {
      final (state, _) = await makeState(routes: {
        'POST auth/email-code/send': HttpResponseData(429, '{"error":{"code":"too_many_requests","message":"Too many."}}', {'retry-after': '42'}),
      });
      await pumpApp(tester, state, route: Routes.signIn);
      await tester.enterText(find.byKey(const ValueKey('sign-in-email')), 'sara@example.com');
      await tester.pump();
      await tester.tap(find.byKey(const ValueKey('sign-in-send')));
      await settle(tester);
      expect(find.text('Too many attempts. Try again in 42 seconds.'), findsOneWidget);
    });
  });

  testWidgets('language change sends PATCH me {locale} and Accept-Language', (tester) async {
    final (state, transport) = await makeState(token: 'tok', routes: {'PATCH me': {...signedInUser, 'locale': 'ar'}});
    expect(state.api.locale, 'en');
    await state.setMarket('uae-ar');
    await tester.pump();
    expect(transport.bodyOf(transport.sent('PATCH me').single), {'locale': 'ar'});
    expect(transport.requests.last.headers['Accept-Language'], 'ar');
    // Changing only the country keeps the language: no extra PATCH.
    await state.setMarket('omn-ar');
    expect(transport.sent('PATCH me'), hasLength(1));
  });

  testWidgets('sign-in merge warnings: snack notice and a dismissible cart banner', (tester) async {
    final (state, transport) = await makeState(routes: {
      'POST auth/email-code/send': FakeTransport.ok({'sent': true}, status: 202),
      'POST auth/email-code/verify': {'token': 'tok-1', 'user': signedInUser},
      'POST cart/merge': FakeTransport.ok(emptyCart, meta: {
        'merge_warnings': [
          {'listing_id': 5, 'code': 'flash_sale_customer_limit', 'message': 'Only 1 of this item could be kept in your cart.', 'requested': 3, 'merged': 1},
        ],
      }),
      'GET cart': cartFixture(qty: 1),
    }, store: {'${StoreKeys.cartToken}:uae': 'guest'});
    await pumpApp(tester, state, route: Routes.signIn);
    await tester.enterText(find.byKey(const ValueKey('sign-in-email')), 'sara@example.com');
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('sign-in-send')));
    await settle(tester);
    await tester.enterText(find.byKey(const ValueKey('sign-in-code')), '123456');
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('sign-in-verify')));
    await settle(tester);

    expect(state.mergeWarnings, hasLength(1));
    expect(find.text('1 item(s) from your guest cart changed. Check your cart.'), findsOneWidget);
    expect(transport.sent('POST cart/merge'), hasLength(1));

    await pushRoute(tester, Routes.cart);
    await settle(tester);
    expect(find.byKey(const ValueKey('cart-merge-warnings')), findsOneWidget);
    expect(find.textContaining('Only 1 of this item could be kept'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('cart-merge-dismiss')));
    await settle(tester);
    expect(find.byKey(const ValueKey('cart-merge-warnings')), findsNothing);
  });

  group('D9 sign-in options', () {
    testWidgets('password sign-in; invalid_credentials offers the email code', (tester) async {
      var attempts = 0;
      final (state, transport) = await makeState(routes: {
        'POST auth/password/login': (HttpRequestData r) => ++attempts == 1
            ? FakeTransport.error(422, 'invalid_credentials', 'These details do not match.')
            : FakeTransport.ok({'token': 'pw-tok', 'user': {...signedInUser, 'has_password': true}}),
      });
      await pumpApp(tester, state, route: Routes.signIn);
      await tester.tap(find.byKey(const ValueKey('sign-in-with-password')));
      await tester.pump();
      await tester.enterText(find.byKey(const ValueKey('sign-in-email')), 'sara@example.com');
      await tester.enterText(find.byKey(const ValueKey('sign-in-password')), 'wrong-pass');
      await tester.pump();
      await tester.tap(find.byKey(const ValueKey('sign-in-password-submit')));
      await settle(tester);
      expect(find.text('These details do not match.'), findsOneWidget);
      expect(find.byKey(const ValueKey('sign-in-use-code')), findsOneWidget);

      await tester.enterText(find.byKey(const ValueKey('sign-in-password')), 'right-pass');
      await tester.pump();
      await tester.tap(find.byKey(const ValueKey('sign-in-password-submit')));
      await settle(tester);
      expect(state.signedIn, isTrue);
      expect(await state.store.read(StoreKeys.token), 'pw-tok');
      final body = transport.bodyOf(transport.sent('POST auth/password/login').last);
      expect(body['email'], 'sara@example.com');
      expect(body['device_name'], isNotEmpty);
    });

    testWidgets('forgot password by code: forgot → reset with code and device_name → signed in', (tester) async {
      final (state, transport) = await makeState(routes: {
        'POST auth/password/forgot': FakeTransport.ok({'sent': true}, status: 202),
        'POST auth/password/reset': {'reset': true, 'token': 'reset-tok', 'user': {...signedInUser, 'has_password': true}},
      });
      await pumpApp(tester, state, route: Routes.signIn);
      await tester.tap(find.byKey(const ValueKey('sign-in-with-password')));
      await tester.pump();
      await tester.tap(find.byKey(const ValueKey('sign-in-forgot')));
      await tester.pump();
      await tester.enterText(find.byKey(const ValueKey('sign-in-email')), 'sara@example.com');
      await tester.pump();
      await tester.tap(find.byKey(const ValueKey('sign-in-forgot-send')));
      await settle(tester);

      await tester.enterText(find.byKey(const ValueKey('sign-in-code')), '654321');
      await tester.enterText(find.byKey(const ValueKey('reset-password')), 'new-password');
      await tester.enterText(find.byKey(const ValueKey('reset-confirm')), 'mismatch-pass');
      await tester.pump();
      await tester.tap(find.byKey(const ValueKey('reset-submit')));
      await settle(tester);
      expect(find.text('The passwords do not match.'), findsOneWidget);
      expect(transport.sent('POST auth/password/reset'), isEmpty);

      await tester.enterText(find.byKey(const ValueKey('reset-confirm')), 'new-password');
      await tester.pump();
      await tester.tap(find.byKey(const ValueKey('reset-submit')));
      await settle(tester);
      expect(state.signedIn, isTrue);
      final body = transport.bodyOf(transport.sent('POST auth/password/reset').single);
      expect(body, containsPair('code', '654321'));
      expect(body, containsPair('password_confirmation', 'new-password'));
      expect(body['device_name'], isNotEmpty);
    });

    testWidgets('Google via the web flow: PKCE redirect, browser, app link → exchange with the verifier', (tester) async {
      SocialSignIn.debugSupported = true;
      final opened = <String>[];
      final previousOpen = SocialSignIn.openUrl;
      SocialSignIn.openUrl = (url) async {
        opened.add(url);
        return true;
      };
      addTearDown(() => SocialSignIn.openUrl = previousOpen);
      final (state, transport) = await makeState(routes: {
        'GET auth/social/google/redirect': {'url': 'https://accounts.google.test/o/oauth2/auth?state=s'},
        'POST auth/social/exchange': {'token': 'social-tok', 'user': signedInUser},
      });
      await pumpApp(tester, state, route: Routes.signIn);
      await tester.tap(find.byKey(const ValueKey('sign-in-google')));
      await settle(tester);
      expect(opened, ['https://accounts.google.test/o/oauth2/auth?state=s']);
      expect(find.byKey(const ValueKey('sign-in-social-waiting')), findsOneWidget);
      final challenge = transport.sent('GET auth/social/google/redirect').single.uri.queryParameters['code_challenge'];
      expect(challenge, hasLength(43));

      await state.handleLink(Uri.parse('nawy://auth/social/complete?ticket=T1&market=uae-en'));
      await settle(tester, 12);
      expect(state.signedIn, isTrue);
      final exchange = transport.sent('POST auth/social/exchange').single;
      expect(exchange.uri.path, endsWith('/uae-en/auth/social/exchange'));
      final body = transport.bodyOf(exchange);
      expect(body['ticket'], 'T1');
      expect(SocialSignIn.challengeFor(body['code_verifier'] as String), challenge);
      expect(await state.store.read(StoreKeys.socialPkce), isNull);
      expect(find.byKey(const ValueKey('sign-in-social-waiting')), findsNothing);
      expect(state.signedIn, isTrue);
    });

    testWidgets('a social error link shows its message and stays signed out', (tester) async {
      final (state, transport) = await makeState();
      await pumpApp(tester, state);
      await state.handleLink(Uri.parse('nawy://auth/social/complete?social_error=social_email_unverified'));
      await settle(tester);
      expect(state.signedIn, isFalse);
      expect(transport.sent('POST auth/social/exchange'), isEmpty);
      expect(find.text('That account has no verified email. Sign in with an email code instead.'), findsOneWidget);
    });

    testWidgets('Security: set a password, unlink Google, sign-in method in history', (tester) async {
      var linked = ['google', 'apple'];
      var hasPassword = false;
      final (state, transport) = await makeState(token: 'tok', routes: {
        'GET me': (HttpRequestData r) => FakeTransport.ok({...signedInUser, 'has_password': hasPassword, 'social_accounts': linked}),
        'POST me/password': (HttpRequestData r) {
          hasPassword = true;
          return FakeTransport.ok({'has_password': true});
        },
        'DELETE me/social-accounts/google': (HttpRequestData r) {
          linked = ['apple'];
          return FakeTransport.ok({'unlinked': 'google'});
        },
        'GET me/login-history': [
          {'id': 1, 'outcome': 'succeeded', 'channel': 'api', 'method': 'password', 'created_at': '2026-11-16T10:00:00Z'},
        ],
      });
      await pumpApp(tester, state);
      await pushRoute(tester, Routes.security);
      await settle(tester);
      expect(find.text('No password yet — you sign in with an email code.'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('security-password-action')));
      await settle(tester);
      expect(find.byKey(const ValueKey('password-current')), findsNothing);
      await tester.enterText(find.byKey(const ValueKey('password-new')), 'my-new-pass');
      await tester.enterText(find.byKey(const ValueKey('password-confirm')), 'my-new-pass');
      await tester.tap(find.byKey(const ValueKey('password-save')));
      await settle(tester);
      expect(transport.bodyOf(transport.sent('POST me/password').single), {'password': 'my-new-pass', 'password_confirmation': 'my-new-pass'});
      expect(find.text('You can sign in with your password or an email code.'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('security-unlink-google')));
      await settle(tester);
      await tester.tap(find.byKey(const ValueKey('confirm-yes')));
      await settle(tester);
      expect(transport.sent('DELETE me/social-accounts/google'), hasLength(1));
      expect(find.byKey(const ValueKey('security-unlink-google')), findsNothing);
      expect(find.byKey(const ValueKey('security-unlink-apple')), findsOneWidget);

      await tester.tap(find.text('Login history'));
      await settle(tester);
      expect(find.textContaining('Password · api'), findsOneWidget);
    });
  });

  testWidgets('orders: disputed overlay and under_review payment', (tester) async {
    final (state, _) = await makeState(token: 'tok', routes: {
      'GET orders/NW-AE-9': orderFixture(status: 'disputed', paymentStatus: 'under_review'),
    });
    await pumpApp(tester, state);
    await pushRoute(tester, Routes.order, {'number': 'NW-AE-9'});
    await settle(tester);
    expect(find.byKey(const ValueKey('order-detail-disputed')), findsOneWidget);
    expect(find.byKey(const ValueKey('order-detail-under-review')), findsOneWidget);
    expect(find.text('Dispute open'), findsWidgets);
    expect(find.byKey(const ValueKey('order-pay-now')), findsNothing);
  });

  testWidgets('payment wait: under_review stops polling and asks not to pay again', (tester) async {
    final (state, _) = await makeState(token: 'tok', routes: {
      'POST orders/NW-AE-9/payments/verify': {...orderFixture(paymentStatus: 'under_review'), 'payment': {'status': 'under_review', 'reference': 'P1'}},
    });
    await pumpApp(tester, state);
    await pushRoute(tester, Routes.paymentWait, {'order': 'NW-AE-9', 'reference': 'P1'});
    await tester.tap(find.byKey(const ValueKey('payment-verify')));
    await settle(tester);
    expect(find.text('Payment under review'), findsOneWidget);
    expect(find.byKey(const ValueKey('payment-verify')), findsNothing);
  });

  testWidgets('reviews: seller reply shown, report with a reason', (tester) async {
    final (state, transport) = await makeState(token: 'tok', routes: {
      'GET products/p1/reviews': {
        'summary': {'average': '4.0', 'count': 1, 'distribution': {}},
        'reviews': [
          {'id': 77, 'rating': 4, 'title': 'Good', 'body': 'Works well', 'author': 'Ali', 'created_at': '2026-11-10T10:00:00Z', 'helpful_count': 0, 'photos': [],
            'seller_reply': {'body': 'Thank you, Ali!', 'created_at': '2026-11-11T10:00:00Z'}},
        ],
      },
      'POST reviews/77/report': FakeTransport.ok({'reported': true}, status: 201),
    });
    await pumpApp(tester, state);
    await pushRoute(tester, Routes.reviews, {'slug': 'p1', 'title': 'P1'});
    await settle(tester);
    expect(find.byKey(const ValueKey('review-seller-reply')), findsOneWidget);
    expect(find.text('Thank you, Ali!'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('review-report-77')));
    await settle(tester);
    await tester.tap(find.byKey(const ValueKey('report-fake')));
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('report-submit')));
    await settle(tester);
    expect(transport.bodyOf(transport.sent('POST reviews/77/report').single), {'reason': 'fake'});
    expect(find.text('Thanks — our team will check this review.'), findsOneWidget);
  });

  testWidgets('my reviews list the published status', (tester) async {
    final (state, _) = await makeState(token: 'tok', routes: {
      'GET me/reviews': [
        {'id': 5, 'rating': 5, 'title': 'Great', 'status': 'published', 'product': {'title': 'Air Fryer'}, 'created_at': '2026-11-10T10:00:00Z'},
      ],
    });
    await pumpApp(tester, state);
    await pushRoute(tester, Routes.myReviews);
    await settle(tester);
    expect(find.text('Published'), findsOneWidget);
    expect(find.text('Air Fryer'), findsOneWidget);
  });

  testWidgets('coupon sheet: sign-in-required error and coupon warnings', (tester) async {
    final cart = {...cartFixture(), 'coupons': [{'code': 'BOGO1', 'discount': money('0.00')}], 'warnings': ['Add 2 eligible items to use coupon BOGO1.']};
    final (state, _) = await makeState(routes: {
      'GET cart': cart,
      'POST cart/coupons': FakeTransport.error(422, 'coupon_sign_in_required', 'Sign in to use this coupon.'),
    });
    await pumpApp(tester, state);
    await pushRoute(tester, Routes.cart);
    await settle(tester);
    await tester.tap(find.byKey(const ValueKey('cart-coupons')));
    await settle(tester);
    expect(find.byKey(const ValueKey('coupon-warning')), findsOneWidget);
    await tester.enterText(find.byKey(const ValueKey('coupon-input')), 'FIRST10');
    await tester.tap(find.text('Apply').last);
    await settle(tester);
    expect(find.text('Sign in to use this coupon.'), findsOneWidget);
    expect(find.byKey(const ValueKey('coupon-sign-in')), findsOneWidget);
  });

  testWidgets('bestselling sort is offered and sent', (tester) async {
    final (state, transport) = await makeState(routes: {
      'GET products': (HttpRequestData r) => FakeTransport.ok([], meta: {'total': 0, 'page': 1, 'last_page': 1}),
    });
    await pumpApp(tester, state);
    await pushRoute(tester, Routes.products, {'q': 'fryer'});
    await settle(tester);
    await tester.tap(find.byKey(const ValueKey('chip-sort')));
    await settle(tester);
    await tester.tap(find.byKey(const ValueKey('sort-bestselling')));
    await settle(tester);
    expect(transport.sent('GET products').last.uri.queryParameters['sort'], 'bestselling');
  });

  testWidgets('a video ad creative renders as a play tile', (tester) async {
    final (state, _) = await makeState(routes: {
      'GET pages/home': {
        'slug': 'home',
        'template': 'home',
        'zones': {
          'main': [
            {'type': 'sponsored_video', 'settings': {'placement': 'home_video'}},
          ],
        },
      },
      'GET ads/placements/home_video': [
        {'id': 1, 'image': null, 'video': 'https://cdn.test/ad.mp4', 'headline': 'New blender', 'label': 'Sponsored', 'link_url': null},
      ],
    });
    await pumpApp(tester, state);
    await settle(tester);
    expect(find.byKey(const ValueKey('ad-video')), findsOneWidget);
    expect(find.text('New blender'), findsOneWidget);
  });

  test('AppNotice keeps its target', () {
    const n = AppNotice('auth.expired', target: 'signIn');
    expect(n.target, 'signIn');
  });

  testWidgets('PDP shows the BNPL split only when Tabby / Tamara is offered (QC-064)', (tester) async {
    final (state, transport) = await makeState(routes: {
      'GET products/fryer': {
        'slug': 'fryer', 'title': 'Air Fryer', 'brand': null, 'category': null, 'breadcrumbs': [], 'images': [], 'highlights': [], 'specifications': [],
        'rating': {'average': null, 'count': 0}, 'measurement_fields': [], 'warranty_plans': [], 'other_offers': [],
        'buy_box': {'listing_id': 9, 'options': {}, 'seller': {'slug': 's', 'name': 'S'}, 'price': money('249.00'), 'was_price': null, 'flash_sale': null, 'delivery': null, 'condition': 'new', 'in_stock': 3},
        'from_price': money('249.00'),
      },
      'GET checkout/payment-methods': [
        {'code': 'card', 'type': 'card', 'name': 'Card'},
        {'code': 'tabby', 'type': 'bnpl', 'name': 'Tabby'},
      ],
    });
    await pumpApp(tester, state);
    await pushRoute(tester, Routes.product, {'slug': 'fryer'});
    await settle(tester);
    expect(find.byKey(const ValueKey('pdp-bnpl')), findsOneWidget);
    expect(find.text('Or 4 interest-free payments of AED 62.25 with Tabby'), findsOneWidget);
    expect(transport.sent('GET checkout/payment-methods').single.uri.queryParameters['amount'], '249.00');
  });
}
