import 'dart:async';

import 'package:flutter/widgets.dart';
import 'package:nawy_core/realtime.dart';

import '../../core/api/api_client.dart';
import '../../core/json.dart';

/// Native WebRTC live viewer (owner-review D18): the admin host's browser
/// sends the video peer-to-peer; signalling runs over Laravel Reverb (Pusher
/// protocol) on the viewer's private channel `private-live.{slug}.peer.{peer}`
/// plus `POST live-streams/{slug}/signal`.
///
/// [LiveWebRtcController] is the state machine: join → socket → subscribe →
/// `ready` → answer the host's offer → playing. It is pure Dart over two
/// seams so it is unit tested without WebRTC: the [PusherSocket] (fake:
/// `FakePusherSocket`) and the [LivePeer] (real: `FlutterWebRtcPeer`).

enum LiveViewerPhase {
  /// Joining, connecting the socket, or negotiating with the host.
  connecting,

  /// Joined, but the host's studio is not sending yet (`host_online` false or
  /// no offer after [LiveWebRtcController.readyInterval]); `ready` is resent.
  waitingForHost,

  /// The host's media arrived.
  playing,

  /// 409 `stream_full`: the WebRTC viewer cap is reached.
  streamFull,

  /// 409 `stream_not_live`.
  hostNotLive,

  /// Signal `ended`: the show is over.
  ended,

  /// Socket / ICE / token failure; retry rejoins the same slot.
  failed,

  /// No realtime socket in this build (web): watch in the browser.
  unsupported,
}

enum LiveViewerFailure { network, socket, token, connection }

/// `{candidate, sdpMid, sdpMLineIndex}` (RTCIceCandidateInit).
class IceCandidateData {
  const IceCandidateData(this.candidate, {this.sdpMid, this.sdpMLineIndex});

  factory IceCandidateData.fromJson(Json json) => IceCandidateData(json.str('candidate'), sdpMid: json.s('sdpMid'), sdpMLineIndex: json.i('sdpMLineIndex'));

  final String candidate;
  final String? sdpMid;
  final int? sdpMLineIndex;

  Json toJson() => {'candidate': candidate, 'sdpMid': sdpMid, 'sdpMLineIndex': sdpMLineIndex};

  @override
  bool operator ==(Object other) => other is IceCandidateData && other.candidate == candidate && other.sdpMid == sdpMid && other.sdpMLineIndex == sdpMLineIndex;

  @override
  int get hashCode => Object.hash(candidate, sdpMid, sdpMLineIndex);
}

enum LivePeerState { connecting, connected, disconnected, failed, closed }

/// One receive-only peer connection to the host.
abstract class LivePeer {
  /// Our ICE candidates to trickle to the host.
  Stream<IceCandidateData> get localCandidates;

  /// ICE connection state.
  Stream<LivePeerState> get states;

  /// Fires when remote media (a track) arrives.
  Stream<void> get tracks;

  Future<void> setRemoteOffer(String sdp);

  /// Creates the answer, sets it as the local description, returns its SDP.
  Future<String> createAnswer();

  Future<void> addRemoteCandidate(IceCandidateData candidate);

  void setMuted(bool muted);

  /// The video (RTCVideoView in the real peer).
  Widget buildView();

  Future<void> close();
}

typedef LivePeerFactory = Future<LivePeer> Function(List<Json> iceServers);
typedef LiveSocketFactory = PusherSocket Function(PusherConfig config);

class LiveWebRtcController extends ChangeNotifier {
  LiveWebRtcController({
    required this.api,
    required this.slug,
    required this.viewerId,
    required this.socketFactory,
    required this.peerFactory,
    this.onViewers,
    this.readyInterval = const Duration(seconds: 8),
    this.iceGrace = const Duration(seconds: 10),
  });

  final ApiClient api;
  final String slug;
  final String viewerId;
  final LiveSocketFactory socketFactory;
  final LivePeerFactory peerFactory;
  final void Function(int viewers)? onViewers;
  final Duration readyInterval;
  final Duration iceGrace;

  LiveViewerPhase _phase = LiveViewerPhase.connecting;
  LiveViewerPhase get phase => _phase;

  LiveViewerFailure? _failure;
  LiveViewerFailure? get failure => _failure;

