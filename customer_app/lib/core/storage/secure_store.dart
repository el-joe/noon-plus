import 'dart:convert';
import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../platform/platform_bridge.dart';

/// Key-value storage for the auth token, guest cart token, guest booking
/// tokens and preferences.
///
/// Owner-review D16 / P1: values live in the Keychain (iOS) / Android
/// Keystore-backed storage through `flutter_secure_storage`
/// ([KeychainSecureStore]). The old stop-gap JSON file (`store.json` in the
/// app-support directory) is copied over once by [migrateLegacyStore] and
/// deleted. [MemorySecureStore] is the fake for tests.
abstract class SecureStore {
  Future<String?> read(String key);
  Future<void> write(String key, String? value);
  Future<void> delete(String key) => write(key, null);
  Future<Map<String, String>> readAll();
}

/// In-memory store (tests, and fallback when secure storage is unavailable).
class MemorySecureStore extends SecureStore {
  MemorySecureStore([Map<String, String>? initial]) : _values = {...?initial};

  final Map<String, String> _values;

  @override
  Future<String?> read(String key) async => _values[key];

  @override
  Future<void> write(String key, String? value) async {
    if (value == null) {
      _values.remove(key);
    } else {
      _values[key] = value;
    }
  }

  @override
  Future<Map<String, String>> readAll() async => Map.unmodifiable(_values);
}

/// The platform secure storage, behind an interface so tests can fake it.
abstract class SecureVault {
  Future<String?> read(String key);
  Future<void> write(String key, String value);
  Future<void> delete(String key);
  Future<Map<String, String>> readAll();
}

/// `flutter_secure_storage`: Keychain (after first unlock, this device only)
/// on iOS, AES-GCM with a Keystore-wrapped key on Android, WebCrypto on the web.
class FlutterSecureVault implements SecureVault {
  FlutterSecureVault([FlutterSecureStorage? storage])
      : storage = storage ??
            const FlutterSecureStorage(
              iOptions: IOSOptions(accessibility: KeychainAccessibility.first_unlock_this_device),
              mOptions: MacOsOptions(accessibility: KeychainAccessibility.first_unlock_this_device),
            );

  final FlutterSecureStorage storage;

  @override
  Future<String?> read(String key) => storage.read(key: key);

  @override
  Future<void> write(String key, String value) => storage.write(key: key, value: value);

  @override
  Future<void> delete(String key) => storage.delete(key: key);

  @override
  Future<Map<String, String>> readAll() => storage.readAll();
}

/// [SecureStore] over a [SecureVault] with a read cache.
class KeychainSecureStore extends SecureStore {
  KeychainSecureStore(this.vault);

  final SecureVault vault;
  final Map<String, String?> _cache = {};

  @override
  Future<String?> read(String key) async {
    if (_cache.containsKey(key)) return _cache[key];
    return _cache[key] = await vault.read(key);
  }

  @override
  Future<void> write(String key, String? value) async {
    if (value == null) {
      await vault.delete(key);
    } else {
      await vault.write(key, value);
    }
    _cache[key] = value;
  }

  @override
  Future<Map<String, String>> readAll() async {
    final all = await vault.readAll();
    _cache
      ..clear()
      ..addAll(all);
    return Map.unmodifiable(all);
  }
}

/// Copies the values of the old stop-gap JSON file into [target] once and
/// deletes the file (and a leftover `.tmp`). Values already in [target] win.
/// A corrupt file is deleted. Returns how many values were copied.
Future<int> migrateLegacyStore(File legacy, SecureStore target) async {
  var copied = 0;
  try {
    if (!await legacy.exists()) return 0;
    Object? decoded;
    try {
      decoded = jsonDecode(await legacy.readAsString());
    } on FormatException {
      decoded = null;
    }
    if (decoded is Map) {
      for (final entry in decoded.entries) {
        final key = entry.key.toString();
        final value = entry.value?.toString();
        if (value == null || await target.read(key) != null) continue;
        await target.write(key, value);
        copied++;
      }
    }
    await legacy.delete();
  } on FileSystemException {
    return copied;
  }
  final tmp = File('${legacy.path}.tmp');
  try {
    if (await tmp.exists()) await tmp.delete();
  } on FileSystemException {
    // Nothing to clean.
  }
  return copied;
}

/// Where the stop-gap kept its JSON file (null on the web).
Future<File?> legacyStoreFile() async {
  if (kIsWeb) return null;
  return File('${await PlatformBridge.appSupportDir()}${Platform.pathSeparator}store.json');
}

/// The app's store: secure storage plus the one-off migration of the old
/// file. Falls back to memory when the platform has no secure storage.
Future<SecureStore> createDefaultStore({SecureVault? vault, Future<File?> Function()? legacyFile}) async {
  try {
    final store = KeychainSecureStore(vault ?? FlutterSecureVault());
    await store.read(StoreKeys.market);
    final legacy = await (legacyFile ?? legacyStoreFile)();
    if (legacy != null) await migrateLegacyStore(legacy, store);
    return store;
  } catch (_) {
    return MemorySecureStore();
  }
}

/// Store keys.
class StoreKeys {
  const StoreKeys._();
  static const market = 'market';
  static const token = 'auth_token';
  static const user = 'user';
  static const cartToken = 'cart_token';
  static const recentSearches = 'recent_searches';
  static const recentlyViewed = 'recently_viewed';
  static const guestBookings = 'guest_bookings';
  static const onboarded = 'onboarded';
  static const socialPkce = 'social_pkce';
  static const installId = 'install_id';
}
