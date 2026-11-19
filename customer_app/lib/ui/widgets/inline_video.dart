import 'dart:async';

import 'package:flutter/material.dart';
import 'package:video_player/video_player.dart';

import '../../core/platform/platform_bridge.dart';
import '../../l10n/l10n.dart';
import '../theme.dart';

/// Builds the player for a URL; tests swap it for a fake (failure path).
typedef VideoControllerFactory = VideoPlayerController Function(Uri url);

VideoPlayerController defaultVideoController(Uri url) => VideoPlayerController.networkUrl(
      url,
      formatHint: url.path.toLowerCase().endsWith('.m3u8') ? VideoFormat.hls : null,
    );

enum InlineVideoState { idle, loading, playing, failed }

/// In-app playback of an HLS stream / recording / video ad creative
/// (`video_player`, owner-review D16 / P4). Shows [poster] with a play button
/// until tapped; play / pause once loaded. When the player cannot open the
/// stream (no HLS support on the platform, network, codec), it falls back to
/// the system player / browser ([PlatformBridge.openUrl]) and keeps an
/// "Open in player" button.
class InlineVideo extends StatefulWidget {
  const InlineVideo({super.key, required this.url, this.poster, this.overlay = const [], this.playKey, this.caption, this.radius = 0});

  /// Swapped in widget tests.
  static VideoControllerFactory controllerFactory = defaultVideoController;

  /// How long the player may take to load before falling back.
  static Duration loadTimeout = const Duration(seconds: 20);

  final String url;
  final Widget? poster;

  /// Badges drawn on top (Live pill, viewer count, headline).
  final List<Widget> overlay;
  final Key? playKey;

  /// Text under the play button before playback starts.
  final String? caption;
  final double radius;

  @override
  State<InlineVideo> createState() => _InlineVideoState();
}

class _InlineVideoState extends State<InlineVideo> {
  VideoPlayerController? _controller;
  InlineVideoState state = InlineVideoState.idle;
  bool _openedExternally = false;

  Future<void> _start() async {
    final uri = Uri.tryParse(widget.url);
    if (uri == null) return _fail();
    setState(() => state = InlineVideoState.loading);
    final controller = InlineVideo.controllerFactory(uri);
    _controller = controller;
    try {
      await controller.initialize().timeout(InlineVideo.loadTimeout);
      if (!mounted || _controller != controller) return;
      controller.addListener(_onTick);
      await controller.play();
      if (mounted) setState(() => state = InlineVideoState.playing);
    } catch (_) {
      if (mounted && _controller == controller) _fail();
    }
  }

  void _onTick() {
    final c = _controller;
    if (c == null || !mounted) return;
    if (c.value.hasError) {
      _fail();
    } else {
      setState(() {});
    }
  }

  void _release() {
    final c = _controller;
    _controller = null;
    if (c == null) return;
    c.removeListener(_onTick);
    // Not awaited: a player that never finished creating may not complete.
    unawaited(c.dispose().catchError((Object _) {}));
  }

  void _fail() {
    _release();
    setState(() => state = InlineVideoState.failed);
    if (!_openedExternally) {
      _openedExternally = true;
      PlatformBridge.openUrl(widget.url);
    }
  }

  void _toggle() {
    final c = _controller;
    if (c == null) return;
    c.value.isPlaying ? c.pause() : c.play();
    setState(() {});
  }

  @override
  void didUpdateWidget(InlineVideo oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.url != widget.url) {
      _release();
      state = InlineVideoState.idle;
      _openedExternally = false;
    }
  }

  @override
  void dispose() {
    _release();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final c = _controller;
    final ready = state == InlineVideoState.playing && c != null && c.value.isInitialized;
    final content = Stack(fit: StackFit.expand, children: [
      Container(color: NawyColors.bgInverse),
      if (ready)
        GestureDetector(
          onTap: _toggle,
          child: Center(child: AspectRatio(aspectRatio: c.value.aspectRatio == 0 ? 16 / 9 : c.value.aspectRatio, child: VideoPlayer(c))),
        )
      else ...[
        ?widget.poster,
        Container(color: Colors.black38),
        Center(child: _status(context)),
      ],
      if (ready)
        PositionedDirectional(
          start: 4,
          bottom: 4,
          child: IconButton(
            key: const ValueKey('video-toggle'),
            tooltip: context.t(c.value.isPlaying ? 'live.pause' : 'live.play'),
            color: NawyColors.textInverse,
            onPressed: _toggle,
            icon: Icon(c.value.isPlaying ? Icons.pause_circle_filled_rounded : Icons.play_circle_fill_rounded),
          ),
        ),
      ...widget.overlay,
    ]);
    return widget.radius > 0 ? ClipRRect(borderRadius: BorderRadius.circular(widget.radius), child: content) : content;
  }

  Widget _status(BuildContext context) => switch (state) {
        InlineVideoState.loading || InlineVideoState.playing => const CircularProgressIndicator(key: ValueKey('video-loading'), color: NawyColors.textInverse),
        InlineVideoState.failed => Padding(
            padding: const EdgeInsets.all(12),
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              Text(context.t('live.playbackFailed'), key: const ValueKey('video-failed'), style: NawyText.bodySm.copyWith(color: NawyColors.textInverse), textAlign: TextAlign.center),
              const SizedBox(height: 8),
              OutlinedButton.icon(
                key: const ValueKey('video-open-external'),
                style: OutlinedButton.styleFrom(foregroundColor: NawyColors.textInverse, side: const BorderSide(color: NawyColors.textInverse)),
                onPressed: () => PlatformBridge.openUrl(widget.url),
                icon: const Icon(Icons.open_in_new_rounded, size: 18),
                label: Text(context.t('live.openExternal')),
              ),
            ]),
          ),
        InlineVideoState.idle => Column(mainAxisSize: MainAxisSize.min, children: [
            IconButton(
              key: widget.playKey ?? const ValueKey('video-play'),
              tooltip: context.t('live.play'),
              iconSize: 64,
              color: NawyColors.textInverse,
              onPressed: _start,
              icon: const Icon(Icons.play_circle_fill_rounded),
            ),
            if (widget.caption != null) Text(widget.caption!, style: NawyText.bodySm.copyWith(color: NawyColors.textInverse), textAlign: TextAlign.center),
          ]),
      };
}
