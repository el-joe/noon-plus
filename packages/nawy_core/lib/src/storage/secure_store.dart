import 'dart:convert';
import 'dart:io';

import '../platform/platform_bridge.dart';

/// Key-value storage for the Sanctum token and preferences.
///
/// OWNER REVIEW (WP10, P1): [FileSecureStore] is a stop-gap — a JSON file in
/// the app-support directory, readable with root/backup access. Swap it for
/// `flutter_secure_storage` (Keychain / Android Keystore) once approved; only
/// [createDefaultStore] changes.
abstract class SecureStore {
  Future<String?> read(String key);
  Future<void> write(String key, String? value);
  Future<void> delete(String key) => write(key, null);
}

/// In-memory store (tests, web, and fallback when the disk is unavailable).
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

/// JSON file under the app-support directory (owner-approval stop-gap).
class FileSecureStore extends SecureStore {
  FileSecureStore(this.file);

  final File file;
  Map<String, String>? _cache;

  static Future<FileSecureStore> open(String appName) async {
    final dir = Directory(await PlatformBridge.appSupportDir(appName));
    if (!dir.existsSync()) dir.createSync(recursive: true);
    return FileSecureStore(File('${dir.path}${Platform.pathSeparator}store.json'));
  }

  Future<Map<String, String>> _load() async {
    if (_cache != null) return _cache!;
    try {
      if (await file.exists()) {
        final decoded = jsonDecode(await file.readAsString());
        if (decoded is Map) return _cache = decoded.map((k, v) => MapEntry(k.toString(), v.toString()));
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
}

Future<SecureStore> createDefaultStore(String appName) async {
  try {
    return await FileSecureStore.open(appName);
  } catch (_) {
    return MemorySecureStore();
  }
}
