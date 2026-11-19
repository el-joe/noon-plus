import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

import '../data/delivery_api.dart';
import '../data/models.dart';
import '../ui/run_step.dart';
import 'proof.dart';

/// Figma "05 Delivery code" (ADR 15): the agent SEES the 4-digit code, the
/// customer reads their copy aloud, and the agent taps "Codes match". The
/// agent never types the code and the app never sends it.
class CodeMatchScreen extends StatefulWidget {
  const CodeMatchScreen({super.key, required this.assignment});

  final Assignment assignment;

  @override
  State<CodeMatchScreen> createState() => _CodeMatchScreenState();
}

class _CodeMatchScreenState extends State<CodeMatchScreen> {
  bool _busy = false;

  Future<void> _codesMatch() async {
    final a = widget.assignment;
    final nav = Navigator.of(context);
    if (needsProofStep(a) || a.isCod) {
      final done = await nav.push<Assignment>(MaterialPageRoute(builder: (_) => ProofScreen(assignment: a)));
      if (done != null && mounted) nav.pop(done);
      return;
    }
    setState(() => _busy = true);
    final done = await runStep(context, () => DeliveryApi(context.api).confirm(a.number, const ConfirmRequest()), success: context.t('proof.delivered'), onRefused: () => Navigator.maybePop(context));
    if (!mounted) return;
    setState(() => _busy = false);
    if (done != null) nav.pop(done);
  }

  @override
  Widget build(BuildContext context) {
    final a = widget.assignment;
    final code = a.deliveryCode ?? '';
    final name = (a.customerName ?? '').split(' ').first;
    return Scaffold(
      appBar: AppBar(title: Text(context.t('code.title'))),
      body: ListView(padding: const EdgeInsets.all(NawySpace.gutter), children: [
        Text(name.isEmpty ? context.t('code.headingNoName') : context.t('code.heading', {'name': name}), style: NawyText.h2),
        const SizedBox(height: 6),
        Text(context.t('code.hint', {'name': name.isEmpty ? context.t('code.customer') : name}), style: NawyText.body.copyWith(color: NawyColors.textSecondary)),
        const SizedBox(height: 16),
        Directionality(
          // Digits read left to right in both languages.
          textDirection: TextDirection.ltr,
          child: Row(
            key: const Key('delivery-code'),
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              for (final digit in code.split(''))
                Container(
                  width: 68,
                  height: 80,
                  margin: const EdgeInsets.symmetric(horizontal: 5),
                  alignment: Alignment.center,
                  decoration: BoxDecoration(
                    color: NawyColors.bgSurface,
                    borderRadius: BorderRadius.circular(NawyRadius.lg),
                    border: Border.all(color: NawyColors.bgBrand, width: 2),
                  ),
                  child: Text(digit, style: NawyText.display),
                ),
            ],
          ),
        ),
        if (code.isEmpty) Text(context.t('code.missing'), style: NawyText.body.copyWith(color: NawyColors.textDanger)),
        const SizedBox(height: 12),
        Container(
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
          decoration: BoxDecoration(color: NawyColors.bgSuccessSubtle, borderRadius: BorderRadius.circular(NawyRadius.md)),
          child: Text(context.t('code.onlyWhenSame'), style: NawyText.bodySm.copyWith(color: NawyColors.textSuccess)),
        ),
        const SizedBox(height: 16),
        FilledButton(
          key: const Key('codes-match'),
          onPressed: code.isEmpty || _busy ? null : _codesMatch,
          child: Text(context.t('code.match')),
        ),
        const SizedBox(height: 10),
        OutlinedButton(
          key: const Key('codes-differ'),
          onPressed: () => showDialog<void>(
            context: context,
            builder: (context) => AlertDialog(
              title: Text(context.t('code.differTitle')),
              content: Text(context.t('code.differBody')),
              actions: [TextButton(onPressed: () => Navigator.pop(context), child: Text(context.t('common.confirm')))],
            ),
          ),
          child: Text(context.t('code.differ')),
        ),
      ]),
    );
  }
}
