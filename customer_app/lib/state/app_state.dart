import 'dart:async';
import 'dart:convert';

import 'package:flutter/widgets.dart';

import '../core/api/api_client.dart';
import '../core/json.dart';
import '../core/storage/secure_store.dart';
import 'cart_store.dart';

/// App-wide state (ChangeNotifier, provided by [AppScope]): market (country +
/// language), session, app contexts (ADR 16), badges.
class AppState extends ChangeNotifier {
  AppState({required this.api, required this.store}) {
    cart = CartStore(api: api, onChanged: notifyListeners);
    api.onCartToken = (token) {
      final country = countryCode;
      if (country != null) unawaited(store.write('${StoreKeys.cartToken}:$country', token));
    };
  }

  final ApiClient api;
  final SecureStore store;
  late final CartStore cart;

  bool ready = false;
  List<Json> countries = const [];
  String? market;
  Json? user;
  List<Json> contexts = const [];
  String activeContext = 'main';
  int unread = 0;

  /// Ask the shell to switch to a tab target (`home`, `cart`, …).
  final ValueNotifier<String?> tabRequest = ValueNotifier<String?>(null);

  String? get token => api.token;
  bool get signedIn => api.token != null;
  bool get hasMarket => market != null;
  String get locale => (market ?? 'uae-en').endsWith('-ar') ? 'ar' : 'en';
  String? get countrySlug => market?.split('-').first;

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
      api.cartToken = saved['${StoreKeys.cartToken}:$countrySlug'];
    }
    ready = true;
    notifyListeners();
    if (market != null) unawaited(refreshMarketData());
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
    market = slug;
    api.market = slug;
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
    notifyListeners();
    if (api.cartToken != null) {
      try {
        await api.post('cart/merge');
      } catch (_) {}
      api.cartToken = null;
      await store.delete('${StoreKeys.cartToken}:$countryCode');
    }
    await Future.wait([cart.refresh().then((_) {}).catchError((_) {}), refreshUnread().catchError((_) {})]);
  }

  Future<void> signOut({bool revoke = true}) async {
    if (revoke && api.token != null) {
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
