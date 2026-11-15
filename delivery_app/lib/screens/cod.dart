import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

import '../data/delivery_api.dart';
import '../data/models.dart';

/// Figma "08 COD": cash in hand (not yet in a settlement) and the daily
/// settlements (`nawy:cod:generate-settlements` 23:30; Nawy finance settles).
class CodScreen extends StatelessWidget {
  const CodScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final session = SessionScope.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(context.t('cod.title'))),
      body: AsyncBody<Json>(
        load: () => DeliveryApi(context.api).cod(),
        builder: (context, data, _) {
          final locale = context.l10n.locale;
          final inHand = Money.fromJson(data['in_hand']) ?? Money.zero(session.currency);
          final collections = data.l('collections');
          final sum = CodMath.total(collections.map((c) => c['amount']), inHand.currency);
          final ml = MaterialLocalizations.of(context);
          return ListView(padding: const EdgeInsets.all(NawySpace.gutter), children: [
            Container(
              key: const Key('cash-in-hand'),
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(color: NawyColors.bgInverse, borderRadius: BorderRadius.circular(NawyRadius.lg)),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(context.t('cod.inHand'), style: NawyText.bodySm.copyWith(color: NawyColors.textInverse)),
                FittedBox(child: Text(inHand.format(locale: locale), style: NawyText.display.copyWith(color: NawyColors.bgBrand))),
                Text(context.t('cod.fromStops', {'count': collections.length}), style: NawyText.bodySm.copyWith(color: NawyColors.textInverse)),
              ]),
            ),
            if (sum != inHand && collections.isNotEmpty)
              Padding(padding: const EdgeInsets.only(top: 6), child: Text(context.t('cod.mismatch'), style: NawyText.bodySm.copyWith(color: NawyColors.textDanger))),
            const SizedBox(height: 8),
            Text(context.t('cod.depositHint'), style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary)),
            SectionTitle(context.t('cod.collections')),
            if (collections.isEmpty) Text(context.t('cod.noCollections'), style: NawyText.body.copyWith(color: NawyColors.textSecondary)),
            for (final c in collections)
              ListTile(
                contentPadding: EdgeInsets.zero,
                title: Text(c.s('sub_order_number') ?? c.str('order_number')),
                subtitle: Text(DateTime.tryParse(c.str('collected_at')) == null ? '' : ml.formatShortDate(DateTime.parse(c.str('collected_at')).toLocal())),
                trailing: Text(money(context, c['amount']), style: NawyText.bodyMedium),
              ),
            SectionTitle(context.t('cod.settlements')),
            if (data.l('settlements').isEmpty) Text(context.t('cod.noSettlements'), style: NawyText.body.copyWith(color: NawyColors.textSecondary)),
            for (final s in data.l('settlements'))
              ListTile(
                contentPadding: EdgeInsets.zero,
                title: Text(s.str('business_date')),
                subtitle: Align(alignment: AlignmentDirectional.centerStart, child: StatusChip(s.str('status'))),
                trailing: Text(money(context, s['expected']), style: NawyText.bodyMedium),
              ),
          ]);
        },
      ),
    );
  }
}
