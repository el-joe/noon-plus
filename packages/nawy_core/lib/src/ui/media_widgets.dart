import 'package:flutter/material.dart';

import '../l10n.dart';
import '../platform/media_picker.dart';
import '../platform/platform_bridge.dart';
import 'theme.dart';

/// Translator for the shared widgets: apps with their own string tables
/// (customer_app) pass theirs; the ops apps use the core `media.*` strings.
typedef Translate = String Function(String key, [Map<String, Object?>? params]);

/// Messages for the files [UploadRules.check] left out.
List<String> rejectionMessages(List<(PickedMedia, UploadRejection)> rejected, UploadRules rules, Translate t) => <String>{
      for (final (file, reason) in rejected)
        switch (reason) {
          UploadRejection.tooBig => t('media.tooBig', {'name': file.name, 'size': rules.maxSizeLabel}),
          UploadRejection.wrongType => t('media.wrongType', {'name': file.name}),
          UploadRejection.tooMany => t('media.tooMany', {'max': rules.maxCount}),
        },
    }.toList();

/// Photos for an upload field (review / return / ad photos, ticket
/// attachments): thumbnails with remove buttons plus "Take photo",
/// "Choose photos" and, when [rules] take PDFs, "Choose file (PDF)". Photos are picked sized for [rules]' size limit
/// ([MediaPicker.fitting]); [rules] (count, size, types) are enforced before
/// anything is sent and the user is told why a file was left out.
class AttachmentPicker extends StatelessWidget {
  const AttachmentPicker({super.key, required this.files, required this.onChanged, required this.rules, this.t, this.enabled = true, this.picker});

  final List<PickedMedia> files;
  final ValueChanged<List<PickedMedia>> onChanged;
  final UploadRules rules;
  final Translate? t;
  final bool enabled;

  /// Defaults to [MediaPicker.current].
  final MediaPicker? picker;

  Translate _t(BuildContext context) => t ?? context.t;

  /// The field takes PDFs (support tickets), so "Choose file" is offered.
  bool get _takesFiles => rules.mimeTypes.contains('application/pdf');

  Future<void> _add(BuildContext context, {required String from}) async {
    // Photos come scaled / compressed to fit the field's size limit.
    final source = (picker ?? MediaPicker.current).fitting(rules.maxBytes);
    final room = rules.maxCount - files.length;
    if (room <= 0) return;
    final picked = switch (from) {
      'camera' => [?await source.camera()],
      'file' => [?await source.document(extensions: documentExtensionsFor(rules.mimeTypes))],
      _ => await source.gallery(limit: room),
    };
    if (picked.isEmpty || !context.mounted) return;
    final result = rules.check(files, picked);
    if (result.accepted.isNotEmpty) onChanged([...files, ...result.accepted]);
    final messages = rejectionMessages(result.rejected, rules, _t(context));
    if (messages.isNotEmpty) {
      ScaffoldMessenger.maybeOf(context)?.showSnackBar(SnackBar(content: Text(messages.join('\n'))));
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = _t(context);
    final source = picker ?? MediaPicker.current;
    final full = files.length >= rules.maxCount;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: [
        if (files.isNotEmpty)
          Wrap(spacing: 8, runSpacing: 8, children: [
            for (final (i, file) in files.indexed)
              Stack(clipBehavior: Clip.none, children: [
                ClipRRect(
                  borderRadius: BorderRadius.circular(NawyRadius.sm),
                  child: Container(
                    width: 64,
                    height: 64,
                    color: NawyColors.bgPage,
                    child: file.isImage
                        ? Image.memory(file.bytes, key: Key('attachment-$i'), fit: BoxFit.cover, errorBuilder: (_, _, _) => const Icon(Icons.image_outlined))
                        : Icon(Icons.picture_as_pdf_outlined, key: Key('attachment-$i'), color: NawyColors.textSecondary),
                  ),
                ),
                PositionedDirectional(
                  top: -8,
                  end: -8,
                  child: IconButton(
                    key: Key('attachment-remove-$i'),
                    visualDensity: VisualDensity.compact,
                    tooltip: t('media.remove'),
                    onPressed: enabled ? () => onChanged([...files]..removeAt(i)) : null,
                    icon: const CircleAvatar(radius: 10, backgroundColor: NawyColors.bgInverse, child: Icon(Icons.close, size: 12, color: NawyColors.textInverse)),
                  ),
                ),
              ]),
          ]),
        const SizedBox(height: 8),
        Wrap(spacing: 8, runSpacing: 4, crossAxisAlignment: WrapCrossAlignment.center, children: [
          if (source.hasCamera)
            OutlinedButton.icon(
              key: const Key('attach-camera'),
              onPressed: enabled && !full ? () => _add(context, from: 'camera') : null,
              icon: const Icon(Icons.photo_camera_outlined, size: 18),
              label: Text(t('media.camera')),
            ),
          OutlinedButton.icon(
            key: const Key('attach-gallery'),
            onPressed: enabled && !full ? () => _add(context, from: 'gallery') : null,
            icon: const Icon(Icons.photo_library_outlined, size: 18),
            label: Text(t('media.gallery')),
          ),
          if (_takesFiles)
            OutlinedButton.icon(
              key: const Key('attach-file'),
              onPressed: enabled && !full ? () => _add(context, from: 'file') : null,
              icon: const Icon(Icons.picture_as_pdf_outlined, size: 18),
              label: Text(t('media.file')),
            ),
          Text(
            files.isEmpty ? t('media.limits', {'max': rules.maxCount, 'size': rules.maxSizeLabel}) : t('media.count', {'n': files.length, 'max': rules.maxCount}),
            style: NawyText.caption.copyWith(color: NawyColors.textSecondary),
          ),
        ]),
      ],
    );
  }
}

