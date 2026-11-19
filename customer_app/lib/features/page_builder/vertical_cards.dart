import 'package:flutter/material.dart';

import '../../core/json.dart';
import '../../core/money.dart';
import '../../l10n/l10n.dart';
import '../../routing/guards.dart';
import '../../state/app_state.dart';
import '../../ui/theme.dart';
import '../../ui/widgets/common.dart';

/// Cards for the verticals (Open Souq ads, property, travel, live, creators),
/// shared by page-builder blocks and the vertical screens.

class AdCard extends StatelessWidget {
  const AdCard({super.key, required this.ad, this.width = 220, this.horizontal = false});

  final Json ad;
  final double width;
  final bool horizontal;

  @override
  Widget build(BuildContext context) {
    final property = ad.m('property');
    final facts = <String>[
      if (property?.s('bedrooms') != null) context.t('property.beds', {'n': property!.s('bedrooms')}),
      if (property?.s('bathrooms') != null) context.t('property.baths', {'n': property!.s('bathrooms')}),
      if (property?.s('area_sqm') != null) '${double.tryParse(property!.str('area_sqm'))?.round() ?? property.s('area_sqm')} m²',
    ];
    final image = NetImage(ad.s('image'), radius: NawyRadius.card, icon: property != null ? Icons.apartment_rounded : Icons.storefront_outlined);
    final info = Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
      Text(formatMoney(ad['price'], locale: context.l10n.locale, empty: context.t('souq.priceOnRequest')), style: NawyText.h4),
      Text(ad.str('title'), maxLines: 2, overflow: TextOverflow.ellipsis, style: NawyText.bodySm),
      if (facts.isNotEmpty) Text(facts.join(' · '), style: NawyText.caption.copyWith(color: NawyColors.textSecondary)),
      Text([ad.s('area'), ad.s('city')].whereType<String>().join(', '), style: NawyText.caption.copyWith(color: NawyColors.textMuted), maxLines: 1),
      if (ad.b('is_featured') || ad.b('verified_business'))
        Padding(
          padding: const EdgeInsets.only(top: 3),
          child: Wrap(spacing: 4, children: [
            if (ad.b('is_featured')) Pill(context.t('souq.featured')),
            if (ad.b('verified_business')) Pill(context.t('souq.verified'), background: NawyColors.bgSuccessSubtle, foreground: NawyColors.textSuccess),
          ]),
        ),
    ]);
    return InkWell(
      onTap: () => Navigator.of(context).pushNamed(Routes.souqAd, arguments: {'number': ad.str('number')}),
      child: horizontal
          ? Row(crossAxisAlignment: CrossAxisAlignment.start, children: [SizedBox(width: 120, height: 100, child: image), const SizedBox(width: 12), Expanded(child: info)])
          : SizedBox(width: width, child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [AspectRatio(aspectRatio: 4 / 3, child: image), const SizedBox(height: 6), info])),
    );
  }
}

class AdRail extends StatelessWidget {
  const AdRail({super.key, required this.ads});

  final List<Json> ads;

  @override
  Widget build(BuildContext context) => SizedBox(
        height: 272,
        child: ListView.separated(
          scrollDirection: Axis.horizontal,
          padding: const EdgeInsets.symmetric(horizontal: 16),
          itemCount: ads.length,
          separatorBuilder: (_, _) => const SizedBox(width: 12),
          itemBuilder: (_, i) => AdCard(ad: ads[i]),
        ),
      );
}

/// D14 paid placement: a promoted trip carries `sponsored: true` and the
/// server's localized `label`. Older answers have neither (not sponsored).
bool isSponsoredTrip(Json package) => package.b('sponsored');

/// The "Sponsored" text of a promoted trip: the server label, else ours.
String sponsoredTripLabel(BuildContext context, Json package) {
  final label = package.s('label');
  return label != null && label.trim().isNotEmpty ? label : context.t('travel.sponsored');
}

class TravelCard extends StatelessWidget {
  const TravelCard({super.key, required this.package, this.width = 240});

  final Json package;
  final double width;

  @override
  Widget build(BuildContext context) {
    final from = Money.fromJson(package['from_price']);
    final sponsored = isSponsoredTrip(package);
    return InkWell(
      onTap: () => Navigator.of(context).pushNamed(Routes.travelPackage, arguments: {'slug': package.str('slug')}),
      child: SizedBox(
        width: width,
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          AspectRatio(aspectRatio: 4 / 3, child: NetImage(package.m('image')?.s('url') ?? package.s('cover_url'), radius: NawyRadius.card, icon: Icons.flight_takeoff_rounded)),
          const SizedBox(height: 6),
          if (sponsored)
            Padding(
              padding: const EdgeInsets.only(bottom: 3),
              child: AdLabel(key: ValueKey('trip-sponsored-${package.str('slug')}'), text: sponsoredTripLabel(context, package)),
            ),
          Text(package.str('title'), maxLines: 2, overflow: TextOverflow.ellipsis, style: NawyText.bodyMedium),
          Text([package.s('destination'), if (package.i('nights') != null) context.t('travel.nights', {'n': package.i('nights')})].whereType<String>().join(' · '),
              style: NawyText.caption.copyWith(color: NawyColors.textSecondary)),
          if (from != null) Text(context.t('travel.fromPerPerson', {'price': from.format(locale: context.l10n.locale)}), style: NawyText.label.copyWith(color: NawyColors.textSuccess)),
          if (package.s('agency') != null) Text(package.str('agency'), style: NawyText.caption.copyWith(color: NawyColors.textMuted)),
        ]),
      ),
    );
  }
}

