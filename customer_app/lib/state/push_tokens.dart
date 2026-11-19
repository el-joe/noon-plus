import 'dart:io' show Platform;
import 'dart:math';

import 'package:flutter/foundation.dart';

import '../core/api/api_client.dart';
import '../core/storage/secure_store.dart';

/// Push device token (G-A2, `me/push-tokens`). There is no FCM package yet
/// (owner-review WP10), so the app registers a placeholder token made from a
/// stable install id: the account ↔ device link is ready, and the real FCM /
/// APNs token replaces [deviceToken] once a push package is approved.
class PushTokens {
  PushTokens({required this.api, required this.store, Random? random}) : _random = random ?? Random.secure();

  final ApiClient api;
  final SecureStore store;
  final Random _random;

  /// A random id created once per install and kept in the secure store.
  Future<String> installId() async {
    final saved = await store.read(StoreKeys.installId);
    if (saved != null && RegExp(r'^[0-9a-f]{32}$').hasMatch(saved)) return saved;
    final id = List.generate(16, (_) => _random.nextInt(256).toRadixString(16).padLeft(2, '0')).join();
    await store.write(StoreKeys.installId, id);
    return id;
  }

  /// The token sent to the API (16–512 characters).
  Future<String> deviceToken() async => 'nawy-install-${await installId()}';

  static String get platform {
    if (kIsWeb) return 'web';
    try {
      if (Platform.isIOS || Platform.isMacOS) return 'ios';
    } catch (_) {}
    return 'android';
  }

  /// `POST me/push-tokens` after sign-in. Best effort.
  Future<bool> register() async {
    try {
      await api.post('me/push-tokens', body: {'token': await deviceToken(), 'platform': platform, 'app': 'customer'});
      return true;
    } on ApiException {
      return false;
    }
  }

  /// `DELETE me/push-tokens` before sign-out. Best effort.
  Future<bool> unregister() async {
    try {
      await api.delete('me/push-tokens', body: {'token': await deviceToken()});
      return true;
    } on ApiException {
      return false;
    }
  }
}
