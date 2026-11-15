import 'package:flutter/material.dart';

import '../../core/api/api_client.dart';
import '../../core/json.dart';
import '../../core/platform/platform_bridge.dart';
import '../../features/page_builder/vertical_cards.dart';
import '../../l10n/l10n.dart';
import '../../state/app_state.dart';
import '../../ui/theme.dart';
import '../../ui/widgets/common.dart';
import '../../ui/widgets/product_card.dart';
import '../../ui/widgets/states.dart';

/// Figma "50 Nawy TV feed" / app "16 Nawy Live": live, upcoming and replays
/// (`GET live-streams`).
class LiveListScreen extends StatelessWidget {
  const LiveListScreen({super.key, this.asTab = false});

  final bool asTab;

  @override
  Widget build(BuildContext context) {
    final body = AsyncBody<List<Json>>(
      key: ValueKey('live-${AppScope.of(context).market}'),
      load: () => AppScope.read(context).api.getList('live-streams').then((r) => r.data),
      isEmpty: (l) => l.isEmpty,
      empty: EmptyView(icon: Icons.live_tv_rounded, title: context.t('live.empty')),
      builder: (context, streams, reload) {
        final groups = <String, List<Json>>{
          'live': streams.where((s) => s.s('status') == 'live').toList(),
          'scheduled': streams.where((s) => s.s('status') == 'scheduled' || s.s('status') == 'idle').toList(),
          'ended': streams.where((s) => s.s('status') == 'ended').toList(),
        };
        return RefreshIndicator(
          onRefresh: reload,
          child: ListView(padding: const EdgeInsets.only(bottom: 24), children: [
            for (final e in groups.entries)
              if (e.value.isNotEmpty) ...[
                SectionHeader(title: context.t('live.group.${e.key}')),
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 16),
                  child: LayoutBuilder(builder: (context, c) {
                    final w = (c.maxWidth - 12) / 2;
                    return Wrap(spacing: 12, runSpacing: 16, children: [for (final s in e.value) LiveCard(stream: s, width: w)]);
                  }),
                ),
              ],
          ]),
        );
      },
    );
    if (asTab) return SafeArea(child: body);
    return Scaffold(appBar: AppBar(title: Text(context.t('live.title'))), body: body);
  }
}

/// Watch screen. The Flutter SDK has no video player, so the stream opens in
/// the system player/browser (`playback.hls_url` / `media_url`) and the
/// linked products are shoppable here. Swap for `video_player` once approved
/// (owner-review WP10).
class LiveWatchScreen extends StatefulWidget {
  const LiveWatchScreen({super.key, required this.slug});

  final String slug;

  @override
  State<LiveWatchScreen> createState() => _LiveWatchScreenState();
}

class _LiveWatchScreenState extends State<LiveWatchScreen> {
  int? _likes;

  @override
  Widget build(BuildContext context) => Scaffold(
        backgroundColor: NawyColors.bgInverse,
        appBar: AppBar(backgroundColor: NawyColors.bgInverse, foregroundColor: NawyColors.textInverse),
        body: AsyncBody<Json>(
          load: () => AppScope.read(context).api.getObject('live-streams/${enc(widget.slug)}'),
          builder: (context, stream, _) {
            final playback = stream.m('playback');
            final url = playback?.s('hls_url') ?? playback?.s('media_url');
            final live = stream.s('status') == 'live';
            final products = stream.l('products');
            return ListView(children: [
              AspectRatio(
                aspectRatio: 16 / 9,
                child: Stack(fit: StackFit.expand, children: [
                  NetImage(stream.s('thumbnail_url'), icon: Icons.live_tv_rounded, background: NawyColors.bgInverse),
                  Container(color: Colors.black38),
                  Center(
                    child: Column(mainAxisSize: MainAxisSize.min, children: [
                      IconButton(
                        key: const ValueKey('live-play'),
                        tooltip: context.t('live.watch'),
                        iconSize: 64,
                        color: NawyColors.textInverse,
                        onPressed: url == null ? null : () => PlatformBridge.openUrl(url),
                        icon: const Icon(Icons.play_circle_fill_rounded),
                      ),
                      Text(
                        url == null ? context.t(stream.s('status') == 'scheduled' ? 'live.startsSoon' : 'live.unavailable') : context.t('live.opensPlayer'),
                        style: NawyText.bodySm.copyWith(color: NawyColors.textInverse),
                        textAlign: TextAlign.center,
                      ),
                    ]),
                  ),
                  if (live)
                    PositionedDirectional(
                      top: 10,
                      start: 10,
                      child: Pill(context.t('live.live'), background: NawyColors.bgDanger, foreground: NawyColors.textInverse, icon: Icons.circle),
                    ),
                ]),
              ),
              Padding(
                padding: const EdgeInsets.all(16),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(stream.str('title'), style: NawyText.h3.copyWith(color: NawyColors.textInverse)),
                  if (stream.s('scheduled_at') != null && !live)
                    Text(context.t('live.scheduled', {'date': shortDate(context, stream.s('scheduled_at'), time: true)}), style: NawyText.bodySm.copyWith(color: NawyColors.textMuted)),
                  if (stream.s('description') != null) Padding(padding: const EdgeInsets.only(top: 6), child: Text(stream.str('description'), style: NawyText.body.copyWith(color: NawyColors.textInverse))),
                  const SizedBox(height: 8),
                  OutlinedButton.icon(
                    style: OutlinedButton.styleFrom(foregroundColor: NawyColors.textInverse, side: const BorderSide(color: NawyColors.textMuted)),
                    onPressed: () async {
                      final res = await guarded(context, () => AppScope.read(context).api.post('live-streams/${enc(widget.slug)}/likes'));
                      if (res != null && mounted) setState(() => _likes = asJson(res.data).i('likes'));
                    },
                    icon: const Icon(Icons.favorite_rounded, color: NawyColors.bgDanger),
                    label: Text('${_likes ?? stream.i('likes') ?? 0}'),
                  ),
                ]),
              ),
              if (products.isNotEmpty)
                Container(
                  color: NawyColors.bgSurface,
                  padding: const EdgeInsets.only(bottom: 16),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    SectionHeader(title: context.t('live.products')),
                    ProductRail(cards: products),
                  ]),
                ),
            ]);
          },
        ),
      );
}
