import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';

import 'open_url_web.dart' if (dart.library.io) 'open_url_io.dart' as web;

/// Small native bridge (MethodChannel `nawy/platform`) for what the Flutter
/// SDK alone cannot do, without pub packages:
/// - `appSupportDir`: the private app-support directory (Android `filesDir`,
///   iOS Application Support). Desktop uses env vars instead.
/// - `openUrl`: open a URL in the system browser.
/// - `openMaps`: hand an address / coordinates to the navigation app
///   (Android `geo:` intent, iOS Apple Maps); falls back to a Google Maps URL.
/// - `takePhoto`: Android camera intent (`ACTION_IMAGE_CAPTURE`), returns the
///   JPEG/PNG bytes of the preview image. Null where not implemented.
///
/// TODO(owner): replace with `path_provider`, `url_launcher` and
/// `image_picker` once approved (owner-review WP10).
class PlatformBridge {
  const PlatformBridge._();

  static const channel = MethodChannel('nawy/platform');

  static bool get _mobile => !kIsWeb && (Platform.isAndroid || Platform.isIOS);

  static Future<String> appSupportDir(String appName) async {
    if (kIsWeb) throw UnsupportedError('No file system on the web');
    if (_mobile) {
      final path = await channel.invokeMethod<String>('appSupportDir');
      if (path != null && path.isNotEmpty) return path;
    }
    final env = Platform.environment;
    if (Platform.isLinux) {
      final base = env['XDG_DATA_HOME'] ?? '${env['HOME'] ?? Directory.systemTemp.path}/.local/share';
      return '$base/$appName';
    }
    if (Platform.isMacOS) return '${env['HOME']}/Library/Application Support/$appName';
    if (Platform.isWindows) return '${env['APPDATA'] ?? Directory.systemTemp.path}\\$appName';
    return '${Directory.systemTemp.path}/$appName';
  }

  /// Opens [url] in the system browser. Returns false when nothing opened it.
  static Future<bool> openUrl(String url) async {
    final uri = Uri.tryParse(url);
    if (uri == null || !(uri.scheme == 'https' || uri.scheme == 'http')) return false;
    try {
      if (kIsWeb) return web.openInNewTab(url);
      if (_mobile) return await channel.invokeMethod<bool>('openUrl', {'url': url}) ?? false;
      final command = Platform.isLinux ? 'xdg-open' : Platform.isMacOS ? 'open' : 'explorer';
      final result = await Process.run(command, [url]);
      return result.exitCode == 0 || Platform.isWindows;
    } catch (_) {
      return false;
    }
  }

  /// Google Maps search / directions URL for [query] (an address line).
  static String mapsUrl(String query) => 'https://www.google.com/maps/dir/?api=1&destination=${Uri.encodeComponent(query)}';

  /// Starts navigation to [query] in the device's maps app.
  static Future<bool> openMaps(String query) async {
    if (query.trim().isEmpty) return false;
    if (_mobile) {
      try {
        final ok = await channel.invokeMethod<bool>('openMaps', {'query': query});
        if (ok == true) return true;
      } catch (_) {
        // Fall through to the web URL.
      }
    }
    return openUrl(mapsUrl(query));
  }

  /// Camera photo bytes, or null (cancelled / not supported on this platform).
  static Future<Uint8List?> takePhoto() async {
    if (!_mobile) return null;
    try {
      return await channel.invokeMethod<Uint8List>('takePhoto');
    } on MissingPluginException {
      return null;
    } on PlatformException {
      return null;
    }
  }
}
