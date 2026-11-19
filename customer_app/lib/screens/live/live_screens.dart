import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/api/api_client.dart';
import '../../core/config.dart';
import '../../core/json.dart';
import '../../ui/widgets/inline_video.dart';
import '../../features/page_builder/vertical_cards.dart';
import '../../l10n/l10n.dart';
import '../../state/app_state.dart';
import '../../ui/theme.dart';
import '../../ui/widgets/common.dart';
import '../../ui/widgets/product_card.dart';
import '../../ui/widgets/states.dart';
import 'live_chat.dart';
import 'live_webrtc_viewer.dart';

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
                    return Wrap(spacing: 12, runSpacing: 16, children: [
                      for (final s in e.value)
                        // QC-040: summaries carry `viewers` (watching in the last 60 s).
                        Stack(children: [
                          LiveCard(stream: s, width: w),
                          if (s.s('status') == 'live' && (s.i('viewers') ?? 0) > 0)
                            PositionedDirectional(top: 6, end: 6, child: ViewerCount(count: s.i('viewers')!, key: ValueKey('live-viewers-${s.str('slug')}'))),
                        ]),
                    ]);
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

/// Watch screen: a live broadcast plays in the app over native WebRTC
/// ([LiveWebRtcViewer], owner-review D18; the storefront page is the
/// fallback); a recording (`media_url`) plays with [InlineVideo]
/// (`video_player`, D16 / P4), falling back to the system player. The linked
/// products stay shoppable.
class LiveWatchScreen extends StatefulWidget {
  const LiveWatchScreen({super.key, required this.slug});

  final String slug;

  @override
  State<LiveWatchScreen> createState() => _LiveWatchScreenState();
}

class _LiveWatchScreenState extends State<LiveWatchScreen> {
  int? _likes;
  int? _viewers;
  Timer? _presence;

  /// Presence heartbeat (QC-040): `POST presence` now and every 30 s while
  /// the live stream is open; the answer is the number watching. The WebRTC
  /// viewer sends its own heartbeat (with its peer token) instead.
  void _startPresence() {
    if (_presence != null) return;
    _presence = Timer.periodic(LiveTimings.presence, (_) => _beat());
    _beat();
  }

  Future<void> _beat() async {
    try {
      final res = await AppScope.read(context).api.post('live-streams/${enc(widget.slug)}/presence', body: {'viewer_id': LiveViewer.id});
      final viewers = asJson(res.data).i('viewers');
      if (mounted && viewers != null) setState(() => _viewers = viewers);
    } catch (_) {
      // Best effort: the next beat retries.
    }
  }

  @override
  void dispose() {
    _presence?.cancel();
    super.dispose();
  }

  List<Widget> _badges(BuildContext context, bool live, int? viewers) => [
        if (live)
          PositionedDirectional(
            top: 10,
            start: 10,
            child: Pill(context.t('live.live'), background: NawyColors.bgDanger, foreground: NawyColors.textInverse, icon: Icons.circle),
          ),
        if (live && viewers != null)
          PositionedDirectional(top: 10, end: 10, child: ViewerCount(count: viewers, key: const ValueKey('live-viewers'))),
      ];

