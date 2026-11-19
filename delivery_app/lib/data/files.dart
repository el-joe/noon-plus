import 'package:flutter/foundation.dart' show defaultTargetPlatform, kIsWeb, TargetPlatform;
import 'package:flutter/services.dart';
import 'package:nawy_core/nawy_core.dart';

/// Where a downloaded file (a payslip PDF) was saved.
class SavedFile {
  const SavedFile(this.location, {this.uri, this.mimeType = 'application/pdf'});

  /// What to tell the agent ("Download/Nawy/PS-1.pdf" or a full path).
  final String location;

  /// Android content URI of the saved file (opens in a PDF viewer).
  final String? uri;
  final String mimeType;
}

/// Saves downloads where the agent finds them again and opens them when the
/// device can. Tests pass a fake.
abstract class FileSaver {
  Future<SavedFile> save(String name, List<int> bytes, {String mimeType = 'application/pdf'});

  /// Opens [file] in the device's viewer; false when nothing can.
  Future<bool> open(SavedFile file);
}

/// Android: the public Downloads folder (`Download/Nawy`, MediaStore through
/// the app's `nawy.delivery/files` channel in `MainActivity`), opened with
/// the PDF viewer. iOS and desktop: the app's documents folder
/// ([PlatformBridge.saveDocument]; on iOS shown in the Files app under
/// "Nawy Delivery"). Not available on the web.
class DeviceFileSaver implements FileSaver {
  const DeviceFileSaver();

  static const _channel = MethodChannel('nawy.delivery/files');

  static bool get _android => !kIsWeb && defaultTargetPlatform == TargetPlatform.android;

  @override
  Future<SavedFile> save(String name, List<int> bytes, {String mimeType = 'application/pdf'}) async {
    if (_android) {
      try {
        final saved = await _channel.invokeMapMethod<String, dynamic>('saveToDownloads', {'name': name, 'bytes': Uint8List.fromList(bytes), 'mime': mimeType});
        if (saved != null && saved['path'] is String) return SavedFile(saved['path'] as String, uri: saved['uri'] as String?, mimeType: mimeType);
      } on PlatformException {
        // Fall back to the app's own folder below.
      } on MissingPluginException {
        // Same.
      }
    }
    return SavedFile(await PlatformBridge.saveDocument(name, bytes), mimeType: mimeType);
  }

  @override
  Future<bool> open(SavedFile file) async {
    if (!_android || file.uri == null) return false;
    try {
      return await _channel.invokeMethod<bool>('open', {'uri': file.uri, 'mime': file.mimeType}) ?? false;
    } catch (_) {
      return false;
    }
  }
}