  bool _muted = false;
  bool get muted => _muted;

  /// The current peer (its view shows while [phase] is playing).
  LivePeer? get peer => _peer;

  String? peerId;
  String? peerToken;
  String? channel;
  int? maxViewers;
  int? viewers;
  bool hostOnline = false;
  List<Json> iceServers = const [];
  Duration heartbeat = const Duration(seconds: 30);

  PusherSocket? _socket;
  LivePeer? _peer;
  StreamSubscription<PusherMessage>? _messagesSub;
  StreamSubscription<PusherStatus>? _statusSub;
  final List<StreamSubscription<Object?>> _peerSubs = [];
  Timer? _readyTimer;
  Timer? _heartbeatTimer;
  Timer? _iceTimer;
  int _generation = 0;
  bool _disposed = false;
  bool _gotOffer = false;
  bool _remoteSet = false;
  bool _answerSent = false;
  final List<IceCandidateData> _remoteBuffer = [];
  final List<IceCandidateData> _localBuffer = [];

  bool get _active => _phase == LiveViewerPhase.connecting || _phase == LiveViewerPhase.waitingForHost || _phase == LiveViewerPhase.playing;

  String get _base => 'live-streams/${enc(slug)}';

  void _set(LiveViewerPhase phase, [LiveViewerFailure? failure]) {
    if (_disposed) return;
    _phase = phase;
    _failure = failure;
    notifyListeners();
  }

  /// Joins (or rejoins the same slot with [peerId] / [peerToken]) and
  /// connects. Also the retry action.
  Future<void> start() async {
    final gen = ++_generation;
    await _teardown();
    if (!_current(gen)) return;
    _set(LiveViewerPhase.connecting);

    Json joined;
    try {
      joined = await _join();
    } on ApiException catch (e) {
      if (!_current(gen)) return;
      if (e.code == 'stream_full') {
        maxViewers = e.details.i('max_viewers') ?? maxViewers;
        return _set(LiveViewerPhase.streamFull);
      }
      if (e.code == 'stream_not_live') return _set(LiveViewerPhase.hostNotLive);
      return _set(LiveViewerPhase.failed, LiveViewerFailure.network);
    }
    if (!_current(gen)) return;

    peerId = joined.s('peer_id');
    peerToken = joined.s('peer_token');
    channel = joined.s('channel') ?? (peerId == null ? null : 'private-live.$slug.peer.$peerId');
    iceServers = joined.l('ice_servers');
    heartbeat = Duration(seconds: (joined.i('heartbeat_seconds') ?? 30).clamp(5, 300));
    maxViewers = joined.i('max_viewers') ?? maxViewers;
    hostOnline = joined.b('host_online');
    _reportViewers(joined.i('viewers'));
    final config = PusherConfig.fromJson(joined.m('socket') ?? const {});
    if (peerToken == null || channel == null || !config.isValid) return _set(LiveViewerPhase.failed, LiveViewerFailure.socket);

    final socket = _socket = socketFactory(config);
    _messagesSub = socket.messages.listen((m) => _onMessage(gen, m));
    _statusSub = socket.status.listen((s) {
      if (_current(gen) && s.state == PusherState.closed && _active) _fail(LiveViewerFailure.socket);
    });
    try {
      await socket.connect();
      if (!_current(gen)) return;
      await socket.subscribe(channel!, authorize: _authorize);
    } catch (e) {
      if (!_current(gen)) return;
      return _fail(e is ApiException && e.code == 'peer_token_invalid' ? LiveViewerFailure.token : LiveViewerFailure.socket);
    }
    if (!_current(gen)) return;

    _heartbeatTimer = Timer.periodic(heartbeat, (_) => _beat(gen));
    _set(hostOnline ? LiveViewerPhase.connecting : LiveViewerPhase.waitingForHost);
    _sendReady(gen);
  }

  Future<void> retry() => start();

  /// `POST join`; a stale slot (403 / 422 on the resume body) gets a fresh one.
  Future<Json> _join() async {
    final resume = peerId != null && peerToken != null;
    try {
      final res = await api.post('$_base/join', body: {if (resume) 'peer_id': peerId, if (resume) 'peer_token': peerToken});
      return asJson(res.data);
    } on ApiException catch (e) {
      if (!resume || !(e.status == 403 || e.status == 422)) rethrow;
      peerId = null;
      peerToken = null;
      return asJson((await api.post('$_base/join', body: const <String, dynamic>{})).data);
    }
  }

