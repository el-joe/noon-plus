import 'dart:async';
import 'dart:convert';

import 'package:customer_app/core/api/api_client.dart';
import 'package:customer_app/screens/live/live_webrtc_controller.dart';
import 'package:flutter/widgets.dart';
import 'package:nawy_core/realtime.dart';
import 'package:nawy_core/testing.dart' show FakePusherSocket;

import 'helpers.dart';

/// A fake receive-only peer: records the offer and candidates; tests drive
/// its ICE state, local candidates and remote tracks.
class FakeLivePeer implements LivePeer {
  FakeLivePeer(this.iceServers, {this.candidatesOnAnswer = const []});

  final List<Map<String, dynamic>> iceServers;

  /// Local candidates "gathered" while the answer is created (before it is
  /// posted), so the controller must buffer them.
  final List<IceCandidateData> candidatesOnAnswer;

  final _candidates = StreamController<IceCandidateData>.broadcast(sync: true);
  final _states = StreamController<LivePeerState>.broadcast(sync: true);
  final _tracks = StreamController<void>.broadcast(sync: true);

  String? remoteOffer;
  final List<IceCandidateData> added = [];
  bool closed = false;
  bool muted = false;

  @override
  Stream<IceCandidateData> get localCandidates => _candidates.stream;

  @override
  Stream<LivePeerState> get states => _states.stream;

  @override
  Stream<void> get tracks => _tracks.stream;

  @override
  Future<void> setRemoteOffer(String sdp) async => remoteOffer = sdp;

  @override
  Future<String> createAnswer() async {
    candidatesOnAnswer.forEach(emitCandidate);
    return 'answer-for-$remoteOffer';
  }

  @override
  Future<void> addRemoteCandidate(IceCandidateData candidate) async => added.add(candidate);

  @override
  void setMuted(bool value) => muted = value;

  @override
  Widget buildView() => const SizedBox.expand(key: ValueKey('fake-rtc-video'));

  void emitCandidate(IceCandidateData c) => _candidates.add(c);
  void emitState(LivePeerState s) => _states.add(s);
  void emitTrack() => _tracks.add(null);

  @override
  Future<void> close() async => closed = true;
}

/// Hands out [FakeLivePeer]s and remembers them.
class FakePeers {
  FakePeers({this.candidatesOnAnswer = const []});

  final List<IceCandidateData> candidatesOnAnswer;
  final List<FakeLivePeer> peers = [];

  FakeLivePeer get last => peers.last;

  Future<LivePeer> create(List<Map<String, dynamic>> iceServers) async {
    final peer = FakeLivePeer(iceServers, candidatesOnAnswer: candidatesOnAnswer);
    peers.add(peer);
    return peer;
  }
}

/// Hands out [FakePusherSocket]s and remembers the configs.
class FakeSockets {
  final List<FakePusherSocket> sockets = [];
  final List<PusherConfig> configs = [];

  FakePusherSocket get last => sockets.last;

  PusherSocket create(PusherConfig config) {
    configs.add(config);
    final socket = FakePusherSocket(config: config);
    sockets.add(socket);
    return socket;
  }
}

const liveChannel = 'private-live.demo.peer.p1';

Map<String, dynamic> joinData({bool hostOnline = true, String token = 'tok1', int heartbeat = 30}) => {
      'peer_id': 'p1',
      'peer_token': token,
      'expires_at': '2026-11-19T10:10:00Z',
      'channel': liveChannel,
      'ice_servers': [
        {'urls': ['stun:stun.test:3478']},
        {'urls': ['turn:turn.test:3478?transport=udp'], 'username': 'u', 'credential': 'c'},
      ],
      'heartbeat_seconds': heartbeat,
      'max_viewers': 20,
      'viewers': 4,
      'host_online': hostOnline,
      'socket': {'key': 'reverb-key', 'host': 'ws.test', 'port': 8080, 'scheme': 'http'},
    };

/// The live WebRTC routes for slug `demo`.
Map<String, Object> liveRtcRoutes({Object? join, Object? signal, Object? presence}) => {
      'POST live-streams/demo/join': join ?? joinData(),
      'POST broadcasting/auth': HttpResponseData(200, jsonEncode({'auth': 'reverb-key:sig'})),
      'POST live-streams/demo/signal': signal ?? FakeTransport.ok({'ok': true}, status: 202),
      'POST live-streams/demo/presence': presence ?? {'viewers': 5, 'peer_token': 'tok2', 'expires_at': '2026-11-19T10:20:00Z'},
    };

/// Signal bodies posted so far (`type` plus the rest).
List<Map<String, dynamic>> signals(FakeTransport transport) => transport.sent('POST live-streams/demo/signal').map(transport.bodyOf).toList();

List<String> signalTypes(FakeTransport transport) => signals(transport).map((b) => b['type'] as String).toList();
