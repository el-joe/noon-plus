import 'dart:convert';

import 'package:flutter/widgets.dart';

import 'api/api_client.dart';
import 'config.dart';
import 'json.dart';
import 'storage/secure_store.dart';

/// Signed-in state shared by the delivery and ops apps: the market
/// (`uae-en`), the Sanctum token and the signed-in profile JSON.
class Session extends ChangeNotifier {
  Session({required this.api, required this.store, this.country = 'uae', this.locale = 'en'});

  final ApiClient api;
  final SecureStore store;

  String country;
  String locale;
  Json? profile;
  bool loaded = false;

  static const keyCountry = 'country';
  static const keyLocale = 'locale';
  static const keyToken = 'token';
  static const keyProfile = 'profile';

  String get market => '$country-$locale';
  bool get signedIn => api.token != null;
  bool get isAr => locale == 'ar';

  /// Currency of the selected country (for zero amounts and local maths).
  String get currency => const {
        'uae': 'AED', 'ksa': 'SAR', 'egy': 'EGP', 'kwt': 'KWD',
        'qat': 'QAR', 'bhr': 'BHD', 'omn': 'OMR', 'jor': 'JOD',
      }[country] ?? 'AED';

  Future<void> load() async {
    country = await store.read(keyCountry) ?? country;
    if (!AppConfig.countries.contains(country)) country = 'uae';
    locale = await store.read(keyLocale) ?? locale;
    api.token = await store.read(keyToken);
    final raw = await store.read(keyProfile);
    if (raw != null) {
      try {
        profile = asJson(jsonDecode(raw));
      } catch (_) {
        profile = null;
      }
    }
    api.market = market;
    loaded = true;
    notifyListeners();
  }

  Future<void> setCountry(String code) async {
    country = code;
    api.market = market;
    await store.write(keyCountry, code);
    notifyListeners();
  }

  Future<void> setLocale(String code) async {
    locale = code;
    api.market = market;
    await store.write(keyLocale, code);
    notifyListeners();
  }

  /// Saves the token returned by a login endpoint plus the profile to show.
  Future<void> signedInWith(String token, Json profileJson) async {
    api.token = token;
    profile = profileJson;
    await store.write(keyToken, token);
    await store.write(keyProfile, jsonEncode(profileJson));
    notifyListeners();
  }

  Future<void> updateProfile(Json profileJson) async {
    profile = profileJson;
    await store.write(keyProfile, jsonEncode(profileJson));
    notifyListeners();
  }

  /// Revokes the token on the server ([logoutPath]) when possible and forgets it.
  Future<void> signOut({String? logoutPath, bool revoke = true}) async {
    if (revoke && logoutPath != null && api.token != null) {
      try {
        await api.post(logoutPath);
      } catch (_) {
        // Token already gone or offline: sign out locally anyway.
      }
    }
    api.token = null;
    profile = null;
    await store.delete(keyToken);
    await store.delete(keyProfile);
    notifyListeners();
  }
}

class SessionScope extends InheritedNotifier<Session> {
  const SessionScope({super.key, required Session session, required super.child}) : super(notifier: session);

  static Session of(BuildContext context) => context.dependOnInheritedWidgetOfExactType<SessionScope>()!.notifier!;
  static Session read(BuildContext context) => context.getInheritedWidgetOfExactType<SessionScope>()!.notifier!;
}

extension SessionContext on BuildContext {
  Session get session => SessionScope.read(this);
  ApiClient get api => SessionScope.read(this).api;
}
