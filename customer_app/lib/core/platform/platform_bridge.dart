import 'dart:async';
import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:nawy_core/device.dart' as core;
import 'package:path_provider/path_provider.dart';

/// Device links for the customer app.
///
/// - Opening web pages (gateway redirects, attachments, the system video
///   player fallback) and `tel:` links: `url_launcher` through the shared
///   `nawy_core` bridge (owner-review D16, P6 / P12).
/// - The app-support directory (where the old file store lived):
///   `path_provider`.
/// - `nawy://` links that started or reached the app (the Google / Apple web
///   sign-in returns to `nawy://auth/social/complete?ticket=…`): the small
///   native `nawy/platform` channel (`initialLink` / `deepLink`), because
///   `url_launcher` cannot receive links. Android: intent filter in
///   `AndroidManifest.xml`; iOS: `CFBundleURLTypes` + `NawyLinks`.
class PlatformBridge {
  const PlatformBridge._();

  static const _channel = MethodChannel('nawy/platform');

  static bool get _mobile => !kIsWeb && (Platform.isAndroid || Platform.isIOS);

  /// Whether `nawy://` links can reach the app (Android and iOS builds).
  static bool get supportsDeepLinks => _mobile;

  static final _links = StreamController<Uri>.broadcast();
  static bool _handlerSet = false;

  /// `nawy://` links pushed by the native side while the app runs.
  static Stream<Uri> get links {
    if (!_handlerSet && _mobile) {
      _handlerSet = true;
      _channel.setMethodCallHandler((call) async {
        if (call.method == 'deepLink') {
          final uri = Uri.tryParse('${call.arguments}');
          if (uri != null) _links.add(uri);
        }
        return null;
      });
    }
    return _links.stream;
  }

  /// The `nawy://` link that cold-started the app, once (null otherwise).
  static Future<Uri?> initialLink() async {
    if (!_mobile) return null;
    try {
      final raw = await _channel.invokeMethod<String>('initialLink');
      return raw == null ? null : Uri.tryParse(raw);
    } on MissingPluginException {
      return null;
    } on PlatformException {
      return null;
    }
  }

  /// The app-support directory: Android `filesDir`, iOS Application Support
  /// (`path_provider`); on desktop the folder the old file store used.
  static Future<String> appSupportDir() async {
    if (kIsWeb) throw UnsupportedError('No file system on the web');
    if (Platform.isAndroid || Platform.isIOS) return (await getApplicationSupportDirectory()).path;
    final env = Platform.environment;
    if (Platform.isLinux) {
      final base = env['XDG_DATA_HOME'] ?? '${env['HOME'] ?? Directory.systemTemp.path}/.local/share';
      return '$base/nawy_customer';
    }
    if (Platform.isMacOS) return '${env['HOME']}/Library/Application Support/nawy_customer';
    if (Platform.isWindows) return '${env['APPDATA'] ?? Directory.systemTemp.path}\\nawy_customer';
    return '${Directory.systemTemp.path}/nawy_customer';
  }

  /// Tests replace the system browser with this.
  @visibleForTesting
  static Future<bool> Function(String url)? debugOpenUrl;

  /// Opens an http(s) [url] in the system browser / player. Returns false
  /// when nothing opened it.
  static Future<bool> openUrl(String url) async {
    final override = debugOpenUrl;
    if (override != null) return override(url);
    return core.PlatformBridge.openUrl(url);
  }

  /// Opens the dialler (P12). Tests see it through [debugOpenUrl] as `tel:…`.
  static Future<bool> callPhone(String? phone) async {
    final override = debugOpenUrl;
    final uri = core.PlatformBridge.telUri(phone);
    if (override != null) return uri == null ? false : override(uri.toString());
    return core.PlatformBridge.callPhone(phone);
  }
}
