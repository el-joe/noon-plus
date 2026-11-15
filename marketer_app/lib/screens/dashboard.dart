import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

import '../data/marketer_api.dart';
import 'copy.dart';

/// Figma "Marketer dashboard": profile, last-30-day KPIs and top actions.
class DashboardScreen extends StatelessWidget {
  const DashboardScreen({super.key, required this.onOpen});

  final void Function(int tab) onOpen;

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(context.t('dashboard.title'))),
        body: AsyncBody<Json>(
          load: () => MarketerApi(context.api).dashboard(),
          builder: (context, data, _) {
            final p = data.m('profile') ?? const {};
            final k = data.m('kpis') ?? const {};
            final clicks = k.i('clicks') ?? 0;
            final conversions = k.i('conversions') ?? 0;
            final code = p.s('code');
            return ListView(padding: const EdgeInsets.all(NawySpace.gutter), children: [
              Text(p.str('name'), style: NawyText.h2),
              const SizedBox(height: 4),
              Wrap(spacing: 6, children: [for (final t in p.strings('types')) StatusChip('info', label: context.t('type.$t'))]),
              if (code != null)
                Row(children: [
                  Text(context.t('dashboard.code', {'code': code}), key: const Key('referral-code'), style: NawyText.bodyMedium),
                  CopyButton(text: code),
                ]),
              const SizedBox(height: 8),
              Text(context.t('dashboard.window'), style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary)),
              const SizedBox(height: 8),
              KpiGrid(columns: 2, tiles: [
                KpiTile(key: const Key('kpi-earnings'), value: money(context, k['earnings']), label: context.t('dashboard.earnings'), highlight: true),
                KpiTile(key: const Key('kpi-sales'), value: money(context, k['sales']), label: context.t('dashboard.sales')),
              ]),
              const SizedBox(height: 8),
              KpiGrid(tiles: [
                KpiTile(value: '$clicks', label: context.t('dashboard.clicks'), onTap: () => onOpen(2)),
                KpiTile(key: const Key('kpi-conversions'), value: '$conversions', label: context.t('dashboard.conversions'), onTap: () => onOpen(3)),
                KpiTile(value: '${conversionRate(clicks, conversions)}%', label: context.t('dashboard.rate')),
                KpiTile(value: '${k.i('active_campaigns') ?? 0}', label: context.t('dashboard.activeCampaigns'), onTap: () => onOpen(1)),
              ]),
              SectionTitle(context.t('dashboard.actions')),
              Card(
                child: Column(children: [
                  ListTile(key: const Key('action-links'), leading: const Icon(Icons.share_outlined), title: Text(context.t('dashboard.shareLink')), trailing: const Icon(Icons.chevron_right), onTap: () => onOpen(2)),
                  ListTile(leading: const Icon(Icons.campaign_outlined), title: Text(context.t('dashboard.seeCampaigns')), trailing: const Icon(Icons.chevron_right), onTap: () => onOpen(1)),
                ]),
              ),
            ]);
          },
        ),
      );
}
