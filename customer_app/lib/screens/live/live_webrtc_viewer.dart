import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:nawy_core/realtime.dart';

import '../../core/platform/platform_bridge.dart';
import '../../l10n/l10n.dart';
import '../../state/app_state.dart';
import '../../ui/theme.dart';
import 'live_chat.dart' show LiveViewer;
import 'live_webrtc_controller.dart';
import 'live_webrtc_peer.dart';

/// Seams for the live WebRTC viewer (swapped in widget tests).
class LiveWebRtc {
  const LiveWebRtc._();

  static LiveSocketFactory socketFactory = PusherClient.new;
  static LivePeerFactory peerFactory = FlutterWebRtcPeer.create;

  /// A realtime socket exists in this build (not on the web).
  static bool Function() supported = () => !kIsWeb && PusherClient.supported;
}

/// In-app live video over native WebRTC (owner-review D18) for a live
/// broadcast (`playback.type == 'live'` and `playback.webrtc`). Shows the
/// host's video when connected and a status panel otherwise (connecting,
/// waiting for the host, full, ended, failed with retry). The storefront
/// page stays the fallback ([webUrl]).
class LiveWebRtcViewer extends StatefulWidget {
  const LiveWebRtcViewer({super.key, required this.slug, required this.webUrl, this.poster, this.overlay = const [], this.onViewers, this.onReload});

  final String slug;

  /// The storefront watch page (fallback when the viewer cannot play).
  final String webUrl;
  final Widget? poster;
  final List<Widget> overlay;
  final void Function(int viewers)? onViewers;

  /// Reloads the stream (after "ended" / "not live", a replay may exist).
  final Future<void> Function()? onReload;

  @override
  State<LiveWebRtcViewer> createState() => _LiveWebRtcViewerState();
}

class _LiveWebRtcViewerState extends State<LiveWebRtcViewer> {
  LiveWebRtcController? _controller;
  bool _unsupported = false;

  @override
  void initState() {
    super.initState();
    if (!LiveWebRtc.supported()) {
      _unsupported = true;
      return;
    }
    _controller = LiveWebRtcController(
      api: AppScope.read(context).api,
      slug: widget.slug,
      viewerId: LiveViewer.id,
      socketFactory: LiveWebRtc.socketFactory,
      peerFactory: LiveWebRtc.peerFactory,
      onViewers: (n) => widget.onViewers?.call(n),
    )..start();
  }

  @override
  void dispose() {
    _controller?.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final controller = _controller;
    if (controller == null || _unsupported) return _frame(context, LiveViewerPhase.unsupported, null);
    return ListenableBuilder(listenable: controller, builder: (context, _) => _frame(context, controller.phase, controller));
  }

  Widget _frame(BuildContext context, LiveViewerPhase phase, LiveWebRtcController? controller) {
    final peer = controller?.peer;
    final playing = phase == LiveViewerPhase.playing && peer != null;
    return Stack(fit: StackFit.expand, key: ValueKey('live-rtc-${phase.name}'), children: [
      Container(color: Colors.black),
      if (playing) peer.buildView() else ...[
        ?widget.poster,
        Container(color: Colors.black54),
        Center(child: SingleChildScrollView(child: _status(context, phase, controller))),
      ],
      ...widget.overlay,
      if (playing)
        PositionedDirectional(
          bottom: 8,
          end: 8,
          child: IconButton.filledTonal(
            key: const ValueKey('live-mute'),
            tooltip: context.t(controller!.muted ? 'live.rtc.unmute' : 'live.rtc.mute'),
            onPressed: controller.toggleMute,
            icon: Icon(controller.muted ? Icons.volume_off_rounded : Icons.volume_up_rounded),
          ),
        ),
    ]);
  }

  Widget _status(BuildContext context, LiveViewerPhase phase, LiveWebRtcController? controller) {
    const fg = NawyColors.textInverse;
    final button = OutlinedButton.styleFrom(foregroundColor: fg, side: const BorderSide(color: NawyColors.textMuted));
    final (icon, message) = switch (phase) {
      LiveViewerPhase.connecting || LiveViewerPhase.playing => (null, 'live.rtc.connecting'),
      LiveViewerPhase.waitingForHost => (Icons.hourglass_empty_rounded, 'live.rtc.waiting'),
      LiveViewerPhase.streamFull => (Icons.groups_rounded, controller?.maxViewers == null ? 'live.rtc.fullNoCount' : 'live.rtc.full'),
      LiveViewerPhase.hostNotLive => (Icons.videocam_off_outlined, 'live.rtc.notLive'),
      LiveViewerPhase.ended => (Icons.videocam_off_outlined, 'live.rtc.ended'),
      LiveViewerPhase.failed => (Icons.signal_wifi_statusbar_connected_no_internet_4_outlined, 'live.rtc.failed'),
      LiveViewerPhase.unsupported => (Icons.open_in_browser_rounded, 'live.rtc.unsupported'),
    };
    final retry = OutlinedButton.icon(
      key: const ValueKey('live-rtc-retry'),
      style: button,
      onPressed: () => controller?.retry(),
      icon: const Icon(Icons.refresh_rounded, size: 18),
      label: Text(context.t('live.rtc.retry')),
    );
    final web = TextButton.icon(
      key: const ValueKey('live-open-web'),
      style: TextButton.styleFrom(foregroundColor: fg),
      onPressed: () => PlatformBridge.openUrl(widget.webUrl),
      icon: const Icon(Icons.open_in_new_rounded, size: 18),
      label: Text(context.t('live.watchInBrowser')),
    );
    final refresh = widget.onReload == null
        ? null
        : OutlinedButton.icon(key: const ValueKey('live-refresh'), style: button, onPressed: widget.onReload, icon: const Icon(Icons.refresh_rounded, size: 18), label: Text(context.t('live.refresh')));
    return Padding(
      padding: const EdgeInsets.all(16),
      child: Column(key: ValueKey('live-rtc-status-${phase.name}'), mainAxisSize: MainAxisSize.min, children: [
        if (icon == null)
          const SizedBox(width: 32, height: 32, child: CircularProgressIndicator(color: fg, strokeWidth: 3))
        else
          Icon(icon, size: 40, color: fg),
        const SizedBox(height: 8),
        Text(context.t(message, {'max': controller?.maxViewers}), style: NawyText.bodySm.copyWith(color: fg), textAlign: TextAlign.center),
        const SizedBox(height: 8),
        switch (phase) {
          LiveViewerPhase.streamFull => retry,
          LiveViewerPhase.failed => Wrap(alignment: WrapAlignment.center, spacing: 8, runSpacing: 4, children: [retry, web]),
          LiveViewerPhase.unsupported => web,
          LiveViewerPhase.ended || LiveViewerPhase.hostNotLive => refresh ?? const SizedBox.shrink(),
          _ => const SizedBox.shrink(),
        },
      ]),
    );
  }
}
