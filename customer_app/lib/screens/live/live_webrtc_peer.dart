import 'dart:async';

import 'package:flutter/widgets.dart';
import 'package:flutter_webrtc/flutter_webrtc.dart';

import '../../core/json.dart';
import 'live_webrtc_controller.dart';

/// The real [LivePeer] on `flutter_webrtc` (owner-review D18): answers the
/// host's offer, so the connection is receive-only (no camera / microphone,
/// no permissions). Audio plays through the device output automatically;
/// mute disables the remote audio tracks.
class FlutterWebRtcPeer implements LivePeer {
  FlutterWebRtcPeer._(this._pc, this._renderer) {
    _pc.onIceCandidate = (c) {
      final candidate = c.candidate;
      if (candidate == null || candidate.isEmpty || _candidates.isClosed) return;
      _candidates.add(IceCandidateData(candidate, sdpMid: c.sdpMid, sdpMLineIndex: c.sdpMLineIndex));
    };
    _pc.onIceConnectionState = (s) {
      final mapped = switch (s) {
        RTCIceConnectionState.RTCIceConnectionStateConnected || RTCIceConnectionState.RTCIceConnectionStateCompleted => LivePeerState.connected,
        RTCIceConnectionState.RTCIceConnectionStateDisconnected => LivePeerState.disconnected,
        RTCIceConnectionState.RTCIceConnectionStateFailed => LivePeerState.failed,
        RTCIceConnectionState.RTCIceConnectionStateClosed => LivePeerState.closed,
        _ => LivePeerState.connecting,
      };
      if (!_states.isClosed) _states.add(mapped);
    };
    _pc.onTrack = (event) {
      if (event.streams.isEmpty) return;
      final stream = event.streams.first;
      _stream = stream;
      _renderer.srcObject = stream;
      _applyMute();
      if (!_tracks.isClosed) _tracks.add(null);
    };
  }

  /// [iceServers] as the join answer sends them (`{urls, username?, credential?}`).
  static Future<LivePeer> create(List<Json> iceServers) async {
    final renderer = RTCVideoRenderer();
    await renderer.initialize();
    final pc = await createPeerConnection({'iceServers': iceServers, 'sdpSemantics': 'unified-plan'});
    return FlutterWebRtcPeer._(pc, renderer);
  }

  final RTCPeerConnection _pc;
  final RTCVideoRenderer _renderer;
  final _candidates = StreamController<IceCandidateData>.broadcast();
  final _states = StreamController<LivePeerState>.broadcast();
  final _tracks = StreamController<void>.broadcast();
  MediaStream? _stream;
  bool _muted = false;

  @override
  Stream<IceCandidateData> get localCandidates => _candidates.stream;

  @override
  Stream<LivePeerState> get states => _states.stream;

  @override
  Stream<void> get tracks => _tracks.stream;

  @override
  Future<void> setRemoteOffer(String sdp) => _pc.setRemoteDescription(RTCSessionDescription(sdp, 'offer'));

  @override
  Future<String> createAnswer() async {
    final answer = await _pc.createAnswer({});
    await _pc.setLocalDescription(answer);
    return answer.sdp ?? '';
  }

  @override
  Future<void> addRemoteCandidate(IceCandidateData c) => _pc.addCandidate(RTCIceCandidate(c.candidate, c.sdpMid, c.sdpMLineIndex));

  @override
  void setMuted(bool muted) {
    _muted = muted;
    _applyMute();
  }

  void _applyMute() {
    for (final track in _stream?.getAudioTracks() ?? const <MediaStreamTrack>[]) {
      track.enabled = !_muted;
    }
  }

  @override
  Widget buildView() => RTCVideoView(_renderer, key: const ValueKey('live-rtc-video'));

  @override
  Future<void> close() async {
    _pc.onIceCandidate = null;
    _pc.onIceConnectionState = null;
    _pc.onTrack = null;
    await _candidates.close();
    await _states.close();
    await _tracks.close();
    try {
      _renderer.srcObject = null;
      await _renderer.dispose();
    } catch (_) {}
    await _pc.close();
  }
}
