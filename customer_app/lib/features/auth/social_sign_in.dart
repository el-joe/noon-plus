import 'dart:convert';
import 'dart:math';

import '../../core/api/api_client.dart';
import '../../core/config.dart';
import '../../core/json.dart';
import '../../core/platform/platform_bridge.dart';
import '../../core/sha256.dart';
import '../../core/storage/secure_store.dart';
import '../../state/app_state.dart';

/// Google / Apple sign-in without the native SDKs (D9). The native packages
/// (`google_sign_in`, `sign_in_with_apple`) need owner approval (owner-review
/// WP10), so the app runs the web OAuth flow in the system browser:
///
/// 1. [start]: `GET auth/social/{provider}/redirect?code_challenge=…` (PKCE
///    S256, the verifier stays on the device) → open the provider URL.
/// 2. The API callback redirects to `nawy://auth/social/complete?ticket=…&
///    market=…` (or `?social_error=…`); Android / iOS hand the link to Dart
///    over `nawy/platform` ([PlatformBridge.links]).
/// 3. [complete]: `POST auth/social/exchange {ticket, device_name,
///    code_verifier}` → token → [AppState.signIn].
///
/// Once the SDKs are approved, they post the identity token (with `nonce`,
/// and Apple's name on the first sign-in) to `auth/social/{provider}/token`.
class SocialSignIn {
  SocialSignIn(this.app, {Random? random}) : _random = random ?? Random.secure();

  final AppState app;
  final Random _random;

  static const providers = ['google', 'apple'];

  /// Test hook; otherwise the buttons show where `nawy://` links work.
  static bool? debugSupported;
  static bool get supported => debugSupported ?? PlatformBridge.supportsDeepLinks;

  /// Opens the provider page (tests replace it).
  static Future<bool> Function(String url) openUrl = PlatformBridge.openUrl;

  static const _unreserved = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~';

  /// A PKCE code verifier: 64 unreserved characters (RFC 7636 §4.1).
  String newVerifier() => List.generate(64, (_) => _unreserved[_random.nextInt(_unreserved.length)]).join();

  /// `base64url(sha256(verifier))` without padding (RFC 7636 §4.2, S256).
  static String challengeFor(String verifier) => base64Url.encode(sha256(ascii.encode(verifier))).replaceAll('=', '');

  static bool isCompletionLink(Uri uri) => uri.scheme == 'nawy' && uri.host == 'auth' && uri.path == '/social/complete';

  Future<void> start(String provider) async {
    if (!providers.contains(provider)) throw ArgumentError.value(provider, 'provider');
    final verifier = newVerifier();
    await app.store.write(StoreKeys.socialPkce, jsonEncode({'provider': provider, 'verifier': verifier, 'market': app.market}));
    final data = await app.api.getObject('auth/social/$provider/redirect', query: {'code_challenge': challengeFor(verifier)});
    final url = data.s('url');
    if (url == null || !await openUrl(url)) {
      throw ApiException(0, 'browser_unavailable', 'Could not open the browser.');
    }
  }

  /// Finishes the sign-in from the app link. False when [uri] is not a
  /// social sign-in link; throws [ApiException] with the API's code on
  /// failure (`social_error` from the link, or the exchange's error).
  Future<bool> complete(Uri uri) async {
    if (!isCompletionLink(uri)) return false;
    final raw = await app.store.read(StoreKeys.socialPkce);
    await app.store.delete(StoreKeys.socialPkce);
    final error = uri.queryParameters['social_error'];
    if (error != null && error.isNotEmpty) throw ApiException(422, error, 'Social sign-in failed.');
    final ticket = uri.queryParameters['ticket'];
    Json? pending;
    try {
      pending = raw == null ? null : asJson(jsonDecode(raw));
    } catch (_) {
      pending = null;
    }
    final verifier = pending?.s('verifier');
    if (ticket == null || ticket.isEmpty || verifier == null) {
      throw ApiException(422, 'social_ticket_invalid', 'This sign-in has expired. Try again.');
    }
    // The ticket only works in the market the sign-in started in.
    final market = uri.queryParameters['market'] ?? pending?.s('market') ?? app.market;
    final res = await app.api.request(
      'POST',
      '$market/auth/social/exchange',
      body: {'ticket': ticket, 'device_name': AppConfig.deviceName, 'code_verifier': verifier},
      global: true,
    );
    final data = asJson(res.data);
    final token = data.s('token');
    if (token == null) throw ApiException(500, 'invalid_response', 'Unexpected sign-in response.');
    await app.signIn(token, data.m('user') ?? const {});
    return true;
  }

  /// The l10n key for a social sign-in failure code.
  static String errorKey(String code) => switch (code) {
        'social_email_unverified' => 'auth.socialEmailUnverified',
        'social_provider_unavailable' => 'auth.socialUnavailable',
        'social_ticket_invalid' || 'social_state_invalid' => 'auth.socialExpired',
        'browser_unavailable' => 'auth.browserUnavailable',
        'account_suspended' => 'auth.suspended',
        _ => 'auth.socialError',
      };
}
