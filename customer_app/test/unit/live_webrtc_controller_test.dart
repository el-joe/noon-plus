import 'package:customer_app/core/api/api_client.dart';
import 'package:customer_app/screens/live/live_webrtc_controller.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers.dart';
import '../live_fakes.dart';

/// The D18 viewer state machine over a fake socket, fake peer and fake API.
/// `testWidgets` only for its fake clock (ready resend, heartbeat, ICE grace).
void main() {
  late FakeSockets sockets;
  late FakePeers peers;
  late FakeTransport transport;
  late List<int> viewerCounts;

  LiveWebRtcController make(Map<String, Object> routes, {FakePeers? withPeers}) {
    sockets = FakeSockets();
    peers = withPeers ?? FakePeers();
    transport = FakeTransport(routes);
    viewerCounts = [];
    final api = ApiClient(baseUrl: 'https://api.test/api/v1', transport: transport)..market = 'uae-en';
    return LiveWebRtcController(
      api: api,
      slug: 'demo',
      viewerId: 'app-viewer',
      socketFactory: sockets.create,
      peerFactory: peers.create,
      onViewers: viewerCounts.add,
    );
  }

  Future<void> flush(WidgetTester tester, [Duration by = Duration.zero]) async {
    await tester.pump(by);
    await tester.pump();
  }

  void signal(Map<String, dynamic> data) => sockets.last.emit(liveChannel, 'signal', data);

  testWidgets('join → socket from the join answer → private channel auth with the peer token → ready', (tester) async {
    final c = make(liveRtcRoutes());
    await c.start();
    await flush(tester);
    expect(transport.bodyOf(transport.sent('POST live-streams/demo/join').single), isEmpty);
    final config = sockets.configs.single;
    expect(config.uri.toString(), 'ws://ws.test:8080/app/reverb-key?protocol=7&client=nawy-dart&version=8.4.0&flash=false');
    final auth = transport.bodyOf(transport.sent('POST broadcasting/auth').single);
    expect(auth, {'socket_id': '123.456', 'channel_name': liveChannel, 'peer_token': 'tok1'});
    expect(sockets.last.subscriptions, {liveChannel: 'reverb-key:sig'});
    expect(signals(transport).single, {'peer_token': 'tok1', 'type': 'ready'});
    expect(c.phase, LiveViewerPhase.connecting);
    expect(viewerCounts, [4]);
    expect(c.maxViewers, 20);
    c.dispose();
  });

  testWidgets('offer → answer posted; remote candidates buffered until the offer is set; own candidates trickled after the answer', (tester) async {
    const early = IceCandidateData('candidate:early', sdpMid: '0', sdpMLineIndex: 0);
    const gathered = IceCandidateData('candidate:local-1', sdpMid: '0', sdpMLineIndex: 0);
    final c = make(liveRtcRoutes(), withPeers: FakePeers(candidatesOnAnswer: [gathered]));
    await c.start();
    await flush(tester);

    // A host candidate that races ahead of the offer is held back.
    signal({'type': 'candidate', 'candidate': early.toJson()});
    signal({'type': 'offer', 'sdp': 'offer-sdp'});
    await flush(tester);
    final peer = peers.last;
    expect(peer.iceServers, hasLength(2));
    expect(peer.remoteOffer, 'offer-sdp');
    expect(peer.added, [early]);
    final types = signalTypes(transport);
    expect(types, ['ready', 'answer', 'candidate']);
    expect(signals(transport)[1]['sdp'], 'answer-for-offer-sdp');
    expect(signals(transport)[2]['candidate'], gathered.toJson());

    // After the answer: own candidates go out at once, host ones are added.
    peer.emitCandidate(const IceCandidateData('candidate:local-2', sdpMid: '1', sdpMLineIndex: 1));
    signal({'type': 'candidate', 'candidate': {'candidate': 'candidate:late', 'sdpMid': '1', 'sdpMLineIndex': 1}});
    await flush(tester);
    expect(signals(transport).last['candidate'], {'candidate': 'candidate:local-2', 'sdpMid': '1', 'sdpMLineIndex': 1});
    expect(peer.added.last.candidate, 'candidate:late');

    expect(c.phase, LiveViewerPhase.connecting);
    peer.emitTrack();
    expect(c.phase, LiveViewerPhase.playing);
    expect(c.peer, same(peer));
    c.dispose();
  });

  testWidgets('host offline: waiting for host, ready resent every 8 s until the offer', (tester) async {
    final c = make(liveRtcRoutes(join: joinData(hostOnline: false)));
    await c.start();
    await flush(tester);
    expect(c.phase, LiveViewerPhase.waitingForHost);
    expect(signalTypes(transport), ['ready']);
    await flush(tester, const Duration(seconds: 8));
    expect(signalTypes(transport), ['ready', 'ready']);
    await flush(tester, const Duration(seconds: 8));
    expect(signalTypes(transport), ['ready', 'ready', 'ready']);
    signal({'type': 'offer', 'sdp': 'o'});
    await flush(tester);
    expect(c.phase, LiveViewerPhase.connecting);
    await flush(tester, const Duration(seconds: 8));
    expect(signalTypes(transport).where((t) => t == 'ready'), hasLength(3));
    c.dispose();
  });

  testWidgets('host online but no offer within 8 s: waiting for host', (tester) async {
    final c = make(liveRtcRoutes());
    await c.start();
    await flush(tester);
    expect(c.phase, LiveViewerPhase.connecting);
    await flush(tester, const Duration(seconds: 8));
    expect(c.phase, LiveViewerPhase.waitingForHost);
    c.dispose();
  });

  testWidgets('online: the peer is dropped and ready sent again; a new offer makes a new peer', (tester) async {
    final c = make(liveRtcRoutes());
    await c.start();
    await flush(tester);
    signal({'type': 'offer', 'sdp': 'o1'});
    await flush(tester);
    peers.last.emitTrack();
    expect(c.phase, LiveViewerPhase.playing);
    final first = peers.last;

    signal({'type': 'online'});
    await flush(tester);
    expect(first.closed, isTrue);
    expect(c.peer, isNull);
    expect(c.phase, LiveViewerPhase.connecting);
    expect(signalTypes(transport), ['ready', 'answer', 'ready']);

    signal({'type': 'offer', 'sdp': 'o2'});
    await flush(tester);
    expect(peers.peers, hasLength(2));
    expect(peers.last.remoteOffer, 'o2');
    expect(signals(transport).last['sdp'], 'answer-for-o2');
    c.dispose();
  });

  testWidgets('ended: closes the peer and the socket, no more ready', (tester) async {
    final c = make(liveRtcRoutes(join: joinData(hostOnline: false)));
    await c.start();
    await flush(tester);
    signal({'type': 'offer', 'sdp': 'o'});
    await flush(tester);
    final peer = peers.last;
    signal({'type': 'ended'});
    await flush(tester);
    expect(c.phase, LiveViewerPhase.ended);
    expect(peer.closed, isTrue);
    expect(sockets.last.closed, isTrue);
    final before = signalTypes(transport).length;
    await flush(tester, const Duration(seconds: 20));
    expect(signalTypes(transport), hasLength(before));
    c.dispose();
    // No leave once the show has ended.
    await flush(tester);
    expect(signalTypes(transport), hasLength(before));
  });

  testWidgets('heartbeat: presence carries the peer token; the refreshed token is used from then on', (tester) async {
    final c = make(liveRtcRoutes());
    await c.start();
    await flush(tester);
    await flush(tester, const Duration(seconds: 30));
    final beat = transport.bodyOf(transport.sent('POST live-streams/demo/presence').single);
    expect(beat, {'viewer_id': 'app-viewer', 'peer_token': 'tok1'});
    expect(c.peerToken, 'tok2');
    expect(viewerCounts, [4, 5]);
    signal({'type': 'offer', 'sdp': 'o'});
    await flush(tester);
    expect(signals(transport).last['peer_token'], 'tok2');
    await flush(tester, const Duration(seconds: 30));
    expect(transport.bodyOf(transport.sent('POST live-streams/demo/presence').last)['peer_token'], 'tok2');
    c.dispose();
  });

  testWidgets('stream_full: no socket, retry joins again', (tester) async {
    final routes = liveRtcRoutes(join: FakeTransport.error(409, 'stream_full', 'This live stream is full.'));
    final c = make(routes);
    await c.start();
    await flush(tester);
    expect(c.phase, LiveViewerPhase.streamFull);
    expect(sockets.sockets, isEmpty);
    routes['POST live-streams/demo/join'] = joinData();
    transport.routes.addAll(routes);
    await c.retry();
    await flush(tester);
    expect(transport.sent('POST live-streams/demo/join'), hasLength(2));
    expect(c.phase, LiveViewerPhase.connecting);
    c.dispose();
  });

  testWidgets('stream_not_live: host not live', (tester) async {
    final c = make(liveRtcRoutes(join: FakeTransport.error(409, 'stream_not_live', 'Not live.')));
    await c.start();
    await flush(tester);
    expect(c.phase, LiveViewerPhase.hostNotLive);
    c.dispose();
  });

  testWidgets('peer_token_invalid on a signal: failed; retry rejoins the same slot with peer id + token', (tester) async {
    final c = make(liveRtcRoutes(signal: FakeTransport.error(403, 'peer_token_invalid', 'Expired.')));
    await c.start();
    await flush(tester);
    expect(c.phase, LiveViewerPhase.failed);
    expect(c.failure, LiveViewerFailure.token);
    expect(sockets.last.closed, isTrue);
    transport.routes['POST live-streams/demo/signal'] = FakeTransport.ok({'ok': true}, status: 202);
    await c.retry();
    await flush(tester);
    expect(transport.bodyOf(transport.sent('POST live-streams/demo/join').last), {'peer_id': 'p1', 'peer_token': 'tok1'});
    expect(sockets.sockets, hasLength(2));
    expect(c.phase, LiveViewerPhase.connecting);
    c.dispose();
  });

  testWidgets('a refused resume falls back to a fresh join', (tester) async {
    final c = make(liveRtcRoutes());
    c.peerId = 'old';
    c.peerToken = 'stale';
    var joins = 0;
    transport.routes['POST live-streams/demo/join'] = (HttpRequestData r) =>
        ++joins == 1 ? FakeTransport.error(403, 'peer_token_invalid', 'Expired.') : FakeTransport.ok(joinData(token: 'fresh'));
    await c.start();
    await flush(tester);
    final bodies = transport.sent('POST live-streams/demo/join').map(transport.bodyOf).toList();
    expect(bodies, [
      {'peer_id': 'old', 'peer_token': 'stale'},
      <String, dynamic>{},
    ]);
    expect(c.peerToken, 'fresh');
    c.dispose();
  });

  testWidgets('ICE: disconnected for more than 10 s fails; a quick recovery does not', (tester) async {
    final c = make(liveRtcRoutes());
    await c.start();
    await flush(tester);
    signal({'type': 'offer', 'sdp': 'o'});
    await flush(tester);
    final peer = peers.last..emitTrack();
    peer.emitState(LivePeerState.disconnected);
    await flush(tester, const Duration(seconds: 5));
    peer.emitState(LivePeerState.connected);
    await flush(tester, const Duration(seconds: 10));
    expect(c.phase, LiveViewerPhase.playing);
    peer.emitState(LivePeerState.disconnected);
    await flush(tester, const Duration(seconds: 11));
    expect(c.phase, LiveViewerPhase.failed);
    expect(c.failure, LiveViewerFailure.connection);
    expect(peer.closed, isTrue);
    c.dispose();
  });

  testWidgets('ICE failed fails at once; the socket dropping fails too', (tester) async {
    final c = make(liveRtcRoutes());
    await c.start();
    await flush(tester);
    signal({'type': 'offer', 'sdp': 'o'});
    await flush(tester);
    peers.last.emitState(LivePeerState.failed);
    await flush(tester);
    expect(c.phase, LiveViewerPhase.failed);

    await c.retry();
    await flush(tester);
    expect(c.phase, LiveViewerPhase.connecting);
    sockets.last.drop();
    await flush(tester);
    expect(c.phase, LiveViewerPhase.failed);
    expect(c.failure, LiveViewerFailure.socket);
    c.dispose();
  });

  testWidgets('mute applies to the current and the next peer; dispose sends leave', (tester) async {
    final c = make(liveRtcRoutes());
    await c.start();
    await flush(tester);
    signal({'type': 'offer', 'sdp': 'o'});
    await flush(tester);
    c.toggleMute();
    expect(c.muted, isTrue);
    expect(peers.last.muted, isTrue);
    signal({'type': 'online'});
    signal({'type': 'offer', 'sdp': 'o2'});
    await flush(tester);
    expect(peers.last.muted, isTrue);
    c.dispose();
    await flush(tester);
    expect(signals(transport).last, {'peer_token': 'tok1', 'type': 'leave'});
    expect(sockets.last.closed, isTrue);
  });
}
