import 'dart:typed_data';

import '../platform/media_picker.dart';
import '../storage/secure_store.dart';

/// Smallest valid-looking JPEG header plus [size] padding bytes.
Uint8List fakeJpeg([int size = 64]) => Uint8List.fromList([0xFF, 0xD8, 0xFF, 0xE0, ...List.filled(size, 0)]);

/// `%PDF-1.7` header plus padding.
Uint8List fakePdf([int size = 64]) => Uint8List.fromList([0x25, 0x50, 0x44, 0x46, 0x2D, 0x31, 0x2E, 0x37, ...List.filled(size, 0)]);

PickedMedia fakePdfFile(String name, {int size = 64}) => PickedMedia.fromBytes(name, fakePdf(size));

/// PNG signature plus padding.
Uint8List fakePng([int size = 64]) => Uint8List.fromList([0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A, ...List.filled(size, 0)]);

PickedMedia fakePhoto(String name, {int size = 64, bool png = false}) => PickedMedia.fromBytes(name, png ? fakePng(size) : fakeJpeg(size));

/// Scripted camera / gallery for widget tests (`MediaPicker.current = …`).
class FakeMediaPicker implements MediaPicker {
  FakeMediaPicker({this.cameraResults = const [], this.galleryResults = const [], this.documentResults = const [], this.hasCamera = true});

  /// Returned one per [document] call (null = cancelled); empty → null.
  List<PickedMedia?> documentResults;
  final List<List<String>> documentExtensionRequests = [];

  /// Returned one per [camera] call (null = cancelled); empty → null.
  List<PickedMedia?> cameraResults;

  /// Returned one per [gallery] call; empty → nothing picked.
  List<List<PickedMedia>> galleryResults;

  @override
  bool hasCamera;

  int cameraCalls = 0;

  /// `maxBytes` of each [fitting] call (the same fake is returned).
  final List<int> fittingRequests = [];

  @override
  MediaPicker fitting(int maxBytes) {
    fittingRequests.add(maxBytes);
    return this;
  }

  final List<int> galleryLimits = [];
  final List<bool> fullSizeRequests = [];

  @override
  Future<PickedMedia?> camera({bool fullSize = false}) async {
    fullSizeRequests.add(fullSize);
    final i = cameraCalls++;
    return i < cameraResults.length ? cameraResults[i] : null;
  }

  @override
  Future<PickedMedia?> document({List<String> extensions = MediaPicker.documentExtensions}) async {
    final i = documentExtensionRequests.length;
    documentExtensionRequests.add(extensions);
    return i < documentResults.length ? documentResults[i] : null;
  }

  @override
  Future<List<PickedMedia>> gallery({int limit = 1}) async {
    final i = galleryLimits.length;
    galleryLimits.add(limit);
    return i < galleryResults.length ? galleryResults[i] : const [];
  }
}

/// [SecureVault] in memory, counting calls (tests of the Keychain store).
class FakeSecureVault implements SecureVault {
  FakeSecureVault([Map<String, String>? initial]) : values = {...?initial};

  final Map<String, String> values;
  int reads = 0;
  bool fail = false;

  @override
  Future<String?> read(String key) async {
    if (fail) throw StateError('no keychain');
    reads++;
    return values[key];
  }

  @override
  Future<void> write(String key, String value) async => values[key] = value;

  @override
  Future<void> delete(String key) async => values.remove(key);
}
