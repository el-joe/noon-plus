import 'dart:async';
import 'dart:convert';

import 'package:flutter/widgets.dart';

import '../core/api/api_client.dart';
import '../core/json.dart';
import '../core/platform/platform_bridge.dart';
import '../core/storage/secure_store.dart';
import '../features/auth/social_sign_in.dart';
import 'cart_store.dart';
import 'push_tokens.dart';
import 'recently_viewed.dart';

/// App-wide state (ChangeNotifier, provided by [AppScope]): market (country +
/// language), session, app contexts (ADR 16), badges.
class AppState extends ChangeNotifier {
  AppState({required this.api, required this.store}) {
    api.onAuthFailure ??= handleAuthFailure;
    cart = CartStore(api: api, onChanged: notifyListeners);
    push = PushTokens(api: api, store: store);
    api.onCartToken = (token) {
      final country = countryCode;
      if (country != null) unawaited(store.write('${StoreKeys.cartToken}:$country', token));
    };
  }

  final ApiClient api;
  final SecureStore store;
  late final CartStore cart;
  late final PushTokens push;

  bool ready = false;
  List<Json> countries = const [];
  String? market;
  Json? user;
  List<Json> contexts = const [];
  String activeContext = 'main';
  int unread = 0;

  /// Ask the shell to switch to a tab target (`home`, `cart`, …).
  final ValueNotifier<String?> tabRequest = ValueNotifier<String?>(null);

  /// A message for the app to show once (snack bar), e.g. after the API
  /// signed the customer out. See [AppNotice].
  final ValueNotifier<AppNotice?> notice = ValueNotifier<AppNotice?>(null);

  /// Email of the account signed out by the API, to prefill sign-in.
  String? lastEmail;

  /// `meta.merge_warnings` of the last `POST cart/merge` (lines of the guest
  /// cart that were clipped or skipped when signing in). Shown in the cart.
  List<Json> mergeWarnings = const [];

  String? get token => api.token;
  bool get signedIn => api.token != null;
  bool get hasMarket => market != null;
  String get locale => (market ?? 'uae-en').endsWith('-ar') ? 'ar' : 'en';
  String? get countrySlug => market?.split('-').first;

  /// The country's name in the app language (G-A8 `names.{en,ar}`).
  String countryName(Json? c) => c?.m('names')?.s(locale) ?? c?.s('name') ?? '';

  Json? get country {
    final slug = countrySlug;
    for (final c in countries) {
      if (c.str('code') == slug || c.strings('markets').any((m) => m.startsWith('$slug-'))) return c;
    }
    return null;
  }

  String? get countryCode => country?.s('code') ?? countrySlug;
  String get currency => country?.m('currency')?.s('code') ?? 'AED';
  String get phoneCode => country?.s('phone_code') ?? '';

  Json get context => contexts.firstWhere((c) => c.str('key') == activeContext, orElse: () => contexts.isNotEmpty ? contexts.first : _defaultContext);

  /// Main tab bar (§14c): Home · Categories · Deals · Account · Cart.
  static final List<Json> defaultTabs = [
    {'key': 'home', 'label': '', 'icon': 'home', 'target': 'home'},
    {'key': 'categories', 'label': '', 'icon': 'grid', 'target': 'categories'},
    {'key': 'deals', 'label': '', 'icon': 'tag', 'target': 'deals'},
    {'key': 'account', 'label': '', 'icon': 'user', 'target': 'account'},
    {'key': 'cart', 'label': '', 'icon': 'cart', 'target': 'cart'},
  ];

  static final Json _defaultContext = {
    'key': 'main',
    'name': 'Nawy',
    'theme': {'header_bg': '#F9BB25', 'header_fg': '#0D1526', 'accent': '#3866DF'},
    'home_page': null,
    'tabs': defaultTabs,
  };

