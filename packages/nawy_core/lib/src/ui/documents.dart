import 'package:flutter/material.dart';

import '../api/api_client.dart';
import '../json.dart';
import '../l10n.dart';
import '../platform/media_picker.dart';
import '../platform/platform_bridge.dart';
import '../session.dart';
import 'media_widgets.dart';
import 'theme.dart';
import 'widgets.dart';

/// `{base}` = `ops/vendor/documents`, `ops/marketer/documents`,
/// `ops/supervisor/documents` or `ops/travel/documents` (the delivery app
/// has its own `delivery/me/documents` screen with the same shape):
/// `GET {base}` → `{requirements, uploads, missing}`, `POST {base}`
/// multipart `document_type_id`, `file`, `valid_until`, and
/// `GET {base}/{id}/file` for the workspace's own file.
class PartnerDocumentsApi {
  PartnerDocumentsApi(this.api, this.base);

  final ApiClient api;
  final String base;

  Future<Json> list() => api.getObject(base);

  /// 422 field errors come back on `file` / `valid_until` / `document_type_id`.
  Future<Json> upload({required int typeId, required PickedMedia file, String? validUntil}) async => asJson((await api.multipart(
        base,
        fields: {'document_type_id': '$typeId', 'valid_until': ?validUntil},
        files: [file.part('file')],
      ))
          .data);

  Future<ApiFile> file(int id) => api.download('$base/$id/file');
}

/// One required (or optional) document type with its newest upload.
class PartnerDocumentRow {
  PartnerDocumentRow(this.requirement, this.upload, {required this.missing});

  final Json requirement;
  final Json? upload;

  /// Listed in `missing` by the API (no usable document of this type).
  final bool missing;

  int get typeId => requirement.i('id') ?? 0;
  bool get mandatory => requirement.b('mandatory');
  bool get hasExpiry => requirement.b('has_expiry');
  int get maxMb => requirement.i('max_mb') ?? 10;

  /// `missing`, or the upload's `pending` / `approved` / `rejected` / `expired`.
  String get status => upload == null ? 'missing' : upload!.str('status', 'pending');

  /// "Upload", "Re-upload" (rejected / expired: same row back to pending) or "Replace".
  String get actionKey => switch (status) {
        'missing' => 'documents.upload',
        'rejected' || 'expired' => 'documents.reupload',
        _ => 'documents.replace',
      };

  /// Expiry warning: approved and within the API's `expiring_soon` window.
  bool get expiringSoon => (upload?.b('expiring_soon') ?? false) && status != 'expired';

  static List<PartnerDocumentRow> from(Json data) {
    final uploads = data.l('uploads');
    final listed = data['missing'] is List ? {for (final v in data['missing'] as List) int.tryParse('$v')} : null;
    return [
      for (final r in data.l('requirements'))
        _row(r, uploads.where((u) => u.i('document_type_id') == r.i('id')).firstOrNull, listed),
    ];
  }

  static PartnerDocumentRow _row(Json r, Json? upload, Set<int?>? listed) {
    final missing = listed?.contains(r.i('id')) ?? (upload == null || const {'rejected', 'expired'}.contains(upload.s('status')));
    return PartnerDocumentRow(r, upload, missing: missing);
  }
}

/// Documents of an ops-app workspace (store, marketer, shipping company,
/// travel agency): what the country requires, each document's review
/// status, expiry warnings, the rejection reason, upload / re-upload by
/// camera, gallery or PDF ([DocumentFileField]) and the own file download.
class PartnerDocumentsScreen extends StatefulWidget {
  const PartnerDocumentsScreen({super.key, required this.base, this.picker, this.subtitleKey});

  /// API path, e.g. `ops/vendor/documents`.
  final String base;

  /// Camera / gallery / files (tests pass a fake); defaults to [MediaPicker.current].
  final MediaPicker? picker;

  /// Optional l10n key of a line under the title (who reviews the documents).
  final String? subtitleKey;

  @override
  State<PartnerDocumentsScreen> createState() => _PartnerDocumentsScreenState();
}

class _PartnerDocumentsScreenState extends State<PartnerDocumentsScreen> {
  final _key = GlobalKey<AsyncBodyState<Json>>();

  PartnerDocumentsApi get _api => PartnerDocumentsApi(context.api, widget.base);

  Future<void> _upload(PartnerDocumentRow row) async {
    final api = _api;
    final done = await Navigator.of(context).push<bool>(MaterialPageRoute(builder: (_) => PartnerDocumentUploadScreen(row: row, api: api, picker: widget.picker)));
    if (done == true) await _key.currentState?.reload();
  }

