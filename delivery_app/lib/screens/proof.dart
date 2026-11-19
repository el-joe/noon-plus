import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

import '../data/delivery_api.dart';
import '../data/models.dart';
import '../ui/run_step.dart';
import '../ui/signature_pad.dart';

/// Figma "06 Proof of delivery" (+ "08 COD" cash step): photo, customer
/// signature and cash collected, then "Complete delivery" sends
/// `codes_match` with the proof (W5).
///
/// Photo: the full-size camera photo from `image_picker` (owner-review D16,
/// P8) through [MediaPicker], checked against the API limit
/// (`delivery.proof_max_kb`). Without a camera the customer signs instead.
class ProofScreen extends StatefulWidget {
  const ProofScreen({super.key, required this.assignment, this.picker});

  final Assignment assignment;

  /// Defaults to [MediaPicker.current] (tests pass a fake).
  final MediaPicker? picker;

  @override
  State<ProofScreen> createState() => _ProofScreenState();
}

class _ProofScreenState extends State<ProofScreen> {
  final _signature = SignatureController();
  final _received = TextEditingController();
  Size _padSize = const Size(340, 160);
  PickedMedia? _photo;
  bool _photoUnavailable = false;
  String? _photoError;
  bool _cashCollected = false;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _signature.addListener(() => setState(() {}));
  }

  @override
  void dispose() {
    _signature.dispose();
    _received.dispose();
    super.dispose();
  }

  Future<void> _capture() async {
    final picker = widget.picker ?? MediaPicker.current;
    final shot = picker.hasCamera ? await picker.camera(fullSize: true) : null;
    if (!mounted) return;
    final check = shot == null ? null : UploadRules.proofPhoto.check(const [], [shot]);
    setState(() {
      _photoUnavailable = !picker.hasCamera;
      _photoError = check == null || check.rejected.isEmpty ? null : rejectionMessages(check.rejected, UploadRules.proofPhoto, context.t).join('\n');
      if (check != null && check.accepted.isNotEmpty) _photo = check.accepted.single;
    });
  }

  bool get _proofOk => !widget.assignment.proofRequired ||
      proofSatisfied(proofType: widget.assignment.proofType, hasPhoto: _photo != null || widget.assignment.hasPhoto, hasSignature: !_signature.isEmpty || widget.assignment.hasSignature);

  bool get _cashOk => !widget.assignment.isCod || _cashCollected;

  Future<void> _complete() async {
    setState(() => _busy = true);
    final signature = _signature.isEmpty ? null : await _signature.toDataUrl(_padSize);
    if (!mounted) return;
    final request = ConfirmRequest(signatureDataUrl: signature, photo: _photo?.bytes, photoType: _photo?.mimeType ?? 'image/jpeg');
    final done = await runStep(context, () => DeliveryApi(context.api).confirm(widget.assignment.number, request),
        success: context.t('proof.delivered'),
        // Refused: leave the code screen too (it pops on any result); the
        // stop reloads the assignment.
        onRefused: () => Navigator.maybePop(context, widget.assignment));
    if (!mounted) return;
    setState(() => _busy = false);
    if (done != null) Navigator.pop(context, done);
  }

  @override
  Widget build(BuildContext context) {
    final a = widget.assignment;
    final locale = context.l10n.locale;
    final due = a.codAmount;
    final received = due == null || _received.text.trim().isEmpty ? null : Money.parse(_received.text, due.currency);
    final change = due == null || received == null ? null : CodMath.changeDue(due, received);
    return Scaffold(
      appBar: AppBar(title: Text(context.t('proof.title'))),
      body: ListView(padding: const EdgeInsets.all(NawySpace.gutter), children: [
        if (a.proofRequired)
          Text(context.t('proof.required.${a.proofType}'), key: const Key('proof-required'), style: NawyText.bodySm.copyWith(color: NawyColors.textBrand)),
        const SizedBox(height: 8),
        Text(context.t('proof.photo'), style: NawyText.label),
        const SizedBox(height: 6),
        Card(
          child: InkWell(
            key: const Key('take-photo'),
            onTap: _capture,
            child: SizedBox(
              height: 150,
              child: _photo != null
                  ? Image.memory(_photo!.bytes, key: const Key('proof-photo'), fit: BoxFit.contain)
                  : Center(
                      child: Column(mainAxisSize: MainAxisSize.min, children: [
                        const Icon(Icons.photo_camera_outlined, size: 36, color: NawyColors.textSecondary),
                        Text(context.t('proof.takePhoto'), style: NawyText.bodyMedium),
                      ]),
                    ),
            ),
          ),
        ),
        if (_photo != null) TextButton(onPressed: _capture, child: Text(context.t('proof.retake'))),
        if (_photoError != null)
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Text(_photoError!, key: const Key('photo-error'), style: NawyText.bodySm.copyWith(color: NawyColors.textDanger)),
          ),
        if (_photoUnavailable)
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Text(context.t('proof.cameraUnavailable'), key: const Key('camera-unavailable'), style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary)),
          ),
        const SizedBox(height: 12),
        Row(children: [
          Expanded(child: Text(context.t('proof.signature'), style: NawyText.label)),
          if (!_signature.isEmpty) TextButton(key: const Key('clear-signature'), onPressed: _signature.clear, child: Text(context.t('proof.clear'))),
        ]),
        const SizedBox(height: 6),
        LayoutBuilder(builder: (context, c) {
          _padSize = Size(c.maxWidth, 160);
          return SignaturePad(key: const Key('signature-pad'), controller: _signature);
        }),
        if (_signature.isEmpty) Padding(padding: const EdgeInsets.only(top: 4), child: Text(context.t('proof.signHere'), style: NawyText.bodySm.copyWith(color: NawyColors.textMuted))),
        if (due != null && !due.isZero) ...[
          const SizedBox(height: 16),
          Card(
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Row(children: [
                  Expanded(child: Text(context.t('proof.cashDue'), style: NawyText.body)),
                  Text(due.format(locale: locale), style: NawyText.h4),
                ]),
                const SizedBox(height: 8),
                TextField(
                  key: const Key('cash-received'),
                  controller: _received,
                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                  decoration: InputDecoration(labelText: context.t('proof.cashReceived'), suffixText: due.currency),
                  onChanged: (_) => setState(() {}),
                ),
                if (received != null) ...[
                  const SizedBox(height: 6),
                  Text(
                    change == null ? context.t('proof.short', {'amount': (due - received).format(locale: locale)}) : context.t('proof.change', {'amount': change.format(locale: locale)}),
                    key: const Key('change-due'),
                    style: NawyText.bodyMedium.copyWith(color: change == null ? NawyColors.textDanger : NawyColors.textSuccess),
                  ),
                ],
                CheckboxListTile(
                  key: const Key('cash-collected'),
                  contentPadding: EdgeInsets.zero,
                  value: _cashCollected,
                  onChanged: (v) => setState(() => _cashCollected = v ?? false),
                  title: Text(context.t('proof.cashCollected', {'amount': due.format(locale: locale)})),
                ),
              ]),
            ),
          ),
        ],
        const SizedBox(height: 16),
        FilledButton(
          key: const Key('complete-delivery'),
          onPressed: _proofOk && _cashOk && !_busy ? _complete : null,
          child: _busy ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2)) : Text(context.t('proof.complete')),
        ),
      ]),
    );
  }
}
