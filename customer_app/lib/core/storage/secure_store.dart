import 'dart:convert';
import 'dart:io';

import '../platform/platform_bridge.dart';

/// Key-value storage for the auth token, guest cart token, guest booking
/// tokens and preferences.
///
/// OWNER REVIEW (WP10): [FileSecureStore] is a stop-gap — a JSON file in the
/// app-support directory, readable by anyone with root/backup access. Swap it
/// for `flutter_secure_storage` (Keychain / Android Keystore) once the owner
/// approves the package; only [createDefaultStore] changes. See
/// docs/project/owner-review.md → WP10.
abstract class SecureStore {
  Future<String?> read(String key);
  Future<void> write(String key, String? value);
  Future<void> delete(String key) => write(key, null);
  Future<Map<String, String>> readAll();
}

/// In-memory store (tests, and fallback when the disk is unavailable).
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

/// JSON file under the app-support directory (owner-approval stop-gap).
class FileSecureStore extends SecureStore {
  FileSecureStore(this.file);

  final File file;
  Map<String, String>? _cache;

  static Future<FileSecureStore> open() async {
    final dir = Directory(await PlatformBridge.appSupportDir());
    if (!dir.existsSync()) dir.createSync(recursive: true);
    return FileSecureStore(File('${dir.path}${Platform.pathSeparator}store.json'));
  }

  Future<Map<String, String>> _load() async {
    if (_cache != null) return _cache!;
    try {
      if (await file.exists()) {
        final decoded = jsonDecode(await file.readAsString());
        if (decoded is Map) {
          return _cache = decoded.map((k, v) => MapEntry(k.toString(), v.toString()));
        }
      }
    } catch (_) {
      // Corrupt file: start again.
    }
    return _cache = {};
  }

  @override
  Future<String?> read(String key) async => (await _load())[key];

  @override
  Future<void> write(String key, String? value) async {
    final values = await _load();
    if (value == null) {
      values.remove(key);
    } else {
      values[key] = value;
    }
    final tmp = File('${file.path}.tmp');
    await tmp.writeAsString(jsonEncode(values), flush: true);
    await tmp.rename(file.path);
  }

  @override
  Future<Map<String, String>> readAll() async => Map.unmodifiable(await _load());
}

Future<SecureStore> createDefaultStore() async {
  try {
    return await FileSecureStore.open();
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
}