  @override
  Widget build(BuildContext context) => Scaffold(
        backgroundColor: NawyColors.bgInverse,
        appBar: AppBar(backgroundColor: NawyColors.bgInverse, foregroundColor: NawyColors.textInverse),
        body: AsyncBody<Json>(
          load: () => AppScope.read(context).api.getObject('live-streams/${enc(widget.slug)}'),
          builder: (context, stream, reload) {
            final playback = stream.m('playback');
            final live = stream.s('status') == 'live';
            final webrtc = liveUsesWebRtc(stream);
            // Recordings: `media_url`. A legacy `hls_url` is still tolerated
            // for a live stream that is not on WebRTC.
            final url = webrtc ? null : playback?.s('media_url') ?? (live ? playback?.s('hls_url') : null);
            final products = stream.l('products');
            if (live && !webrtc && _presence == null) WidgetsBinding.instance.addPostFrameCallback((_) => mounted ? _startPresence() : null);
            final viewers = _viewers ?? stream.i('viewers');
            final poster = NetImage(stream.s('thumbnail_url'), icon: Icons.live_tv_rounded, background: NawyColors.bgInverse);
            return ListView(children: [
              AspectRatio(
                aspectRatio: 16 / 9,
                // D18: live over native WebRTC; D16 / P4: the recording plays
                // in the app, the system player is the fallback.
                child: webrtc
                    ? LiveWebRtcViewer(
                        slug: widget.slug,
                        webUrl: liveWebUrl(AppScope.read(context).market, widget.slug),
                        poster: poster,
                        overlay: _badges(context, live, viewers),
                        onReload: reload,
                        onViewers: (n) {
                          if (mounted) setState(() => _viewers = n);
                        },
                      )
                    : url == null
                    ? Stack(fit: StackFit.expand, children: [
                        poster,
                        Container(color: Colors.black54),
                        Center(child: _NoPlayback(stream: stream, onRefresh: reload)),
                        ..._badges(context, live, viewers),
                      ])
                    : InlineVideo(
                        url: url,
                        playKey: const ValueKey('live-play'),
                        poster: poster,
                        overlay: _badges(context, live, viewers),
                      ),
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
              LiveChat(
                slug: widget.slug,
                live: live,
                onMeta: (viewers, likes) {
                  if (!mounted) return;
                  setState(() {
                    if (viewers != null) _viewers = viewers;
                    if (likes != null) _likes = likes;
                  });
                },
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

/// A live broadcast the app joins over native WebRTC (D18): status `live`,
/// `playback.type == 'live'` and `playback.webrtc`.
bool liveUsesWebRtc(Json stream) {
  final playback = stream.m('playback');
  return stream.s('status') == 'live' && playback != null && playback.str('type', 'live') == 'live' && playback.b('webrtc');
}

/// Why the watch screen has no video to play (no WebRTC broadcast and no
/// `media_url`), from the stream's real state.
enum LiveFallback {
  /// `scheduled`: not started; the start time shows under the title.
  scheduled,

  /// `idle`: the host has not started (or paused) the stream.
  notStarted,

  /// `ended` without a recording.
  ended,

  /// `live` but nothing to play right now (not on WebRTC).
  unavailable,
}

LiveFallback liveFallback(Json stream) => switch (stream.s('status')) {
      'scheduled' => LiveFallback.scheduled,
      'idle' => LiveFallback.notStarted,
      'ended' => LiveFallback.ended,
      _ => LiveFallback.unavailable,
    };

/// The storefront watch page (`/{market}/live/{slug}`, browser WebRTC
/// viewer): the fallback when the in-app viewer cannot play.
String liveWebUrl(String? market, String slug) => '${AppConfig.storefrontUrl}/${market ?? 'uae-en'}/live/${Uri.encodeComponent(slug)}';

class _NoPlayback extends StatelessWidget {
  const _NoPlayback({required this.stream, required this.onRefresh});

  final Json stream;
  final Future<void> Function() onRefresh;

  @override
  Widget build(BuildContext context) {
    final reason = liveFallback(stream);
    final (icon, message) = switch (reason) {
      LiveFallback.scheduled => (Icons.schedule_rounded, 'live.startsSoon'),
      LiveFallback.notStarted => (Icons.hourglass_empty_rounded, 'live.notStarted'),
      LiveFallback.ended => (Icons.videocam_off_outlined, 'live.endedNoReplay'),
      LiveFallback.unavailable => (Icons.signal_wifi_statusbar_connected_no_internet_4_outlined, 'live.unavailable'),
    };
    const fg = NawyColors.textInverse;
    final button = OutlinedButton.styleFrom(foregroundColor: fg, side: const BorderSide(color: NawyColors.textMuted));
    return Padding(
      padding: const EdgeInsets.all(16),
      child: Column(key: ValueKey('live-fallback-${reason.name}'), mainAxisSize: MainAxisSize.min, children: [
        Icon(icon, size: 40, color: fg),
        const SizedBox(height: 8),
        Text(context.t(message), style: NawyText.bodySm.copyWith(color: fg), textAlign: TextAlign.center),
        const SizedBox(height: 8),
        if (reason != LiveFallback.ended)
          OutlinedButton.icon(key: const ValueKey('live-refresh'), style: button, onPressed: onRefresh, icon: const Icon(Icons.refresh_rounded, size: 18), label: Text(context.t('live.refresh'))),
      ]),
    );
  }
}
