import 'package:flutter/material.dart';

import '../../core/api/api_client.dart';
import '../../core/json.dart';
import '../../core/money.dart';
import '../../features/page_builder/blocks.dart';
import '../../l10n/l10n.dart';
import '../../state/app_state.dart';
import '../../ui/theme.dart';
import '../../ui/widgets/common.dart';
import '../../ui/widgets/product_card.dart';
import '../../ui/widgets/states.dart';

/// Figma "12 Deals": live and upcoming flash sales (`GET flash-sales`).
class DealsTab extends StatelessWidget {
  const DealsTab({super.key});

  @override
  Widget build(BuildContext context) {
    final app = AppScope.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(context.t('tabs.deals'))),
      body: AsyncBody<List<Json>>(
        key: ValueKey('deals-${app.market}'),
        load: () => app.api.getList('flash-sales').then((r) => r.data),
        isEmpty: (sales) => sales.where((s) => s.l('deals').isNotEmpty).isEmpty,
        empty: EmptyView(icon: Icons.local_offer_outlined, title: context.t('deals.empty'), subtitle: context.t('deals.emptyHint')),
        builder: (context, sales, reload) {
          final live = sales.where((s) => s.s('state') == 'live' && s.l('deals').isNotEmpty).toList();
          final upcoming = sales.where((s) => s.s('state') != 'live' && s.l('deals').isNotEmpty).toList();
          return RefreshIndicator(
            onRefresh: reload,
            child: ListView(padding: const EdgeInsets.only(bottom: 24), children: [
              for (final sale in live) FlashSaleSection(sale: sale),
              if (upcoming.isNotEmpty) SectionHeader(title: context.t('deals.upcoming')),
              for (final sale in upcoming)
                ListTile(
                  leading: const Icon(Icons.schedule_rounded),
                  title: Text(sale.str('name')),
                  subtitle: Text(context.t('deals.startsAt', {'date': shortDate(context, sale.s('starts_at'), time: true)})),
                ),
            ]),
          );
        },
      ),
    );
  }
}

/// One flash sale: countdown, claimed bars, deal grid (`GET flash-sales/{id|slug}`).
class FlashSaleScreen extends StatelessWidget {
  const FlashSaleScreen({super.key, required this.id});

  final String id;

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(context.t('deals.flashSale'))),
        body: AsyncBody<Json>(
          load: () => AppScope.read(context).api.getObject('flash-sales/${enc(id)}'),
          builder: (context, sale, _) {
            final ends = DateTime.tryParse(sale.str('ends_at'));
            final deals = sale.l('deals');
            return ListView(padding: const EdgeInsets.only(bottom: 24), children: [
              Container(
                color: NawyColors.bgDangerSubtle,
                padding: const EdgeInsets.all(16),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(sale.str('name'), style: NawyText.h2),
                  if (ends != null) Row(children: [Text('${context.t('blocks.endsIn')} '), Countdown(endsAt: ends, style: NawyText.h4.copyWith(color: NawyColors.textDanger))]),
                  if (sale.i('max_per_customer') != null) Text(context.t('deals.maxPer', {'n': sale.i('max_per_customer')}), style: NawyText.caption),
                ]),
              ),
              const SizedBox(height: 12),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16),
                child: LayoutBuilder(builder: (context, c) {
                  final w = (c.maxWidth - 12) / 2;
                  return Wrap(spacing: 12, runSpacing: 16, children: [
                    for (final d in deals)
                      SizedBox(
                        width: w,
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          ProductCard(
                            card: FlashSaleSection.dealCard(d),
                            was: d.m('was_price'),
                            tag: d.b('sold_out') ? context.t('deals.soldOut') : '-${Money.fromJson(d['price'])?.percentOffFrom(Money.fromJson(d['was_price']) ?? Money.zero(Money.fromJson(d['price'])?.currency ?? 'AED')) ?? 0}%',
                            width: w,
                          ),
                          const SizedBox(height: 4),
                          LinearProgressIndicator(value: (d.i('claimed_percent') ?? 0) / 100, minHeight: 4, color: NawyColors.bgDanger, backgroundColor: NawyColors.borderDefault),
                          Text(context.t('deals.claimed', {'n': d.i('claimed_percent') ?? 0}), style: NawyText.caption.copyWith(color: NawyColors.textSecondary)),
                        ]),
                      ),
                  ]);
                }),
              ),
            ]);
          },
        ),
      );
}