  Future<void> _download(Json upload) async {
    try {
      final file = await _api.file(upload.i('id') ?? 0);
      final path = await PlatformBridge.saveDocument(file.filename ?? upload.str('original_name', 'document'), file.bytes);
      if (mounted) showSnack(context, context.t('documents.saved', {'path': path}));
    } catch (e) {
      if (mounted) showSnack(context, apiErrorMessage(context, e));
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(context.t('documents.title'))),
        body: AsyncBody<Json>(
          key: _key,
          load: () => _api.list(),
          builder: (context, data, _) {
            final rows = PartnerDocumentRow.from(data);
            final missingRequired = rows.where((r) => r.mandatory && r.missing).toList();
            final expiring = rows.where((r) => r.expiringSoon).toList();
            return ListView(padding: const EdgeInsets.all(NawySpace.gutter), children: [
              if (widget.subtitleKey != null)
                Padding(
                  padding: const EdgeInsets.only(bottom: 12),
                  child: Text(context.t(widget.subtitleKey!), style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary)),
                ),
              if (missingRequired.isNotEmpty)
                Card(
                  key: const Key('documents-missing-banner'),
                  color: NawyColors.bgWarningSubtle,
                  child: ListTile(
                    leading: const Icon(Icons.warning_amber_rounded, color: NawyColors.textBrand),
                    title: Text(context.t('documents.missingTitle')),
                    subtitle: Text(context.t('documents.missingBody', {'names': missingRequired.map((r) => r.requirement.str('name')).join(', ')})),
                  ),
                ),
              if (expiring.isNotEmpty)
                Card(
                  key: const Key('documents-expiring-banner'),
                  color: NawyColors.bgWarningSubtle,
                  child: ListTile(
                    leading: const Icon(Icons.schedule, color: NawyColors.textBrand),
                    title: Text(context.t('documents.expiringTitle')),
                    subtitle: Text(expiring.map((r) => r.requirement.str('name')).join(', ')),
                  ),
                ),
              for (final row in rows) PartnerDocumentCard(row: row, onUpload: () => _upload(row), onDownload: row.upload == null ? null : () => _download(row.upload!)),
              if (rows.isEmpty) EmptyView(icon: Icons.description_outlined, title: context.t('documents.none')),
            ]);
          },
        ),
      );
}

/// A document type: status chip, required / optional, valid-until date,
/// expiry warning, rejection reason, upload and view buttons.
class PartnerDocumentCard extends StatelessWidget {
  const PartnerDocumentCard({super.key, required this.row, required this.onUpload, this.onDownload});

  final PartnerDocumentRow row;
  final VoidCallback onUpload;
  final VoidCallback? onDownload;

  @override
  Widget build(BuildContext context) {
    final upload = row.upload;
    final validUntil = upload?.s('valid_until');
    final days = upload?.i('expires_in_days');
    final reason = upload?.s('rejection_reason');
    return Card(
      key: Key('document-${row.typeId}'),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Expanded(child: Text(row.requirement.str('name'), style: NawyText.bodyMedium)),
            StatusChip(row.status, key: Key('document-status-${row.typeId}'), label: row.status == 'missing' ? context.t('documents.missing') : null),
          ]),
          const SizedBox(height: 2),
          Text(context.t(row.mandatory ? 'documents.mandatory' : 'documents.optional'), style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary)),
          if (row.status == 'pending') ...[
            const SizedBox(height: 4),
            Text(context.t('documents.pendingHint'), style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary)),
          ],
          if (validUntil != null) ...[
            const SizedBox(height: 4),
            Text(context.t(row.status == 'expired' ? 'documents.expiredOn' : 'documents.validUntil', {'date': validUntil}), style: NawyText.bodySm),
          ],
          if (row.expiringSoon && days != null) ...[
            const SizedBox(height: 4),
            Row(key: Key('document-expiring-${row.typeId}'), children: [
              const Icon(Icons.schedule, size: 16, color: NawyColors.textBrand),
              const SizedBox(width: 4),
              Expanded(
                child: Text(
                  days <= 0 ? context.t('documents.expiresToday') : context.t('documents.expiresIn', {'days': days}),
                  style: NawyText.bodySm.copyWith(color: NawyColors.textBrand),
                ),
              ),
            ]),
          ],
          if (row.status == 'rejected' && reason != null && reason.trim().isNotEmpty) ...[
            const SizedBox(height: 4),
            Text(context.t('documents.rejectedBecause', {'reason': reason}), key: Key('document-reason-${row.typeId}'), style: NawyText.bodySm.copyWith(color: NawyColors.textDanger)),
          ],
          Row(mainAxisAlignment: MainAxisAlignment.end, children: [
            if (onDownload != null)
              TextButton.icon(
                key: Key('document-view-${row.typeId}'),
                onPressed: onDownload,
                icon: const Icon(Icons.download_outlined, size: 18),
                label: Text(context.t('documents.view')),
              ),
            TextButton.icon(
              key: Key('document-upload-${row.typeId}'),
              onPressed: onUpload,
              icon: const Icon(Icons.upload_file_outlined, size: 18),
              label: Text(context.t(row.actionKey)),
            ),
          ]),
        ]),
      ),
    );
  }
}

/// Upload (or replace / re-upload) one document: the file and, for types
/// with an expiry, the "valid until" date (after today).
class PartnerDocumentUploadScreen extends StatefulWidget {
  const PartnerDocumentUploadScreen({super.key, required this.row, required this.api, this.picker, this.today});

  final PartnerDocumentRow row;
  final PartnerDocumentsApi api;
  final MediaPicker? picker;

