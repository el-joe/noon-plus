import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

import '../data/delivery_api.dart';
import '../data/location.dart';
import '../data/models.dart';
import '../ui/run_step.dart';
import 'cant_deliver.dart';
import 'code_match.dart';
import 'history.dart';
import 'offer.dart';
import 'pickup.dart';

/// Figma "04 Stop detail": customer, parcels, cash to collect and the next
/// step (accept → pick up → navigate / arrive → deliver or can't deliver).
class StopDetailScreen extends StatefulWidget {
  const StopDetailScreen({super.key, required this.number});

  final String number;

  @override
  State<StopDetailScreen> createState() => _StopDetailScreenState();
}

class _StopDetailScreenState extends State<StopDetailScreen> {
  final _key = GlobalKey<AsyncBodyState<Assignment>>();

  /// Loads the stop and tells the GPS tracker whether it is on the road.
  Future<Assignment> _load() async {
    final tracker = DutyScope.read(context);
    final a = await DeliveryApi(context.api).assignment(widget.number);
    tracker?.noteStop(a);
    return a;
  }

  Future<void> _push(Widget screen) async {
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => screen));
    _key.currentState?.reload();
  }

  Future<void> _act(StopAction action, Assignment a) async {
    final api = DeliveryApi(context.api);
    switch (action) {
      case StopAction.accept || StopAction.decline:
        return _push(OfferScreen(assignment: a));
      case StopAction.start:
        return _push(PickupScreen(assignment: a));
      case StopAction.navigate:
        final ok = await PlatformBridge.openMaps(a.addressLine);
        if (!ok && mounted) showSnack(context, context.t('stop.mapsFailed'));
        return;
      case StopAction.arrive:
        await runStep(context, () => api.arrive(a.number), success: context.t('stop.arrivedDone'));
        _key.currentState?.reload();
        return;
      case StopAction.deliver:
        return _push(CodeMatchScreen(assignment: a));
      case StopAction.fail:
        return _push(CantDeliverScreen(assignment: a));
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(
          title: Text(context.t('stop.title', {'number': widget.number})),
          actions: [
            IconButton(
              key: const Key('open-history'),
              tooltip: context.t('history.title'),
              icon: const Icon(Icons.history),
              onPressed: () => _push(HistoryScreen(number: widget.number)),
            ),
          ],
        ),
        body: AsyncBody<Assignment>(
          key: _key,
          load: _load,
          builder: (context, a, reload) {
            final locale = context.l10n.locale;
            final actions = actionsFor(a);
            return ListView(padding: const EdgeInsets.all(NawySpace.gutter), children: [
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Row(children: [
                      Expanded(child: Text(a.customerName ?? a.shipmentNumber, style: NawyText.h3)),
                      StatusChip(a.status),
                    ]),
                    if (a.addressLine.isNotEmpty) ...[const SizedBox(height: 4), Text(a.addressLine, style: NawyText.body.copyWith(color: NawyColors.textSecondary))],
                    if (a.landmark != null) Text(context.t('stop.note', {'note': a.landmark}), style: NawyText.bodySm),
                    if (a.customerPhone != null) ...[
                      const SizedBox(height: 8),
                      PhoneLink(a.customerPhone!, key: const Key('call-customer')),
                    ],
                    const SizedBox(height: 8),
                    Text(context.t('stop.meta', {'order': a.orderNumber, 'attempt': a.attempt}), style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary)),
                    if (a.seller != null) Text(context.t('stop.pickupFrom', {'seller': a.seller}), style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary)),
                  ]),
                ),
              ),
              const SizedBox(height: 12),
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(context.t('stop.parcels', {'count': a.items.length}), style: NawyText.h4),
                    const SizedBox(height: 6),
                    for (final item in a.items) Text('${item.i('quantity') ?? 1} × ${item.str('title')}', style: NawyText.body),
                  ]),
                ),
              ),
              if (a.isCod) ...[
                const SizedBox(height: 12),
                Container(
                  key: const Key('cod-box'),
                  padding: const EdgeInsets.all(16),
                  decoration: BoxDecoration(color: NawyColors.bgWarningSubtle, borderRadius: BorderRadius.circular(NawyRadius.card), border: Border.all(color: NawyColors.borderDefault)),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(context.t('stop.collectCash'), style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary)),
                    Text(a.codAmount!.format(locale: locale), style: NawyText.h2),
                  ]),
                ),
              ],
              if (a.isCancelled) ...[
                const SizedBox(height: 12),
                Container(
                  key: const Key('cancelled-banner'),
                  padding: const EdgeInsets.all(16),
                  decoration: BoxDecoration(color: NawyColors.bgDangerSubtle, borderRadius: BorderRadius.circular(NawyRadius.card)),
                  child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    const Icon(Icons.block, color: NawyColors.textDanger),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Text(context.t('stop.cancelled'), style: NawyText.h4.copyWith(color: NawyColors.textDanger)),
                        Text(context.t('stop.cancelledHint'), style: NawyText.bodySm),
                      ]),
                    ),
                  ]),
                ),
              ],
              if (a.failureReason != null) ...[
                const SizedBox(height: 12),
                Text(context.t('stop.failedReason', {'reason': context.t('reason.${a.failureReason}')}), style: NawyText.body.copyWith(color: NawyColors.textDanger)),
              ],
              const SizedBox(height: 16),
              for (final action in actions) ...[
                _ActionButton(action: action, assignment: a, onPressed: () => _act(action, a)),
                const SizedBox(height: 10),
              ],
            ]);
          },
        ),
      );
}

class _ActionButton extends StatelessWidget {
  const _ActionButton({required this.action, required this.assignment, required this.onPressed});

  final StopAction action;
  final Assignment assignment;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    final label = context.t('action.${action.name}');
    final key = Key('action-${action.name}');
    final primary = action == StopAction.deliver || action == StopAction.accept || action == StopAction.start;
    final danger = action == StopAction.fail || action == StopAction.decline;
    if (primary) return FilledButton(key: key, onPressed: onPressed, child: Text(label));
    return OutlinedButton(
      key: key,
      onPressed: onPressed,
      style: danger ? OutlinedButton.styleFrom(foregroundColor: NawyColors.textDanger) : null,
      child: Text(label),
    );
  }
}
