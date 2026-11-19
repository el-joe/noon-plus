import 'package:customer_app/core/platform/platform_bridge.dart';
import 'package:customer_app/routing/guards.dart';
import 'package:customer_app/screens/live/live_webrtc_controller.dart';
import 'package:customer_app/screens/live/live_webrtc_viewer.dart';
import 'package:customer_app/state/app_state.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers.dart';
import '../live_fakes.dart';

/// Live watch screen over native WebRTC (owner-review D18): the in-app
/// viewer's states with a fake socket, fake peer and fake API.
void main() {
  late FakeSockets sockets;
  late FakePeers peers;
  final opened = <String>[];
  final realSocket = LiveWebRtc.socketFactory;
  final realPeer = LiveWebRtc.peerFactory;
  final realSupported = LiveWebRtc.supported;

  setUp(() {
    sockets = FakeSockets();
    peers = FakePeers();
    opened.clear();
    LiveWebRtc.socketFactory = sockets.create;
    LiveWebRtc.peerFactory = peers.create;
    LiveWebRtc.supported = () => true;
    PlatformBridge.debugOpenUrl = (url) async {
      opened.add(url);
      return true;
    };
  });
  tearDown(() {
    LiveWebRtc.socketFactory = realSocket;
    LiveWebRtc.peerFactory = realPeer;
    LiveWebRtc.supported = realSupported;
    PlatformBridge.debugOpenUrl = null;
  });

  final liveStream = {
    'slug': 'demo',
    'title': 'Demo show',
    'status': 'live',
    'viewers': 3,
    'products': [],
    'playback': {'type': 'live', 'webrtc': true, 'host_online': true, 'max_viewers': 20, 'viewers': 3, 'full': false, 'join_url': 'live-streams/demo/join'},
  };

  Future<(AppState, FakeTransport)> open(WidgetTester tester, {Map<String, Object> routes = const {}, String market = 'uae-en'}) async {
    final (state, transport) = await makeState(market: market, routes: {
      'GET live-streams/demo': liveStream,
      ...liveRtcRoutes(),
      ...routes,
    });
    await pumpApp(tester, state);
    await pushRoute(tester, Routes.liveWatch, {'slug': 'demo'});
    await settle(tester);
    return (state, transport);
  }

  void signal(Map<String, dynamic> data) => sockets.last.emit(liveChannel, 'signal', data);

  Future<void> leave(WidgetTester tester) async {
    Navigator.of(tester.element(find.byType(Scaffold).last)).pop();
    await settle(tester, 10);
  }

  testWidgets('plays in the app: offer → answer → video with a mute toggle; leaving sends leave', (tester) async {
    final (_, transport) = await open(tester);
    expect(find.byType(LiveWebRtcViewer), findsOneWidget);
    expect(find.text('Connecting to the live stream…'), findsOneWidget);
    expect(signalTypes(transport), ['ready']);
    // The viewer heartbeats with its token; the screen's own beat is off.
    expect(transport.sent('POST live-streams/demo/presence'), isEmpty);

    signal({'type': 'offer', 'sdp': 'offer-sdp'});
    await settle(tester);
    expect(signals(transport).last['sdp'], 'answer-for-offer-sdp');
    peers.last.emitTrack();
    await settle(tester);
    expect(find.byKey(const ValueKey('fake-rtc-video')), findsOneWidget);
    expect(find.byKey(const ValueKey('live-viewers')), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('live-mute')));
    await settle(tester);
    expect(peers.last.muted, isTrue);
    expect(find.byTooltip('Unmute'), findsOneWidget);

    await tester.pump(const Duration(seconds: 30));
    await settle(tester);
    expect(transport.bodyOf(transport.sent('POST live-streams/demo/presence').single)['peer_token'], 'tok1');

    await leave(tester);
    expect(signals(transport).last, {'peer_token': 'tok2', 'type': 'leave'});
    expect(peers.last.closed, isTrue);
    expect(sockets.last.closed, isTrue);
  });

  testWidgets('waiting for the host: message, ready resent every 8 s', (tester) async {
    final (_, transport) = await open(tester, routes: {'POST live-streams/demo/join': joinData(hostOnline: false)});
    expect(find.text('Waiting for the host to start the video…'), findsOneWidget);
    await tester.pump(const Duration(seconds: 8));
    await settle(tester);
    expect(signalTypes(transport), ['ready', 'ready']);
    await leave(tester);
  });

  testWidgets('stream full: clear message and a retry that joins again', (tester) async {
    final (_, transport) = await open(tester, routes: {'POST live-streams/demo/join': FakeTransport.error(409, 'stream_full', 'Full.')});
    expect(find.text('This live stream is full. Try again in a moment.'), findsOneWidget);
    expect(sockets.sockets, isEmpty);
    transport.routes['POST live-streams/demo/join'] = joinData();
    await tester.tap(find.byKey(const ValueKey('live-rtc-retry')));
    await settle(tester);
    expect(transport.sent('POST live-streams/demo/join'), hasLength(2));
    expect(find.text('Connecting to the live stream…'), findsOneWidget);
    await leave(tester);
  });

  testWidgets('stream full in Arabic', (tester) async {
    await open(tester, market: 'uae-ar', routes: {'POST live-streams/demo/join': FakeTransport.error(409, 'stream_full', 'Full.')});
    expect(find.text('هذا البث المباشر ممتلئ. حاول مرة أخرى بعد قليل.'), findsOneWidget);
    expect(find.text('حاول مرة أخرى'), findsOneWidget);
  });

  testWidgets('ended: the show-ended message with refresh', (tester) async {
    final (_, transport) = await open(tester);
    signal({'type': 'ended'});
    await settle(tester);
    expect(find.text('This live show has ended.'), findsOneWidget);
    expect(sockets.last.closed, isTrue);
    expect(find.byKey(const ValueKey('live-refresh')), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('live-refresh')));
    await settle(tester);
    expect(transport.sent('GET live-streams/demo'), hasLength(2));
    await leave(tester);
  });

  testWidgets('not live: message (409 stream_not_live)', (tester) async {
    await open(tester, routes: {'POST live-streams/demo/join': FakeTransport.error(409, 'stream_not_live', 'Not live.')});
    expect(find.text('The host isn’t live right now.'), findsOneWidget);
  });

  testWidgets('failed: retry rejoins the same slot; the browser stays a fallback', (tester) async {
    final (_, transport) = await open(tester);
    signal({'type': 'offer', 'sdp': 'o'});
    await settle(tester);
    peers.last.emitState(LivePeerState.failed);
    await settle(tester);
    expect(find.text('The live video connection was lost.'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('live-open-web')));
    await settle(tester);
    expect(opened.single, endsWith('/uae-en/live/demo'));

    await tester.tap(find.byKey(const ValueKey('live-rtc-retry')));
    await settle(tester);
    expect(transport.bodyOf(transport.sent('POST live-streams/demo/join').last), {'peer_id': 'p1', 'peer_token': 'tok1'});
    expect(find.text('Connecting to the live stream…'), findsOneWidget);
    await leave(tester);
  });

  testWidgets('socket lost: failed', (tester) async {
    await open(tester);
    sockets.last.drop();
    await settle(tester);
    expect(find.byKey(const ValueKey('live-rtc-status-failed')), findsOneWidget);
    await leave(tester);
  });
}
