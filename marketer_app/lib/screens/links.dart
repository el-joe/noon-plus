import 'package:flutter/material.dart';
import 'package:nawy_core/nawy_core.dart';

import '../data/marketer_api.dart';
import 'copy.dart';

/// Figma "Marketer links": the main referral link and one per campaign.
class LinksScreen extends StatelessWidget {
  const LinksScreen({super.key});

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(context.t('links.title'))),
        body: AsyncBody<Json>(
          load: () => MarketerApi(context.api).links(),
          builder: (context, data, _) {
            final main = data.s('referral_link');
            final links = data.l('links');
            return ListView(padding: const EdgeInsets.all(NawySpace.gutter), children: [
              Card(
                color: NawyColors.bgBrandSubtle,
                child: ListTile(
                  title: Text(context.t('links.main'), style: NawyText.label),
                  subtitle: Text(main ?? context.t('links.none'), key: const Key('main-link'), textDirection: TextDirection.ltr),
                  trailing: main == null ? null : CopyButton(key: const Key('copy-main'), text: main),
                ),
              ),
              SectionTitle(context.t('links.perCampaign')),
              if (links.isEmpty) Text(context.t('links.none'), style: NawyText.body.copyWith(color: NawyColors.textSecondary)),
              for (final l in links)
                Card(
                  child: ListTile(
                    title: Text(l.str('campaign')),
                    subtitle: Text('${l.str('url')}\n${context.t('links.stats', {'clicks': l.i('clicks') ?? 0, 'conversions': l.i('conversions') ?? 0})}'),
                    isThreeLine: true,
                    trailing: CopyButton(text: l.str('url')),
                  ),
                ),
            ]);
          },
        ),
      );
}
