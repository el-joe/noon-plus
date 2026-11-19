import 'package:flutter/material.dart';

import '../../core/json.dart';
import '../../l10n/l10n.dart';
import '../../routing/guards.dart';
import '../../state/app_state.dart';
import '../../ui/theme.dart';
import '../../ui/widgets/common.dart';
import '../../ui/widgets/states.dart';
import '../catalog/pdp_extras.dart';

/// Home "Offers for you" (QC-064, `GET offers`): public coupons the shopper
/// can still use (tap copies the code) and running / upcoming flash sales.
class OffersForYou extends StatelessWidget {
  const OffersForYou({super.key});

  @override
  Widget build(BuildContext context) => OnceFuture<Json>(
        load: () => AppScope.read(context).api.getObject('offers'),
        builder: (context, snap) {
          final data = snap.data ?? const <String, dynamic>{};
          final coupons = data.l('coupons');
          final sales = data.l('flash_sales');
          if (coupons.isEmpty && sales.isEmpty) return const SizedBox.shrink();
          return Column(key: const ValueKey('home-offers'), crossAxisAlignment: CrossAxisAlignment.start, children: [
            SectionHeader(title: context.t('offers.title'), subtitle: coupons.isEmpty ? null : context.t('offers.tapToCopy')),
            SizedBox(
              height: 64,
              child: ListView(scrollDirection: Axis.horizontal, padding: const EdgeInsets.symmetric(horizontal: 16), children: [
                for (final c in coupons) Padding(padding: const EdgeInsetsDirectional.only(end: 8), child: Center(child: CouponChip(coupon: c))),
                for (final s in sales)
                  Padding(
                    padding: const EdgeInsetsDirectional.only(end: 8),
                    child: Center(
                      child: ActionChip(
                        key: ValueKey('offer-sale-${s.i('id')}'),
                        avatar: const Icon(Icons.bolt_rounded, size: 16, color: NawyColors.textDanger),
                        label: Text(context.t(s.s('state') == 'live' ? 'offers.saleLive' : 'offers.saleSoon', {'name': s.str('name'), 'count': s.i('deals_count') ?? 0})),
                        onPressed: () => Navigator.of(context).pushNamed(Routes.flashSale, arguments: {'id': s.s('slug') ?? '${s.i('id')}'}),
                      ),
                    ),
                  ),
              ]),
            ),
          ]);
        },
      );
}

/// The home floating mini-player entry (QC-063, `GET live-streams/floating`,
/// a country setting): a small card over the home that opens the stream.
/// Dismissed for the rest of the session.
class FloatingLivePlayer extends StatefulWidget {
  const FloatingLivePlayer({super.key});

  static bool dismissed = false;

  @override
  State<FloatingLivePlayer> createState() => _FloatingLivePlayerState();
}

class _FloatingLivePlayerState extends State<FloatingLivePlayer> {
  @override
  Widget build(BuildContext context) {
    if (FloatingLivePlayer.dismissed) return const SizedBox.shrink();
    return OnceFuture<Json?>(
      load: () async {
        try {
          final data = (await AppScope.read(context).api.get('live-streams/floating')).data;
          return data is Map ? asJson(data) : null;
        } catch (_) {
          return null;
        }
      },
      builder: (context, snap) {
        final stream = snap.data;
        if (stream == null || stream.s('slug') == null || FloatingLivePlayer.dismissed) return const SizedBox.shrink();
        final live = stream.s('status') == 'live';
        return Material(
          key: const ValueKey('floating-player'),
          elevation: 6,
          color: NawyColors.bgInverse,
          borderRadius: BorderRadius.circular(NawyRadius.lg),
          clipBehavior: Clip.antiAlias,
          child: InkWell(
            onTap: () => Navigator.of(context).pushNamed(Routes.liveWatch, arguments: {'slug': stream.s('slug')}),
            child: SizedBox(
              width: 150,
              height: 110,
              child: Stack(children: [
                Positioned.fill(child: NetImage(stream.s('thumbnail_url'), icon: Icons.live_tv_rounded, background: NawyColors.bgInverse)),
                PositionedDirectional(
                  top: 6,
                  start: 6,
                  child: Pill(live ? context.t('live.live') : context.l10n.status(stream.s('status')), background: live ? NawyColors.bgDanger : NawyColors.bgSurface, foreground: live ? NawyColors.textInverse : NawyColors.textPrimary),
                ),
                PositionedDirectional(
                  top: 0,
                  end: 0,
                  child: IconButton(
                    key: const ValueKey('floating-player-close'),
                    tooltip: context.t('common.close'),
                    visualDensity: VisualDensity.compact,
                    onPressed: () => setState(() => FloatingLivePlayer.dismissed = true),
                    icon: const Icon(Icons.close_rounded, size: 16, color: NawyColors.textInverse),
                  ),
                ),
                PositionedDirectional(
                  bottom: 6,
                  start: 8,
                  end: 8,
                  child: Text(
                    [stream.str('title'), if ((stream.i('viewers') ?? 0) > 0) context.t('home.watching', {'count': stream.i('viewers')})].join(' · '),
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: NawyText.caption.copyWith(color: NawyColors.textInverse),
                  ),
                ),
              ]),
            ),
          ),
        );
      },
    );
  }
}
