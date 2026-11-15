import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';

import 'open_url_web.dart' if (dart.library.io) 'open_url_io.dart' as web;

/// Small native bridge (MethodChannel `nawy/platform`) for what the Flutter
/// SDK alone cannot do, without pub packages:
/// - `appSupportDir`: the private app-support directory (Android
///   `filesDir`, iOS Application Support). Desktop uses env vars instead.
/// - `openUrl`: open a URL in the system browser (gateway redirects, HLS).
///
/// TODO(owner): replace with `path_provider` + `url_launcher` once approved.
class PlatformBridge {
  const PlatformBridge._();

  static const _channel = MethodChannel('nawy/platform');

  static Future<String> appSupportDir() async {
    if (kIsWeb) throw UnsupportedError('No file system on the web');
    if (Platform.isAndroid || Platform.isIOS) {
      final path = await _channel.invokeMethod<String>('appSupportDir');
      if (path != null && path.isNotEmpty) return path;
    }
    final env = Platform.environment;
    if (Platform.isLinux) {
      final base = env['XDG_DATA_HOME'] ?? '${env['HOME'] ?? Directory.systemTemp.path}/.local/share';
      return '$base/nawy_customer';
    }
    if (Platform.isMacOS) return '${env['HOME']}/Library/Application Support/nawy_customer';
    if (Platform.isWindows) return '${env['APPDATA'] ?? Directory.systemTemp.path}\\nawy_customer';
    return '${Directory.systemTemp.path}/nawy_customer';
  }

  /// Opens [url] in the system browser. Returns false when nothing opened it.
  static Future<bool> openUrl(String url) async {
    final uri = Uri.tryParse(url);
    if (uri == null || !(uri.scheme == 'https' || uri.scheme == 'http')) return false;
    try {
      if (kIsWeb) return web.openInNewTab(url);
      if (Platform.isAndroid || Platform.isIOS) {
        return await _channel.invokeMethod<bool>('openUrl', {'url': url}) ?? false;
      }
      final command = Platform.isLinux ? 'xdg-open' : Platform.isMacOS ? 'open' : 'explorer';
      final result = await Process.run(command, [url]);
      return result.exitCode == 0 || Platform.isWindows;
    } catch (_) {
      return false;
    }
  }
}