  /// Restore the session and market from the store.
  Future<void> init() async {
    final saved = await store.readAll();
    api.token = saved[StoreKeys.token];
    final userJson = saved[StoreKeys.user];
    if (userJson != null) {
      try {
        user = asJson(jsonDecode(userJson));
      } catch (_) {}
    }
    final savedMarket = saved[StoreKeys.market];
    if (savedMarket != null && RegExp(r'^[a-z]{3}-(en|ar)$').hasMatch(savedMarket)) {
      market = savedMarket;
      api.market = savedMarket;
      api.locale = locale;
      api.cartToken = saved['${StoreKeys.cartToken}:$countrySlug'];
    }
    ready = true;
    notifyListeners();
    if (market != null) unawaited(refreshMarketData());
    // Cold start from `nawy://auth/social/complete?…` (sign-in in the browser).
    final link = await PlatformBridge.initialLink();
    if (link != null) unawaited(handleLink(link));
  }

  /// `nawy://` app links: the Google / Apple web sign-in coming back with a
  /// one-time ticket ([SocialSignIn.complete]). Failures become a notice.
  Future<void> handleLink(Uri uri) async {
    if (!SocialSignIn.isCompletionLink(uri)) return;
    try {
      await SocialSignIn(this).complete(uri);
    } on ApiException catch (e) {
      notice.value = AppNotice(SocialSignIn.errorKey(e.code), message: e.code.startsWith('social_') || e.isNetwork ? null : e.message);
    }
  }

  Future<List<Json>> loadCountries() async {
    if (countries.isNotEmpty) return countries;
    final res = await api.get('countries', global: true);
    countries = asJsonList(res.data);
    notifyListeners();
    return countries;
  }

  Future<void> setMarket(String slug) async {
    final countryChanged = slug.split('-').first != countrySlug;
    final previousLocale = market == null ? null : locale;
    market = slug;
    api.market = slug;
    api.locale = locale;
    if (previousLocale != null && previousLocale != locale) unawaited(syncLocale());
    await store.write(StoreKeys.market, slug);
    if (countryChanged) {
      api.cartToken = await store.read('${StoreKeys.cartToken}:${slug.split('-').first}');
      cart.clear();
      contexts = const [];
      activeContext = 'main';
    }
    notifyListeners();
    await refreshMarketData();
  }

  /// Contexts, cart badge and unread badge for the current market.
  Future<void> refreshMarketData() async {
    await Future.wait([
      loadContexts().catchError((_) {}),
      cart.refresh().then((_) {}).catchError((_) {}),
      refreshUnread().catchError((_) {}),
      loadCountries().then((_) {}).catchError((_) {}),
    ]);
  }

  /// `PATCH me {locale}`: emails and in-app notifications follow the
  /// customer's language (QC-028). Best effort.
  Future<void> syncLocale() async {
    if (!signedIn) return;
    try {
      final res = await api.patch('me', body: {'locale': locale});
      final data = asJson(res.data);
      if (data.isNotEmpty) updateUser(data);
    } on ApiException {
      // Offline or refused: the next sign-in sends it again.
    }
  }

  Future<void> loadContexts() async {
    final res = await api.get('app/contexts');
    contexts = asJsonList(res.data);
    if (!contexts.any((c) => c.str('key') == activeContext)) activeContext = contexts.isNotEmpty ? contexts.first.str('key', 'main') : 'main';
    notifyListeners();
  }

  void switchContext(String key) {
    if (key == activeContext) return;
    activeContext = key;
    notifyListeners();
  }

  Future<void> refreshUnread() async {
    if (!signedIn) {
      unread = 0;
      return;
    }
    final data = await api.getObject('me/notifications/unread-count');
    unread = data.i('unread') ?? 0;
    notifyListeners();
  }

  void setUnread(int value) {
    unread = value;
    notifyListeners();
  }

  /// Email-code sign-in result (`{token, user}`): store the token and move the
  /// guest cart into the account (`POST cart/merge`).
  Future<void> signIn(String token, Json signedInUser) async {
    api.token = token;
    user = signedInUser;
    await store.write(StoreKeys.token, token);
    await store.write(StoreKeys.user, jsonEncode(signedInUser));
    notice.value = null;
    notifyListeners();
    // An old phone-only account adds its email first (the API answers `email_required` until then).
    if (signedInUser['requires_email'] == true) return;
    await _afterSignIn(signedInUser);
  }

