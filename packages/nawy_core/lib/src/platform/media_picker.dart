
import 'dart:math' as math;

import 'package:file_picker/file_picker.dart';
import 'package:flutter/foundation.dart';
import 'package:image_picker/image_picker.dart';

import '../api/api_client.dart' show FilePart;

/// A photo or file chosen on the device, ready for a multipart upload.
class PickedMedia {
  const PickedMedia({required this.name, required this.bytes, required this.mimeType});

  final String name;
  final Uint8List bytes;

  /// Sniffed from the bytes (`image/jpeg`, `image/png`, …); the file name and
  /// the platform's guess are only used when the bytes say nothing.
  final String mimeType;

  int get size => bytes.length;
  bool get isImage => mimeType.startsWith('image/');

  /// File name with the extension of [mimeType] (`IMG_1.HEIC` → `IMG_1.jpg`
  /// once the picker re-encoded it).
  String get uploadName {
    final ext = extensionFor(mimeType);
    if (ext == null) return name;
    final dot = name.lastIndexOf('.');
    final base = (dot > 0 ? name.substring(0, dot) : name).replaceAll(RegExp(r'[^A-Za-z0-9_\-]'), '_');
    return '${base.isEmpty ? 'file' : base}.$ext';
  }

  FilePart part(String field) => FilePart(field: field, filename: uploadName, bytes: bytes, contentType: mimeType);

  static PickedMedia fromBytes(String name, Uint8List bytes, {String? mimeType}) =>
      PickedMedia(name: name, bytes: bytes, mimeType: sniffMime(bytes) ?? mimeType ?? mimeFromName(name) ?? 'application/octet-stream');
}

/// Content type from the first bytes (magic numbers); null when unknown.
String? sniffMime(List<int> b) {
  bool at(int offset, List<int> sig) {
    if (b.length < offset + sig.length) return false;
    for (var i = 0; i < sig.length; i++) {
      if (b[offset + i] != sig[i]) return false;
    }
    return true;
  }

  if (at(0, const [0xFF, 0xD8, 0xFF])) return 'image/jpeg';
  if (at(0, const [0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A])) return 'image/png';
  if (at(0, const [0x52, 0x49, 0x46, 0x46]) && at(8, const [0x57, 0x45, 0x42, 0x50])) return 'image/webp';
  if (at(0, const [0x47, 0x49, 0x46, 0x38])) return 'image/gif';
  if (at(0, const [0x25, 0x50, 0x44, 0x46])) return 'application/pdf';
  if (at(4, const [0x66, 0x74, 0x79, 0x70])) {
    final brand = String.fromCharCodes(b.sublist(8, b.length < 12 ? b.length : 12));
    if (const {'heic', 'heix', 'mif1', 'msf1', 'hevc'}.contains(brand)) return 'image/heic';
    if (const {'qt  '}.contains(brand)) return 'video/quicktime';
    return 'video/mp4';
  }
  return null;
}

String? mimeFromName(String name) {
  final ext = name.contains('.') ? name.split('.').last.toLowerCase() : '';
  return const {
    'jpg': 'image/jpeg',
    'jpeg': 'image/jpeg',
    'png': 'image/png',
    'webp': 'image/webp',
    'gif': 'image/gif',
    'heic': 'image/heic',
    'pdf': 'application/pdf',
    'mp4': 'video/mp4',
    'mov': 'video/quicktime',
  }[ext];
}

/// File extensions for the document picker from an upload field's types
/// (`application/pdf` → `pdf`, `image/jpeg` → `jpg`, `jpeg`).
List<String> documentExtensionsFor(Set<String> mimeTypes) => [
      for (final m in mimeTypes)
        ...switch (m) {
          'image/jpeg' => const ['jpg', 'jpeg'],
          _ => [?extensionFor(m)],
        },
    ];

String? extensionFor(String mime) => const {
      'image/jpeg': 'jpg',
      'image/png': 'png',
      'image/webp': 'webp',
      'image/gif': 'gif',
      'image/heic': 'heic',
      'application/pdf': 'pdf',
    }[mime];

/// Why a picked file was left out.
enum UploadRejection { tooMany, tooBig, wrongType }

