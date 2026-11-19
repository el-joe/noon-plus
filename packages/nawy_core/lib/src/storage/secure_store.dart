import 'dart:convert';
import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../platform/platform_bridge.dart';

/// Key-value storage for the Sanctum token and preferences.
///
/// Owner-review D16 / P1: values live in the Keychain (iOS) / Android
/// Keystore-backed storage through `flutter_secure_storage`
/// ([KeychainSecureStore]). The old stop-gap JSON file (`store.json` in the
/// app-support directory) is migrated once by [migrateLegacyStore] and then
/// deleted. [MemorySecureStore] is the fake for tests.
abstract class SecureStore {
  Future<String?> read(String key);
  Future<void> write(String key, String? value);
  Future<void> delete(String key) => write(key, null);
}

/// In-memory store (tests, and fallback when secure storage is unavailable).
class MemorySecureStore extends SecureStore {
  MemorySecureStore([Map<String, String>? initial]) : values = {...?initial};

  final Map<String, String> values;

  @override
  Future<String?> read(String key) async => values[key];

  @override
  Future<void> write(String key, String? value) async {
    if (value == null) {
      values.remove(key);
    } else {
      values[key] = value;
    }
  }
}

/// The platform secure storage, behind an interface so tests can fake it.
abstract class SecureVault {
  Future<String?> read(String key);
  Future<void> write(String key, String value);
  Future<void> delete(String key);
}

/// `flutter_secure_storage`: Keychain (first unlock, this device only) on
/// iOS, AES-GCM with a Keystore-wrapped key on Android, WebCrypto on the web.
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
}

/// [SecureStore] over a [SecureVault], with a read cache (the token is read
/// on every start; Keychain calls are slow-ish).
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
}

/// Moves the values of the old stop-gap JSON file into [target] once and
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
Future<File?> legacyStoreFile(String appName) async {
  if (kIsWeb) return null;
  final dir = await PlatformBridge.appSupportDir(appName);
  return File('$dir${Platform.pathSeparator}store.json');
}

/// The app's store: secure storage with the one-off migration of the old
/// file. Falls back to memory (signed out on the next start) when the
/// platform has no secure storage (e.g. Linux without libsecret).
Future<SecureStore> createDefaultStore(String appName, {SecureVault? vault, Future<File?> Function()? legacyFile}) async {
  try {
    final store = KeychainSecureStore(vault ?? FlutterSecureVault());
    await store.read('__probe__');
    final legacy = await (legacyFile ?? () => legacyStoreFile(appName))();
    if (legacy != null) await migrateLegacyStore(legacy, store);
    return store;
  } catch (_) {
    return MemorySecureStore();
  }
}
