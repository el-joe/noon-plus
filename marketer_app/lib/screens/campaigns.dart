import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

import '../data/marketer_api.dart';
import 'copy.dart';

/// Figma "Marketer campaigns": accepted campaign invitations.
class CampaignsScreen extends StatelessWidget {
  const CampaignsScreen({super.key});

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(context.t('campaigns.title'))),
        body: AsyncBody<List<Json>>(
          load: () => MarketerApi(context.api).campaigns(),
          builder: (context, rows, _) {
            if (rows.isEmpty) return ListView(children: [EmptyView(icon: Icons.campaign_outlined, title: context.t('campaigns.empty'), subtitle: context.t('campaigns.emptyHint'))]);
            final ml = MaterialLocalizations.of(context);
            String date(String? iso) {
              final d = iso == null ? null : DateTime.tryParse(iso);
              return d == null ? '—' : ml.formatShortDate(d.toLocal());
            }

            return ListView.separated(
              padding: const EdgeInsets.all(NawySpace.gutter),
              itemCount: rows.length,
              separatorBuilder: (_, _) => const SizedBox(height: 8),
              itemBuilder: (context, i) {
                final c = rows[i];
                final link = c.s('link');
                return Card(
                  key: Key('campaign-${c.i('id')}'),
                  child: Padding(
                    padding: const EdgeInsets.all(12),
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Row(children: [
                        Expanded(child: Text(c.str('name', '—'), style: NawyText.h4)),
                        if (c.s('status') != null) StatusChip(c.str('status')),
                      ]),
                      if (c.s('seller') != null) Text(c.str('seller'), style: NawyText.bodySm.copyWith(color: NawyColors.textSecondary)),
                      const SizedBox(height: 6),
                      Text(context.t('campaigns.commission', {'value': c.s('commission') ?? '—'}), style: NawyText.body),
                      Text(context.t('campaigns.dates', {'from': date(c.s('starts_at')), 'to': date(c.s('ends_at'))}), style: NawyText.bodySm),
                      Text(context.t('campaigns.conversions', {'count': c.i('conversions') ?? 0}), style: NawyText.bodySm),
                      if (link != null)
                        Row(children: [
                          Expanded(child: Text(link, style: NawyText.bodySm.copyWith(color: NawyColors.textLink), maxLines: 1, overflow: TextOverflow.ellipsis, textDirection: TextDirection.ltr)),
                          CopyButton(key: Key('copy-${c.i('id')}'), text: link),
                        ]),
                    ]),
                  ),
                );
              },
            );
          },
        ),
      );
}