/// The API's limits for one upload field (count, size, types). The values
/// mirror the backend validation rules named on each constant.
class UploadRules {
  const UploadRules({required this.maxCount, required this.maxBytes, required this.mimeTypes});

  final int maxCount;
  final int maxBytes;
  final Set<String> mimeTypes;

  static const jpegPngWebp = {'image/jpeg', 'image/png', 'image/webp'};

  /// Laravel `image` rule: jpg, jpeg, png, bmp, gif, svg, webp.
  static const anyImage = {'image/jpeg', 'image/png', 'image/webp', 'image/gif'};

  /// `StoreReviewRequest`: `photos` max 5, each `image|max:5120`.
  static const reviewPhotos = UploadRules(maxCount: 5, maxBytes: 5120 * 1024, mimeTypes: anyImage);

  /// `StoreReturnRequest`: `photos` max 5, each `image|max:5120`.
  static const returnPhotos = UploadRules(maxCount: 5, maxBytes: 5120 * 1024, mimeTypes: anyImage);

  /// Support tickets: `nawy.support.max_attachments` 5, `attachment_max_kb`
  /// 5120, `attachment_mimes` jpg/jpeg/png/webp/pdf (the picker offers photos).
  static const supportAttachments = UploadRules(maxCount: 5, maxBytes: 5120 * 1024, mimeTypes: {...jpegPngWebp, 'application/pdf'});

  /// `me/avatar`: `image|mimes:jpg,jpeg,png,webp|max:2048`.
  static const avatar = UploadRules(maxCount: 1, maxBytes: 2048 * 1024, mimeTypes: jpegPngWebp);

  /// `classifieds/ads/{n}/photos`: one `photo` per call, `image`,
  /// `classified.photo_max_kb` (8192); per ad `category.max_photos` (20).
  static UploadRules adPhotos({int maxCount = 20, int maxKb = 8192}) => UploadRules(maxCount: maxCount, maxBytes: maxKb * 1024, mimeTypes: anyImage);

  /// Onboarding documents (delivery agent, vendor, marketer…): one file,
  /// `mimes:pdf,jpg,jpeg,png,webp`, `max:` the document type's `max_mb`
  /// (default 10 MB; the API caps uploads at 20 MB).
  static UploadRules document({int maxMb = 10}) => UploadRules(maxCount: 1, maxBytes: maxMb * 1024 * 1024, mimeTypes: {...jpegPngWebp, 'application/pdf'});

  /// Delivery proof photo: `image|max:delivery.proof_max_kb` (8192).
  static const proofPhoto = UploadRules(maxCount: 1, maxBytes: 8192 * 1024, mimeTypes: anyImage);

  String get maxSizeLabel => maxBytes >= 1024 * 1024 ? '${(maxBytes / (1024 * 1024)).toStringAsFixed(maxBytes % (1024 * 1024) == 0 ? 0 : 1)} MB' : '${maxBytes ~/ 1024} KB';

  /// Splits [added] into the files that fit next to [existing] and the
  /// rejected ones with the reason.
  ({List<PickedMedia> accepted, List<(PickedMedia, UploadRejection)> rejected}) check(List<PickedMedia> existing, List<PickedMedia> added) {
    final accepted = <PickedMedia>[];
    final rejected = <(PickedMedia, UploadRejection)>[];
    for (final file in added) {
      if (!mimeTypes.contains(file.mimeType)) {
        rejected.add((file, UploadRejection.wrongType));
      } else if (file.size > maxBytes) {
        rejected.add((file, UploadRejection.tooBig));
      } else if (existing.length + accepted.length >= maxCount) {
        rejected.add((file, UploadRejection.tooMany));
      } else {
        accepted.add(file);
      }
    }
    return (accepted: accepted, rejected: rejected);
  }
}

/// Camera and gallery access. [MediaPicker.current] is the device picker
/// (`image_picker`); tests set a fake (`FakeMediaPicker` in testing.dart).
abstract class MediaPicker {
  /// The picker the widgets use.
  static MediaPicker current = DeviceMediaPicker();

  /// One photo from the camera (null when cancelled or unavailable).
  Future<PickedMedia?> camera({bool fullSize = false});

  /// Up to [limit] photos from the gallery (empty when cancelled).
  Future<List<PickedMedia>> gallery({int limit = 1});