  /// `POST broadcasting/auth` with the peer token → the channel `auth`.
  Future<String> _authorize(String socketId, String channelName) async {
    final res = await api.postRaw('broadcasting/auth', body: {'socket_id': socketId, 'channel_name': channelName, 'peer_token': peerToken});
    final body = asJson(res.data);
    final auth = body.s('auth') ?? body.m('data')?.s('auth');
    if (auth == null) throw ApiException(res.status, 'invalid_response', 'No channel auth');
    return auth;
  }

  bool _current(int gen) => !_disposed && gen == _generation;

  void _sendReady(int gen) {
    _readyTimer?.cancel();
    _gotOffer = false;
    _signal(gen, 'ready');
    _readyTimer = Timer.periodic(readyInterval, (_) {
      if (!_current(gen) || _gotOffer || !_active) return;
      if (_phase == LiveViewerPhase.connecting) _set(LiveViewerPhase.waitingForHost);
      _signal(gen, 'ready');
    });
  }

  /// `POST signal`; a refused token fails the viewer (retry rejoins).
  Future<bool> _signal(int gen, String type, [Json extra = const {}]) async {
    final token = peerToken;
    if (token == null) return false;
    try {
      await api.post('$_base/signal', body: {'peer_token': token, 'type': type, ...extra});
      return true;
    } on ApiException catch (e) {
      if (!_current(gen) || type == 'leave') return false;
      if (e.code == 'peer_token_invalid' || e.status == 403) {
        _fail(LiveViewerFailure.token);
      } else if (e.code == 'stream_not_live') {
        await _teardown();
        _set(LiveViewerPhase.hostNotLive);
      } else if (type == 'answer') {
        _fail(LiveViewerFailure.network);
      }
      return false;
    }
  }

  Future<void> _beat(int gen) async {
    final token = peerToken;
    try {
      final res = await api.post('$_base/presence', body: {'viewer_id': viewerId, 'peer_token': ?token});
      if (!_current(gen)) return;
      final data = asJson(res.data);
      final fresh = data.s('peer_token');
      if (fresh != null && fresh.isNotEmpty) peerToken = fresh;
      _reportViewers(data.i('viewers'));
    } catch (_) {
      // Best effort: the next beat retries.
    }
  }

  void _reportViewers(int? n) {
    if (n == null) return;
    viewers = n;
    onViewers?.call(n);
  }

  void _onMessage(int gen, PusherMessage message) {
    if (!_current(gen) || message.channel != channel) return;
    if (message.event != 'signal' && !message.event.endsWith('.signal')) return;
    final data = message.json;
    switch (data.s('type')) {
      case 'offer':
        final sdp = data.s('sdp');
        if (sdp != null && _phase != LiveViewerPhase.ended) _onOffer(gen, sdp);
      case 'candidate':
        final c = data.m('candidate');
        if (c != null && c.s('candidate') != null) _onRemoteCandidate(IceCandidateData.fromJson(c));
      case 'online':
        _restartNegotiation(gen);
      case 'ended':
        _end(gen);
    }
  }

