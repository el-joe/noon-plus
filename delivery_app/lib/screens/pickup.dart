import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

import '../data/delivery_api.dart';
import '../data/models.dart';
import '../ui/run_step.dart';
import 'scan.dart';

/// Figma "12 Pickup scan". The agent scans the parcel label
/// (`mobile_scanner`, owner-review D16 / P10) or types the shipment number
/// when the camera can't read it. A label (`<shipment>.<check>`, Code 39)
/// must pass its mod 43 check and be this stop's shipment; the API confirms
/// the match (`POST …/scan`). A matching code ticks every item of the
/// shipment. Items can still be ticked by hand (damaged label). Then
/// "Start delivery" (picked up → out for delivery).
class PickupScreen extends StatefulWidget {
  const PickupScreen({super.key, required this.assignment, this.scanner = scanParcelCode});

  final Assignment assignment;

  /// Opens the camera scanner (tests pass a fake).
  final ParcelScanner scanner;

  @override
  State<PickupScreen> createState() => _PickupScreenState();
}

class _PickupScreenState extends State<PickupScreen> {
  late final List<bool> _checked = List.filled(widget.assignment.items.length, false);
  final _manual = TextEditingController();
  final _manualFocus = FocusNode();
  bool _busy = false;
  bool _verified = false;
  bool _checking = false;
  String? _codeError;

  @override
  void dispose() {
    _manual.dispose();
    _manualFocus.dispose();
    super.dispose();
  }

  /// Checks the code on the phone first (label check character, this
  /// stop's numbers), then with the API (`POST …/scan`, authoritative). Without
  /// a connection the phone's check stands.
  Future<void> _apply(String code) async {
    final a = widget.assignment;
    final result = matchParcelCode(a, code);
    if (result != ParcelMatch.match) {
      setState(() => _codeError = switch (result) {
            ParcelMatch.badLabel => context.t('pickup.badLabel'),
            ParcelMatch.otherParcel => context.t('pickup.otherParcel', {'shipment': parseParcelBarcode(code).base}),
            ParcelMatch.empty => null,
            _ => context.t('pickup.wrongCode', {'code': code.trim()}),
          });
      return;
    }
    setState(() {
      _checking = true;
      _codeError = null;
    });
    String? refused;
    try {
      await DeliveryApi(context.api).scan(a.number, normaliseParcelCode(code));
    } on ApiException catch (e) {
      final offline = e.isNetwork || e.isRateLimited || e.status >= 500;
      if (!offline && mounted) refused = apiErrorMessage(context, e);
    }
    if (!mounted) return;
    setState(() {
      _checking = false;
      _codeError = refused;
      if (refused == null) {
        _verified = true;
        _checked.fillRange(0, _checked.length, true);
      }
    });
  }

  void _submitManual(String value) {
    if (value.trim().isNotEmpty) _apply(value);
  }

  Future<void> _scan() async {
    final code = await widget.scanner(context);
    if (!mounted) return;
    if (code == null) {
      // Closed, or the camera failed: type the code instead.
      _manualFocus.requestFocus();
      return;
    }
    _apply(code);
  }

  @override
  Widget build(BuildContext context) {
    final a = widget.assignment;
    final allChecked = _checked.every((c) => c);
    return Scaffold(
      appBar: AppBar(title: Text(context.t('pickup.title'))),
      body: ListView(padding: const EdgeInsets.all(NawySpace.gutter), children: [
        Text(context.t('pickup.from', {'seller': a.seller ?? '—'}), style: NawyText.h3),
        const SizedBox(height: 4),
        Text(context.t('pickup.hint'), style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary)),
        const SizedBox(height: 12),
        FilledButton.icon(
          key: const Key('pickup-scan'),
          onPressed: _busy || _checking ? null : _scan,
          icon: const Icon(Icons.qr_code_scanner),
          label: Text(context.t('pickup.scan')),
        ),
        const SizedBox(height: 8),
        Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Expanded(
            child: TextField(
              key: const Key('pickup-code'),
              controller: _manual,
              focusNode: _manualFocus,
              textDirection: TextDirection.ltr,
              textCapitalization: TextCapitalization.characters,
              textInputAction: TextInputAction.done,
              onSubmitted: _submitManual,
              decoration: InputDecoration(labelText: context.t('pickup.manual'), hintText: a.shipmentNumber.isEmpty ? null : 'NW-…', errorText: _codeError),
            ),
          ),
          const SizedBox(width: 8),
          Padding(
            padding: const EdgeInsets.only(top: 4),
            child: OutlinedButton(
              key: const Key('pickup-code-check'),
              onPressed: _checking ? null : () => _submitManual(_manual.text),
              child: Text(context.t('pickup.check')),
            ),
          ),
        ]),
        if (_verified)
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Row(key: const Key('pickup-verified'), children: [
              const Icon(Icons.verified_outlined, size: 18, color: NawyColors.textSuccess),
              const SizedBox(width: 6),
              Expanded(child: Text(context.t('pickup.verified', {'shipment': a.shipmentNumber}), style: NawyText.bodySm.copyWith(color: NawyColors.textSuccess))),
            ]),
          ),
        const SizedBox(height: 12),
        Card(
          child: Column(children: [
            for (final (i, item) in a.items.indexed)
              CheckboxListTile(
                key: Key('parcel-$i'),
                value: _checked[i],
                onChanged: (v) => setState(() => _checked[i] = v ?? false),
                title: Text(item.str('title')),
                subtitle: Text('× ${item.i('quantity') ?? 1} · ${a.shipmentNumber}'),
              ),
          ]),
        ),
        const SizedBox(height: 16),
        FilledButton(
          key: const Key('pickup-start'),
          onPressed: !allChecked || _busy
              ? null
              : () async {
                  setState(() => _busy = true);
                  final ok = await runStep(context, () => DeliveryApi(context.api).start(a.number), success: context.t('pickup.started'), onRefused: () => Navigator.maybePop(context));
                  if (!context.mounted) return;
                  setState(() => _busy = false);
                  if (ok != null) Navigator.pop(context, ok);
                },
          child: Text(context.t('pickup.start')),
        ),
      ]),
    );
  }
}