  /// One document file (PDF or a scan) from the device's files
  /// (`file_picker`, D16); null when cancelled or unavailable.
  Future<PickedMedia?> document({List<String> extensions = documentExtensions});

  /// Whether a camera can be offered here.
  bool get hasCamera;

  /// This picker with photos scaled / compressed so one should fit in
  /// [maxBytes] (an upload field's `photo_max_kb`); itself when the default
  /// size already fits. [UploadRules.check] still refuses a photo that does
  /// not, with a message.
  MediaPicker fitting(int maxBytes);

  /// File types offered by [document] by default.
  static const documentExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
}

/// `image_picker`. Photos are scaled to [maxDimension] and re-encoded at
/// [quality] (JPEG; HEIC from iOS becomes JPEG) so they fit the API limits.
class DeviceMediaPicker implements MediaPicker {
  DeviceMediaPicker({ImagePicker? picker, this.maxDimension = 2048, this.quality = 85}) : _picker = picker ?? ImagePicker();

  final ImagePicker _picker;
  final double maxDimension;
  final int quality;

  @override
  MediaPicker fitting(int maxBytes) {
    final (dimension, q) = photoSettingsFor(maxBytes, maxDimension: maxDimension, quality: quality);
    return dimension == maxDimension && q == quality ? this : DeviceMediaPicker(picker: _picker, maxDimension: dimension, quality: q);
  }

  @override
  bool get hasCamera => kIsWeb || defaultTargetPlatform == TargetPlatform.android || defaultTargetPlatform == TargetPlatform.iOS;

  @override
  Future<PickedMedia?> document({List<String> extensions = MediaPicker.documentExtensions}) async {
    try {
      final file = await FilePicker.pickFile(type: FileType.custom, allowedExtensions: extensions);
      return file == null ? null : PickedMedia.fromBytes(file.name, await file.xFile.readAsBytes());
    } catch (_) {
      return null;
    }
  }

  Future<PickedMedia> _read(XFile file) async => PickedMedia.fromBytes(file.name, await file.readAsBytes(), mimeType: file.mimeType);

  @override
  Future<PickedMedia?> camera({bool fullSize = false}) async {
    try {
      final file = await _picker.pickImage(
        source: ImageSource.camera,
        maxWidth: fullSize ? 3072 : maxDimension,
        maxHeight: fullSize ? 3072 : maxDimension,
        imageQuality: quality,
        requestFullMetadata: false,
      );
      return file == null ? null : await _read(file);
    } catch (_) {
      return null;
    }
  }

  @override
  Future<List<PickedMedia>> gallery({int limit = 1}) async {
    try {
      if (limit <= 1) {
        final file = await _picker.pickImage(source: ImageSource.gallery, maxWidth: maxDimension, maxHeight: maxDimension, imageQuality: quality, requestFullMetadata: false);
        return file == null ? const [] : [await _read(file)];
      }
      final files = await _picker.pickMultiImage(maxWidth: maxDimension, maxHeight: maxDimension, imageQuality: quality, limit: limit, requestFullMetadata: false);
      return [for (final f in files.take(limit)) await _read(f)];
    } catch (_) {
      return const [];
    }
  }
}

/// Rough upper bound of a JPEG photo's size: long edge [dimension] px at
/// 4:3, [quality] (detailed photos run about 0.5 bytes per pixel at 85).
int estimatedJpegBytes(double dimension, int quality) {
  final bytesPerPixel = quality >= 85 ? 0.5 : (quality >= 75 ? 0.36 : 0.28);
  return (dimension * dimension * 0.75 * bytesPerPixel).round();
}

/// Long edge and JPEG quality for photos that must fit in [maxBytes]: lower
/// the quality to 65 first, then the size (not under 640 px), keeping 20 %
/// headroom. The defaults when they already fit.
(double, int) photoSettingsFor(int maxBytes, {double maxDimension = 2048, int quality = 85}) {
  final budget = maxBytes * 0.8;
  var dimension = maxDimension;
  var q = quality;
  while (estimatedJpegBytes(dimension, q) > budget) {
    if (q > 65) {
      q = math.max(65, q - 10);
    } else if (dimension > 640) {
      dimension = math.max(640, (dimension * 0.85).floorToDouble());
    } else {
      break;
    }
  }
  return (dimension, q);
}
