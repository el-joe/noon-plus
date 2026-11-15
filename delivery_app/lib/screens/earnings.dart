import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

import '../data/delivery_api.dart';

/// Figma "09 Earnings" + "10 Wallet": last 7 days of stops at the country's
/// pay rates (`delivery.pay_per_stop`; not set = counts only, owner Q-P2).
/// Payouts and an agent wallet are not built in the backend yet.
class EarningsScreen extends StatelessWidget {
  const EarningsScreen({super.key});

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(context.t('earnings.title'))),
        body: AsyncBody<Json>(
          load: () => DeliveryApi(context.api).earnings(),
          builder: (context, data, _) {
            final days = data.l('days');
            final maxStops = days.fold<int>(1, (m, d) => (d.i('delivered') ?? 0) > m ? d.i('delivered')! : m);
            return ListView(padding: const EdgeInsets.all(NawySpace.gutter), children: [
              KpiGrid(tiles: [
                KpiTile(key: const Key('earnings-total'), value: data['total'] == null ? '—' : money(context, data['total']), label: context.t('earnings.week'), highlight: true),
                KpiTile(value: '${data.i('stops') ?? 0}', label: context.t('earnings.stops')),
                KpiTile(value: '${data.i('failed') ?? 0}', label: context.t('earnings.failed')),
              ]),
              const SizedBox(height: 8),
              Text(
                data['per_stop'] == null ? context.t('earnings.noRate') : context.t('earnings.rate', {'amount': money(context, data['per_stop'])}),
                style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary),
              ),
              SectionTitle(context.t('earnings.byDay')),
              for (final d in days)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 6),
                  child: Row(children: [
                    SizedBox(width: 90, child: Text(d.str('date'), style: NawyText.bodySm)),
                    Expanded(
                      child: ClipRRect(
                        borderRadius: BorderRadius.circular(NawyRadius.pill),
                        child: LinearProgressIndicator(
                          value: (d.i('delivered') ?? 0) / maxStops,
                          minHeight: 10,
                          backgroundColor: NawyColors.borderDefault,
                          color: NawyColors.bgBrand,
                        ),
                      ),
                    ),
                    const SizedBox(width: 8),
                    SizedBox(width: 96, child: Text(d['earned'] == null ? '${d.i('delivered') ?? 0}' : money(context, d['earned']), textAlign: TextAlign.end, style: NawyText.bodyMedium)),
                  ]),
                ),
              SectionTitle(context.t('wallet.title')),
              Card(
                child: ListTile(
                  leading: const Icon(Icons.account_balance_wallet_outlined),
                  title: Text(context.t('wallet.pending')),
                  subtitle: Text(context.t('wallet.notBuilt')),
                ),
              ),
            ]);
          },
        ),
      );
}
