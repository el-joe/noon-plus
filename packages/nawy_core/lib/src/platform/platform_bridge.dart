import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:path_provider/path_provider.dart';
import 'package:url_launcher/url_launcher.dart';

/// Device links and paths (owner-review D16, P6 / P12): `url_launcher` opens
/// web pages, the maps app and `tel:` links; `path_provider` gives the
/// app-support directory. Replaces the hand-written `nawy/platform` channel.
class PlatformBridge {
  const PlatformBridge._();

  /// Tests replace the launcher with this (returns whether it "opened").
  @visibleForTesting
  static Future<bool> Function(Uri uri)? debugLaunch;

  static bool get _android => !kIsWeb && Platform.isAndroid;
  static bool get _ios => !kIsWeb && Platform.isIOS;

  static Future<bool> _launch(Uri uri) async {
    final override = debugLaunch;
    if (override != null) return override(uri);
    try {
      return await launchUrl(uri, mode: kIsWeb ? LaunchMode.platformDefault : LaunchMode.externalApplication, webOnlyWindowName: '_blank');
    } catch (_) {
      return false;
    }
  }

  /// The private app-support directory: Android `filesDir`, iOS Application
  /// Support (both through `path_provider`); `~/.local/share/{appName}` and
  /// the like on desktop, where the stop-gap store used to live.
  static Future<String> appSupportDir(String appName) async {
    if (kIsWeb) throw UnsupportedError('No file system on the web');
    if (Platform.isAndroid || Platform.isIOS) return (await getApplicationSupportDirectory()).path;
    final env = Platform.environment;
    if (Platform.isLinux) {
      final base = env['XDG_DATA_HOME'] ?? '${env['HOME'] ?? Directory.systemTemp.path}/.local/share';
      return '$base/$appName';
    }
    if (Platform.isMacOS) return '${env['HOME']}/Library/Application Support/$appName';
    if (Platform.isWindows) return '${env['APPDATA'] ?? Directory.systemTemp.path}\\$appName';
    return '${Directory.systemTemp.path}/$appName';
  }

  /// Saves [bytes] as [name] in the app's documents directory and returns
  /// the full path (iOS: shown in the Files app when the app's Info.plist sets
  /// `UIFileSharingEnabled`; Android: the app's private files). The name is
  /// reduced to letters, digits, `.`, `_` and `-`.
  static Future<String> saveDocument(String name, List<int> bytes) async {
    final override = debugSaveDocument;
    if (override != null) return override(name, bytes);
    if (kIsWeb) throw UnsupportedError('No file system on the web');
    final safe = name.replaceAll(RegExp(r'[^A-Za-z0-9._\-]'), '_');
    final dir = (await getApplicationDocumentsDirectory()).path;
    final file = File('$dir${Platform.pathSeparator}${safe.isEmpty ? 'file' : safe}');
    await file.writeAsBytes(bytes, flush: true);
    return file.path;
  }

  /// Tests replace [saveDocument] with this (returns the "saved" path).
  @visibleForTesting
  static Future<String> Function(String name, List<int> bytes)? debugSaveDocument;

  /// Opens an http(s) [url] in the system browser. Returns false when nothing
  /// opened it (or the URL is not http/https).
  static Future<bool> openUrl(String url) async {
    final uri = Uri.tryParse(url);
    if (uri == null || !(uri.scheme == 'https' || uri.scheme == 'http') || uri.host.isEmpty) return false;
    return _launch(uri);
  }

  /// Google Maps directions URL for [query] (an address line).
  static String mapsUrl(String query) => 'https://www.google.com/maps/dir/?api=1&destination=${Uri.encodeComponent(query)}';

  /// The maps app link for [query]: Android `geo:` (any navigation app),
  /// Apple Maps on iOS, else the Google Maps URL.
  static Uri mapsUri(String query, {bool? android, bool? ios}) {
    if (android ?? _android) return Uri.parse('geo:0,0?q=${Uri.encodeComponent(query)}');
    if (ios ?? _ios) return Uri.parse('https://maps.apple.com/?daddr=${Uri.encodeComponent(query)}');
    return Uri.parse(mapsUrl(query));
  }

  /// Starts navigation to [query] in the device's maps app; falls back to
  /// Google Maps in the browser.
  static Future<bool> openMaps(String query) async {
    if (query.trim().isEmpty) return false;
    final uri = mapsUri(query);
    if (uri.scheme != 'https' && await _launch(uri)) return true;
    return _launch(Uri.parse(mapsUrl(query)));
  }

  /// `tel:` link for a phone number as the API shows it (`+971 50 123 4567`),
  /// keeping a leading `+` and the digits. Null when no digits are left.
  static Uri? telUri(String? phone) {
    if (phone == null) return null;
    final trimmed = phone.trim();
    final digits = trimmed.replaceAll(RegExp(r'[^0-9]'), '');
    if (digits.length < 3) return null;
    return Uri(scheme: 'tel', path: '${trimmed.startsWith('+') || trimmed.startsWith('00') ? '+' : ''}${trimmed.startsWith('00') ? digits.substring(2) : digits}');
  }

  /// Opens the dialler with [phone] (P12 tap-to-call).
  static Future<bool> callPhone(String? phone) async {
    final uri = telUri(phone);
    return uri == null ? false : _launch(uri);
  }
}