  /// "Today" for the date picker (tests).
  final DateTime? today;

  @override
  State<PartnerDocumentUploadScreen> createState() => _PartnerDocumentUploadScreenState();
}

class _PartnerDocumentUploadScreenState extends State<PartnerDocumentUploadScreen> {
  PickedMedia? _file;
  DateTime? _validUntil;
  bool _busy = false;
  bool _tried = false;
  Map<String, String> _serverErrors = const {};

  DateTime get _today {
    final now = widget.today ?? DateTime.now();
    return DateTime(now.year, now.month, now.day);
  }

  static String _ymd(DateTime d) => '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

  String? get _fileError => _serverErrors['file'] ?? _serverErrors['document_type_id'] ?? (_tried && _file == null ? context.t('documents.fileRequired') : null);

  String? get _dateError => _serverErrors['valid_until'] ?? (_tried && widget.row.hasExpiry && _validUntil == null ? context.t('documents.expiryRequired') : null);

  Future<void> _pickDate() async {
    final first = _today.add(const Duration(days: 1));
    final picked = await showDatePicker(
      context: context,
      initialDate: _validUntil ?? DateTime(first.year + 1, first.month, first.day),
      firstDate: first,
      lastDate: DateTime(_today.year + 20),
      helpText: context.t('documents.validUntilLabel'),
    );
    if (picked != null && mounted) {
      setState(() {
        _validUntil = picked;
        _serverErrors = {..._serverErrors}..remove('valid_until');
      });
    }
  }

  Future<void> _submit() async {
    setState(() => _tried = true);
    final file = _file;
    if (file == null || (widget.row.hasExpiry && _validUntil == null)) return;
    setState(() {
      _busy = true;
      _serverErrors = const {};
    });
    try {
      await widget.api.upload(typeId: widget.row.typeId, file: file, validUntil: _validUntil == null ? null : _ymd(_validUntil!));
      if (!mounted) return;
      showSnack(context, context.t('documents.uploaded'));
      Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _serverErrors = e.fieldErrors;
      });
      if (!const {'file', 'valid_until', 'document_type_id'}.any(e.fieldErrors.containsKey)) showSnack(context, apiErrorMessage(context, e));
    }
  }

  @override
  Widget build(BuildContext context) {
    final row = widget.row;
    final rules = UploadRules.document(maxMb: row.maxMb);
    final fileError = _fileError;
    final dateError = _dateError;
    return Scaffold(
      appBar: AppBar(title: Text(context.t(row.actionKey))),
      body: ListView(padding: const EdgeInsets.all(NawySpace.gutter), children: [
        Text(row.requirement.str('name'), style: NawyText.h3),
        const SizedBox(height: 4),
        Text(context.t('documents.limits', {'size': row.maxMb}), style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary)),
        if (row.status == 'rejected' && (row.upload?.s('rejection_reason') ?? '').isNotEmpty) ...[
          const SizedBox(height: 4),
          Text(context.t('documents.rejectedBecause', {'reason': row.upload!.str('rejection_reason')}), style: NawyText.bodySm.copyWith(color: NawyColors.textDanger)),
        ],
        const SizedBox(height: 12),
        DocumentFileField(
          file: _file,
          rules: rules,
          picker: (widget.picker ?? MediaPicker.current).fitting(rules.maxBytes),
          enabled: !_busy,
          onChanged: (f) => setState(() {
            _file = f;
            _serverErrors = {..._serverErrors}
              ..remove('file')
              ..remove('document_type_id');
          }),
        ),
        if (fileError != null)
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Text(fileError, key: const Key('document-file-error'), style: NawyText.bodySm.copyWith(color: NawyColors.textDanger)),
          ),
        if (row.hasExpiry) ...[
          const SizedBox(height: 16),
          InkWell(
            key: const Key('document-expiry'),
            onTap: _busy ? null : _pickDate,
            child: InputDecorator(
              decoration: InputDecoration(
                labelText: context.t('documents.validUntilLabel'),
                errorText: dateError,
                suffixIcon: const Icon(Icons.calendar_today_outlined),
              ),
              child: Text(_validUntil == null ? context.t('documents.pickDate') : _ymd(_validUntil!), textDirection: TextDirection.ltr),
            ),
          ),
        ],
        const SizedBox(height: 20),
        FilledButton(
          key: const Key('document-submit'),
          onPressed: _busy ? null : _submit,
          child: Text(context.t('documents.send')),
        ),
      ]),
    );
  }
}

/// Settings entry that opens [PartnerDocumentsScreen] for [base].
class DocumentsMenuTile extends StatelessWidget {
  const DocumentsMenuTile({super.key = const Key('open-documents'), required this.base, this.picker});

  final String base;
  final MediaPicker? picker;

  @override
  Widget build(BuildContext context) => ListTile(
        leading: const Icon(Icons.description_outlined),
        title: Text(context.t('documents.title')),
        subtitle: Text(context.t('documents.menuHint')),
        trailing: const Icon(Icons.chevron_right),
        onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => PartnerDocumentsScreen(base: base, picker: picker))),
      );
}
