import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

import '../data/delivery_api.dart';
import '../data/models.dart';

/// Figma "11 Accept/decline": a new assignment offer. Declining needs a reason.
class OfferScreen extends StatelessWidget {
  const OfferScreen({super.key, required this.assignment});

  final Assignment assignment;

  @override
  Widget build(BuildContext context) {
    final a = assignment;
    final locale = context.l10n.locale;
    final api = DeliveryApi(context.api);
    return Scaffold(
      appBar: AppBar(title: Text(context.t('offer.title'))),
      body: ListView(padding: const EdgeInsets.all(NawySpace.gutter), children: [
        Text(context.t('offer.heading'), style: NawyText.h2),
        const SizedBox(height: 12),
        Card(
          child: Column(children: [
            ListTile(leading: const Icon(Icons.store_outlined), title: Text(context.t('offer.pickup')), subtitle: Text(a.seller ?? '—')),
            ListTile(leading: const Icon(Icons.place_outlined), title: Text(context.t('offer.dropoff')), subtitle: Text(a.addressLine.isEmpty ? '—' : a.addressLine)),
            ListTile(leading: const Icon(Icons.inventory_2_outlined), title: Text(context.t('stop.parcels', {'count': a.items.length}))),
            if (a.isCod) ListTile(leading: const Icon(Icons.payments_outlined), title: Text(context.t('stop.collectCash')), subtitle: Text(a.codAmount!.format(locale: locale))),
            if (a.slaDueAt != null)
              ListTile(leading: const Icon(Icons.schedule), title: Text(context.t('stop.due', {'time': TimeOfDay.fromDateTime(a.slaDueAt!).format(context)}))),
          ]),
        ),
        const SizedBox(height: 16),
        FilledButton(
          key: const Key('offer-accept'),
          onPressed: () async {
            final ok = await runAction(context, () => api.accept(a.number), success: context.t('offer.accepted'));
            if (ok != null && context.mounted) Navigator.pop(context, ok);
          },
          child: Text(context.t('action.accept')),
        ),
        const SizedBox(height: 10),
        OutlinedButton(
          key: const Key('offer-decline'),
          style: OutlinedButton.styleFrom(foregroundColor: NawyColors.textDanger),
          onPressed: () async {
            final reason = await askReason(context, title: context.t('offer.declineTitle'), minLength: 3);
            if (reason == null || !context.mounted) return;
            final ok = await runAction(context, () => api.decline(a.number, reason), success: context.t('offer.declined'));
            if (ok != null && context.mounted) Navigator.pop(context, ok);
          },
          child: Text(context.t('action.decline')),
        ),
      ]),
    );
  }
}