/// Trips in the server's order (sponsored first, D14): never re-sorted.
class TravelRail extends StatelessWidget {
  const TravelRail({super.key, required this.packages});

  final List<Json> packages;

  @override
  Widget build(BuildContext context) => SizedBox(
        height: 310,
        child: ListView.separated(
          scrollDirection: Axis.horizontal,
          padding: const EdgeInsets.symmetric(horizontal: 16),
          itemCount: packages.length,
          separatorBuilder: (_, _) => const SizedBox(width: 12),
          itemBuilder: (_, i) => TravelCard(package: packages[i]),
        ),
      );
}

class LiveCard extends StatelessWidget {
  const LiveCard({super.key, required this.stream, this.width = 150});

  final Json stream;
  final double width;

  @override
  Widget build(BuildContext context) {
    final live = stream.s('status') == 'live';
    return InkWell(
      onTap: () => Navigator.of(context).pushNamed(Routes.liveWatch, arguments: {'slug': stream.str('slug')}),
      child: SizedBox(
        width: width,
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          AspectRatio(
            aspectRatio: 9 / 14,
            child: Stack(children: [
              Positioned.fill(child: NetImage(stream.s('thumbnail_url'), radius: NawyRadius.card, icon: Icons.live_tv_rounded, background: NawyColors.bgInverse)),
              PositionedDirectional(
                top: 6,
                start: 6,
                child: Pill(live ? context.t('live.live') : context.l10n.status(stream.s('status')),
                    background: live ? NawyColors.bgDanger : NawyColors.bgInverse, foreground: NawyColors.textInverse, icon: live ? Icons.circle : null),
              ),
            ]),
          ),
          const SizedBox(height: 4),
          Text(stream.str('title'), maxLines: 2, overflow: TextOverflow.ellipsis, style: NawyText.label),
        ]),
      ),
    );
  }
}

class LiveRail extends StatelessWidget {
  const LiveRail({super.key, required this.streams});

  final List<Json> streams;

  @override
  Widget build(BuildContext context) => SizedBox(
        height: 290,
        child: ListView.separated(
          scrollDirection: Axis.horizontal,
          padding: const EdgeInsets.symmetric(horizontal: 16),
          itemCount: streams.length,
          separatorBuilder: (_, _) => const SizedBox(width: 10),
          itemBuilder: (_, i) => LiveCard(stream: streams[i]),
        ),
      );
}

class CreatorRail extends StatelessWidget {
  const CreatorRail({super.key, required this.items});

  final List<Json> items;

  @override
  Widget build(BuildContext context) => SizedBox(
        height: 120,
        child: ListView.separated(
          scrollDirection: Axis.horizontal,
          padding: const EdgeInsets.symmetric(horizontal: 16),
          itemCount: items.length,
          separatorBuilder: (_, _) => const SizedBox(width: 14),
          itemBuilder: (_, i) {
            final c = items[i];
            return SizedBox(
              width: 76,
              child: Column(children: [
                ClipOval(child: SizedBox(width: 64, height: 64, child: NetImage(c.s('avatar_url'), icon: Icons.person_rounded))),
                const SizedBox(height: 4),
                Text(c.str('name'), maxLines: 2, textAlign: TextAlign.center, style: NawyText.caption),
              ]),
            );
          },
        ),
      );
}

/// `verticals` block: shortcuts to the app contexts (ADR 16).
class VerticalsBlock extends StatelessWidget {
  const VerticalsBlock({super.key});

  @override
  Widget build(BuildContext context) {
    final app = AppScope.of(context);
    final contexts = app.contexts.where((c) => c.str('key') != 'main').toList();
    if (contexts.isEmpty) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
      child: Wrap(spacing: 8, runSpacing: 8, children: [
        for (final c in contexts)
          ActionChip(
            label: Text(c.str('name')),
            backgroundColor: hexColor(c.m('theme')?.s('header_bg')),
            labelStyle: NawyText.label.copyWith(color: hexColor(c.m('theme')?.s('header_fg'))),
            onPressed: () => app.switchContext(c.str('key')),
          ),
      ]),
    );
  }
}
