import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

import '../data/delivery_api.dart';

/// Documents required for agents in this country, their review state and
/// expiry, and the upload (`POST delivery/me/documents`: camera, photo or
/// PDF through [DocumentFileField], expiry date when the type has one).
class DocumentsScreen extends StatefulWidget {
  const DocumentsScreen({super.key, this.picker});

  /// Camera / gallery / files (tests pass a fake); defaults to
  /// [MediaPicker.current].
  final MediaPicker? picker;

  @override
  State<DocumentsScreen> createState() => _DocumentsScreenState();
}

/// One requirement with its current upload (the newest of its type).
class DocumentRow {
  DocumentRow(this.requirement, this.upload, {required this.missing});

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

  /// "Upload", "Re-upload" (rejected / expired) or "Replace".
  String get actionKey => switch (status) {
        'missing' => 'documents.upload',
        'rejected' || 'expired' => 'documents.reupload',
        _ => 'documents.replace',
      };

  static List<DocumentRow> from(Json data) {
    final uploads = data.l('uploads');
    final listed = data['missing'] is List ? {for (final v in data['missing'] as List) int.tryParse('$v')} : null;
    return [
      for (final r in data.l('requirements'))
        _row(r, uploads.where((u) => u.i('document_type_id') == r.i('id')).firstOrNull, listed),
    ];
  }

  static DocumentRow _row(Json r, Json? upload, Set<int?>? listed) {
    // `missing` from the API when sent; else nothing usable uploaded.
    final missing = listed?.contains(r.i('id')) ?? (upload == null || const {'rejected', 'expired'}.contains(upload.s('status')));
    return DocumentRow(r, upload, missing: missing);
  }
}

class _DocumentsScreenState extends State<DocumentsScreen> {
  final _key = GlobalKey<AsyncBodyState<Json>>();

  Future<void> _upload(DocumentRow row) async {
    final done = await Navigator.of(context).push<bool>(MaterialPageRoute(builder: (_) => DocumentUploadScreen(row: row, picker: widget.picker)));
    if (done == true) await _key.currentState?.reload();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(context.t('documents.title'))),
        body: AsyncBody<Json>(
          key: _key,
          load: () => DeliveryApi(context.api).documents(),
          builder: (context, data, _) {
            final rows = DocumentRow.from(data);
            final missingRequired = rows.where((r) => r.mandatory && r.missing).toList();
            return ListView(padding: const EdgeInsets.all(NawySpace.gutter), children: [
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
              for (final row in rows) _DocumentCard(row: row, onUpload: () => _upload(row)),
              if (rows.isEmpty) EmptyView(icon: Icons.description_outlined, title: context.t('documents.none')),
            ]);
          },
        ),
      );
}

class _DocumentCard extends StatelessWidget {
  const _DocumentCard({required this.row, required this.onUpload});

  final DocumentRow row;
  final VoidCallback onUpload;

  @override
  Widget build(BuildContext context) {
    final upload = row.upload;
    final validUntil = upload?.s('valid_until');
    final days = upload?.i('expires_in_days');
    final reason = upload?.s('rejection_reason');
    final soon = upload?.b('expiring_soon') ?? false;
    return Card(
      key: Key('document-${row.typeId}'),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Expanded(child: Text(row.requirement.str('name'), style: NawyText.bodyMedium)),
            StatusChip(row.status, label: row.status == 'missing' ? context.t('documents.missing') : null),
          ]),
          const SizedBox(height: 2),
          Text(context.t(row.mandatory ? 'documents.mandatory' : 'documents.optional'), style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary)),
          if (validUntil != null) ...[
            const SizedBox(height: 4),
            Text(context.t('documents.validUntil', {'date': validUntil}), style: NawyText.bodySm),
          ],
          if (soon && days != null && row.status != 'expired') ...[
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
            Text(context.t('documents.rejectedBecause', {'reason': reason}), style: NawyText.bodySm.copyWith(color: NawyColors.textDanger)),
          ],
          Align(
            alignment: AlignmentDirectional.centerEnd,
            child: TextButton.icon(
              key: Key('document-upload-${row.typeId}'),
              onPressed: onUpload,
              icon: const Icon(Icons.upload_file_outlined, size: 18),
              label: Text(context.t(row.actionKey)),
            ),
          ),
        ]),
      ),
    );
  }
}

/// Upload (or replace) one document: the file and, for types with an
/// expiry, the "valid until" date (after today).
class DocumentUploadScreen extends StatefulWidget {
  const DocumentUploadScreen({super.key, required this.row, this.picker, this.today});

  final DocumentRow row;
  final MediaPicker? picker;

  /// "Today" for the date picker (tests).
  final DateTime? today;

  @override
  State<DocumentUploadScreen> createState() => _DocumentUploadScreenState();
}

class _DocumentUploadScreenState extends State<DocumentUploadScreen> {
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

  String? get _fileError => _serverErrors['file'] ?? (_tried && _file == null ? context.t('documents.fileRequired') : null);

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
      await DeliveryApi(context.api).uploadDocument(typeId: widget.row.typeId, file: file, validUntil: _validUntil == null ? null : _ymd(_validUntil!));
      if (!mounted) return;
      showSnack(context, context.t('documents.uploaded'));
      Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _serverErrors = e.fieldErrors;
      });
      if (!e.fieldErrors.containsKey('file') && !e.fieldErrors.containsKey('valid_until')) showSnack(context, apiErrorMessage(context, e));
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
            _serverErrors = {..._serverErrors}..remove('file');
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