  /// The old phone-only account confirmed its email (`me/email/verify`): finish the sign-in.
  Future<void> completeEmail(Json account) async {
    updateUser(account);
    await _afterSignIn(account);
  }

  Future<void> _afterSignIn(Json signedInUser) async {
    if (api.cartToken != null) {
      try {
        final res = await api.post('cart/merge');
        mergeWarnings = asJsonList(res.meta['merge_warnings']);
        if (mergeWarnings.isNotEmpty) notice.value = AppNotice('cart.mergeChanged', params: {'count': mergeWarnings.length}, target: 'cart');
      } catch (_) {}
      api.cartToken = null;
      await store.delete('${StoreKeys.cartToken}:$countryCode');
    }
    // The account keeps the language chosen in the app.
    if (signedInUser.s('locale') != locale) unawaited(syncLocale());
    // G-A2: link this device to the account; QC-043: merge recently viewed.
    unawaited(push.register());
    unawaited(syncRecentlyViewed(this).catchError((_) {}));
    await Future.wait([cart.refresh().then((_) {}).catchError((_) {}), refreshUnread().catchError((_) {})]);
  }

  void clearMergeWarnings() {
    mergeWarnings = const [];
    notifyListeners();
  }

  /// The API refused the token: 401 (the 90-day token expired or was
  /// revoked), 403 `account_suspended` or `wrong_token`. Sign out locally and
  /// ask the customer to sign in again.
  Future<void> handleAuthFailure(ApiException error) async {
    if (!signedIn) return;
    lastEmail = user?.s('email') ?? lastEmail;
    final key = switch (error.code) {
      'account_suspended' => 'auth.suspended',
      'wrong_token' || 'no_workspace' => 'auth.wrongToken',
      'email_required' => 'auth.emailRequired',
      _ => 'auth.expired',
    };
    await signOut(revoke: false);
    notice.value = AppNotice(key, target: error.code == 'account_suspended' ? null : 'signIn');
  }

  Future<void> signOut({bool revoke = true}) async {
    if (revoke && api.token != null) {
      await push.unregister();
      try {
        await api.post('auth/logout');
      } catch (_) {}
    }
    api.token = null;
    user = null;
    unread = 0;
    await store.delete(StoreKeys.token);
    await store.delete(StoreKeys.user);
    cart.clear();
    notifyListeners();
  }

  void updateUser(Json value) {
    user = {...?user, ...value};
    unawaited(store.write(StoreKeys.user, jsonEncode(user)));
    notifyListeners();
  }

  /// Small JSON lists kept on the device (recent searches, recently viewed,
  /// guest booking tokens).
  Future<List<Json>> readList(String key) async {
    final raw = await store.read(key);
    if (raw == null) return [];
    try {
      return asJsonList(jsonDecode(raw));
    } catch (_) {
      return [];
    }
  }

  Future<void> writeList(String key, List<Json> values) => store.write(key, jsonEncode(values));

  Future<void> pushRecent(String key, Json entry, {required String idKey, int max = 12}) async {
    final list = await readList(key);
    list.removeWhere((e) => e[idKey] == entry[idKey]);
    list.insert(0, entry);
    await writeList(key, list.take(max).toList());
  }
}

/// A one-off message for the app shell: an l10n [key] with [params], and an
/// optional action [target] (`signIn` opens sign-in, `cart` the cart).
class AppNotice {
  const AppNotice(this.key, {this.params = const {}, this.target, this.message});

  final String key;
  final Map<String, Object?> params;
  final String? target;

  /// Text from the API (already translated), shown instead of [key].
  final String? message;
}

class AppScope extends InheritedNotifier<AppState> {
  const AppScope({super.key, required AppState state, required super.child}) : super(notifier: state);

  static AppState of(BuildContext context) => context.dependOnInheritedWidgetOfExactType<AppScope>()!.notifier!;

  /// Read without subscribing to changes (event handlers).
  static AppState read(BuildContext context) => context.getInheritedWidgetOfExactType<AppScope>()!.notifier!;
}

extension AppContextX on BuildContext {
  AppState get app => AppScope.of(this);
  AppState get appRead => AppScope.read(this);
  ApiClient get api => AppScope.read(this).api;
}
