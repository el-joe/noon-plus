import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

import '../data/delivery_api.dart';
import '../data/models.dart';

/// Figma "07 Can't deliver": one of the configured reasons
/// (`delivery.failure_reasons`, sent with every assignment) plus a note.
/// Rescheduling and return to origin are decided by dispatch (W4).
class CantDeliverScreen extends StatefulWidget {
  const CantDeliverScreen({super.key, required this.assignment});

  final Assignment assignment;

  @override
  State<CantDeliverScreen> createState() => _CantDeliverScreenState();
}

class _CantDeliverScreenState extends State<CantDeliverScreen> {
  String? _reason;
  final _note = TextEditingController();
  bool _busy = false;

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final reasons = widget.assignment.failureReasons;
    return Scaffold(
      appBar: AppBar(title: Text(context.t('fail.title'))),
      body: ListView(padding: const EdgeInsets.all(NawySpace.gutter), children: [
        Text(context.t('fail.heading'), style: NawyText.h2),
        const SizedBox(height: 8),
        RadioGroup<String>(
          groupValue: _reason,
          onChanged: (v) => setState(() => _reason = v),
          child: Column(children: [
            for (final reason in reasons)
              RadioListTile<String>(
                key: Key('reason-$reason'),
                value: reason,
                contentPadding: EdgeInsets.zero,
                title: Text(context.t('reason.$reason')),
              ),
          ]),
        ),
        const SizedBox(height: 8),
        Text(context.t('fail.note'), style: NawyText.label),
        const SizedBox(height: 6),
        TextField(key: const Key('fail-note'), controller: _note, maxLength: 255, decoration: InputDecoration(hintText: context.t('fail.noteHint'))),
        Text(context.t('fail.next'), style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary)),
        const SizedBox(height: 16),
        FilledButton(
          key: const Key('fail-submit'),
          onPressed: _reason == null || _busy
              ? null
              : () async {
                  setState(() => _busy = true);
                  final note = _note.text.trim();
                  final done = await runAction(context, () => DeliveryApi(context.api).fail(widget.assignment.number, _reason!, note: note.isEmpty ? null : note), success: context.t('fail.done'));
                  if (!context.mounted) return;
                  setState(() => _busy = false);
                  if (done != null) Navigator.pop(context, done);
                },
          child: Text(context.t('fail.submit')),
        ),
      ]),
    );
  }
}