/// One document file for an upload (delivery-agent, store or marketer
/// documents, contracts): "Take photo", "Choose photo" and "Choose file"
/// (PDF through `file_picker`, D16). Shows the chosen file and enforces
/// [rules] (type, size) before anything is sent.
class DocumentFileField extends StatelessWidget {
  const DocumentFileField({super.key, required this.file, required this.onChanged, this.rules, this.t, this.enabled = true, this.picker});

  final PickedMedia? file;
  final ValueChanged<PickedMedia?> onChanged;

  /// Defaults to [UploadRules.document] (10 MB, PDF / JPEG / PNG / WebP).
  final UploadRules? rules;
  final Translate? t;
  final bool enabled;

  /// Defaults to [MediaPicker.current].
  final MediaPicker? picker;

  UploadRules get _rules => rules ?? UploadRules.document();

  Future<void> _pick(BuildContext context, String from) async {
    final source = picker ?? MediaPicker.current;
    final picked = switch (from) {
      'camera' => await source.camera(fullSize: true),
      'gallery' => (await source.gallery()).firstOrNull,
      _ => await source.document(),
    };
    if (picked == null || !context.mounted) return;
    final result = _rules.check(const [], [picked]);
    if (result.accepted.isNotEmpty) {
      onChanged(result.accepted.first);
      return;
    }
    final messages = rejectionMessages(result.rejected, _rules, t ?? context.t);
    ScaffoldMessenger.maybeOf(context)?.showSnackBar(SnackBar(content: Text(messages.join('\n'))));
  }

  @override
  Widget build(BuildContext context) {
    final translate = t ?? context.t;
    final source = picker ?? MediaPicker.current;
    final chosen = file;
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
      Container(
        key: const Key('document-file'),
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(color: NawyColors.bgPage, borderRadius: BorderRadius.circular(NawyRadius.sm), border: Border.all(color: NawyColors.borderDefault)),
        child: Row(children: [
          Icon(chosen == null ? Icons.upload_file_outlined : (chosen.isImage ? Icons.image_outlined : Icons.picture_as_pdf_outlined), color: NawyColors.textSecondary),
          const SizedBox(width: 12),
          Expanded(
            child: Text(
              chosen == null ? translate('media.noFile') : '${chosen.name} · ${UploadRules(maxCount: 1, maxBytes: chosen.size, mimeTypes: const {}).maxSizeLabel}',
              style: NawyText.bodySm,
              overflow: TextOverflow.ellipsis,
            ),
          ),
          if (chosen != null)
            IconButton(key: const Key('document-remove'), tooltip: translate('media.remove'), onPressed: enabled ? () => onChanged(null) : null, icon: const Icon(Icons.close, size: 18)),
        ]),
      ),
      const SizedBox(height: 8),
      Wrap(spacing: 8, runSpacing: 4, crossAxisAlignment: WrapCrossAlignment.center, children: [
        if (source.hasCamera)
          OutlinedButton.icon(key: const Key('document-camera'), onPressed: enabled ? () => _pick(context, 'camera') : null, icon: const Icon(Icons.photo_camera_outlined, size: 18), label: Text(translate('media.camera'))),
        OutlinedButton.icon(key: const Key('document-gallery'), onPressed: enabled ? () => _pick(context, 'gallery') : null, icon: const Icon(Icons.photo_library_outlined, size: 18), label: Text(translate('media.photo'))),
        OutlinedButton.icon(key: const Key('document-pdf'), onPressed: enabled ? () => _pick(context, 'file') : null, icon: const Icon(Icons.picture_as_pdf_outlined, size: 18), label: Text(translate('media.file'))),
        Text(translate('media.documentLimits', {'size': _rules.maxSizeLabel}), style: NawyText.caption.copyWith(color: NawyColors.textSecondary)),
      ]),
    ]);
  }
}

/// A phone number that opens the dialler (`tel:`, P12). Shown left to right
/// in Arabic too.
class PhoneLink extends StatelessWidget {
  const PhoneLink(this.phone, {super.key, this.t, this.style});

  final String phone;
  final Translate? t;
  final TextStyle? style;

  @override
  Widget build(BuildContext context) {
    final translate = t ?? context.t;
    final callable = PlatformBridge.telUri(phone) != null;
    return Semantics(
      button: callable,
      label: translate('media.call', {'phone': phone}),
      excludeSemantics: true,
      child: InkWell(
        onTap: callable ? () => PlatformBridge.callPhone(phone) : null,
        borderRadius: BorderRadius.circular(NawyRadius.sm),
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 4),
          child: Row(mainAxisSize: MainAxisSize.min, children: [
            Icon(Icons.call_outlined, size: 16, color: callable ? NawyColors.textLink : NawyColors.textMuted),
            const SizedBox(width: 6),
            Text(phone, textDirection: TextDirection.ltr, style: (style ?? NawyText.bodyMedium).copyWith(color: callable ? NawyColors.textLink : null)),
          ]),
        ),
      ),
    );
  }
}