  Future<void> _onOffer(int gen, String sdp) async {
    _gotOffer = true;
    if (_phase == LiveViewerPhase.waitingForHost) _set(LiveViewerPhase.connecting);
    await _closePeer();
    _remoteSet = false;
    _answerSent = false;
    _localBuffer.clear();
    LivePeer peer;
    try {
      peer = await peerFactory(iceServers);
    } catch (_) {
      if (_current(gen)) _fail(LiveViewerFailure.connection);
      return;
    }
    if (!_current(gen)) {
      await peer.close();
      return;
    }
    _peer = peer;
    peer.setMuted(_muted);
    _peerSubs
      ..add(peer.localCandidates.listen((c) {
        if (!_current(gen) || _peer != peer) return;
        if (_answerSent) {
          _signal(gen, 'candidate', {'candidate': c.toJson()});
        } else {
          _localBuffer.add(c);
        }
      }))
      ..add(peer.states.listen((s) => _onPeerState(gen, peer, s)))
      ..add(peer.tracks.listen((_) {
        if (_current(gen) && _peer == peer && _active) _set(LiveViewerPhase.playing);
      }));
    try {
      await peer.setRemoteOffer(sdp);
      if (!_current(gen) || _peer != peer) return;
      _remoteSet = true;
      final buffered = List.of(_remoteBuffer);
      _remoteBuffer.clear();
      for (final c in buffered) {
        await peer.addRemoteCandidate(c);
      }
      final answer = await peer.createAnswer();
      if (!_current(gen) || _peer != peer) return;
      final sent = await _signal(gen, 'answer', {'sdp': answer});
      if (!sent || !_current(gen) || _peer != peer) return;
      _answerSent = true;
      final local = List.of(_localBuffer);
      _localBuffer.clear();
      for (final c in local) {
        await _signal(gen, 'candidate', {'candidate': c.toJson()});
      }
    } catch (_) {
      if (_current(gen) && _peer == peer) _fail(LiveViewerFailure.connection);
    }
  }

  void _onRemoteCandidate(IceCandidateData c) {
    final peer = _peer;
    if (peer == null || !_remoteSet) {
      _remoteBuffer.add(c);
      return;
    }
    peer.addRemoteCandidate(c).catchError((Object _) {});
  }

  void _onPeerState(int gen, LivePeer peer, LivePeerState s) {
    if (!_current(gen) || _peer != peer) return;
    switch (s) {
      case LivePeerState.connected:
        _iceTimer?.cancel();
        _iceTimer = null;
      case LivePeerState.disconnected:
        _iceTimer ??= Timer(iceGrace, () {
          _iceTimer = null;
          if (_current(gen) && _peer == peer && _active) _fail(LiveViewerFailure.connection);
        });
      case LivePeerState.failed:
        if (_active) _fail(LiveViewerFailure.connection);
      case LivePeerState.connecting:
      case LivePeerState.closed:
        break;
    }
  }

  /// Signal `online`: the host studio (re)started — new peer, `ready` again.
  void _restartNegotiation(int gen) {
    if (!_active) return;
    // Detaches the old peer at once (its close finishes in the background).
    _closePeer();
    _remoteBuffer.clear();
    _localBuffer.clear();
    _set(LiveViewerPhase.connecting);
    _sendReady(gen);
  }

  void _end(int gen) {
    _set(LiveViewerPhase.ended);
    _teardown();
  }

  void _fail(LiveViewerFailure failure) {
    if (_disposed) return;
    _teardown();
    _set(LiveViewerPhase.failed, failure);
  }

  void toggleMute() {
    _muted = !_muted;
    _peer?.setMuted(_muted);
    notifyListeners();
  }

  Future<void> _closePeer() async {
    _iceTimer?.cancel();
    _iceTimer = null;
    for (final s in _peerSubs) {
      s.cancel();
    }
    _peerSubs.clear();
    final peer = _peer;
    _peer = null;
    _remoteSet = false;
    _answerSent = false;
    if (peer != null) {
      try {
        await peer.close();
      } catch (_) {}
    }
  }

  Future<void> _teardown() async {
    _readyTimer?.cancel();
    _readyTimer = null;
    _heartbeatTimer?.cancel();
    _heartbeatTimer = null;
    final socket = _socket;
    _socket = null;
    // Not awaited: cancelling from inside the socket's own event (signal
    // `ended`) only completes after that event is delivered.
    _messagesSub?.cancel();
    _statusSub?.cancel();
    _messagesSub = null;
    _statusSub = null;
    final peer = _closePeer();
    socket?.close().catchError((Object _) {});
    await peer;
  }

  /// Leaves the slot (best effort `signal leave`) and closes everything.
  @override
  void dispose() {
    final token = peerToken;
    final holdsSlot = _active || _phase == LiveViewerPhase.failed;
    _disposed = true;
    _generation++;
    if (token != null && holdsSlot) {
      api.post('$_base/signal', body: {'peer_token': token, 'type': 'leave'}).then((_) {}, onError: (Object _) {});
    }
    _teardown();
    super.dispose();
  }
}
