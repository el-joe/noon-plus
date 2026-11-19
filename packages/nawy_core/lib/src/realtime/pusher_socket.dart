import 'dart:async';
import 'dart:convert';

import '../json.dart';
import 'pusher_wire.dart';

/// Minimal Pusher-protocol (v7) client for Laravel Reverb, on a plain
/// WebSocket (owner-review D18: no pusher / web_socket_channel package).
///
/// Covers what the apps need: connect, `pusher:connection_established`
/// (socket id), private channel subscribe with an auth callback
/// (`POST broadcasting/auth`), `pusher:ping` → `pusher:pong`, an idle ping of
/// our own, `pusher:error`, and a [PusherSocket.status] stream that tells the
/// caller when the socket closed (the caller decides whether to reconnect;
/// [PusherSocket.connect] again re-subscribes the channels it knew).

/// Public Reverb connection (`socket` in the live join answer).
class PusherConfig {
  const PusherConfig({required this.key, required this.host, this.port, this.scheme = 'https'});

  /// `{key, host, port, scheme}`; scheme `http` → `ws`, otherwise `wss`.
  factory PusherConfig.fromJson(Json json) => PusherConfig(
        key: json.str('key'),
        host: json.str('host'),
        port: json.i('port'),
        scheme: json.str('scheme', 'https'),
      );

  final String key;
  final String host;
  final int? port;
  final String scheme;

  bool get isValid => key.isNotEmpty && host.isNotEmpty;

  Uri get uri => Uri(
        scheme: scheme == 'http' || scheme == 'ws' ? 'ws' : 'wss',
        host: host,
        port: port,
        path: '/app/$key',
        queryParameters: const {'protocol': '7', 'client': 'nawy-dart', 'version': '8.4.0', 'flash': 'false'},
      );
}

/// One channel event: [data] is the decoded JSON payload (Pusher sends it
/// as a JSON string; a non-JSON string stays a string).
class PusherMessage {
  const PusherMessage({required this.channel, required this.event, this.data});

  final String channel;
  final String event;
  final Object? data;

  Json get json => asJson(data);

  @override
  String toString() => 'PusherMessage($channel, $event, $data)';
}

enum PusherState { idle, connecting, connected, closed }

/// A state change; [error] is set for `pusher:error`, a failed connect or an
/// unexpected close. [code] is the Pusher / WebSocket close code when known.
class PusherStatus {
  const PusherStatus(this.state, {this.error, this.code});

  final PusherState state;
  final String? error;
  final int? code;

  /// Pusher 4000–4099: do not reconnect with the same settings.
  bool get fatal => code != null && code! >= 4000 && code! < 4100;

  @override
  String toString() => 'PusherStatus(${state.name}, $code, $error)';
}

class PusherException implements Exception {
  PusherException(this.message, {this.code});

  final String message;
  final int? code;

  @override
  String toString() => 'PusherException($code, $message)';
}

/// Returns the `auth` string (`key:signature`) for a private channel.
typedef PusherAuthorizer = Future<String> Function(String socketId, String channel);

/// The socket the apps use (fake in tests: `FakePusherSocket`).
abstract class PusherSocket {
  /// Channel events (internal `pusher:` / `pusher_internal:` events are
  /// handled here and not forwarded).
  Stream<PusherMessage> get messages;

  Stream<PusherStatus> get status;

  PusherState get state;

  String? get socketId;

  /// Opens the socket; completes with the socket id once
  /// `pusher:connection_established` arrives. Re-subscribes the channels
  /// subscribed before a close.
  Future<String> connect();

  /// Subscribes [channel] (private channels need [authorize]); completes on
  /// `pusher_internal:subscription_succeeded`, throws [PusherException] on a
  /// failed auth / `pusher:subscription_error` / timeout.
  Future<void> subscribe(String channel, {PusherAuthorizer? authorize});

  void unsubscribe(String channel);

  Future<void> close();
}

/// The raw text WebSocket under [PusherClient] (dart:io in the apps, a fake
/// in tests).
abstract class PusherWire {
  Stream<String> get incoming;
  void send(String text);
  int? get closeCode;
  Future<void> close([int code = 1000]);
}

typedef PusherWireConnector = Future<PusherWire> Function(Uri uri);

class PusherClient implements PusherSocket {
  PusherClient(this.config, {PusherWireConnector? connector, this.timeout = const Duration(seconds: 15)})
      : _connector = connector ?? ((uri) => connectPusherWire(uri));

  /// False on the web build (no socket there; callers fall back).
  static bool get supported => pusherWireSupported;

  final PusherConfig config;
  final PusherWireConnector _connector;
  final Duration timeout;

  final _messages = StreamController<PusherMessage>.broadcast();
  final _status = StreamController<PusherStatus>.broadcast();
  final Map<String, PusherAuthorizer?> _channels = {};
  final Map<String, Completer<void>> _pending = {};

  PusherWire? _wire;
  StreamSubscription<String>? _sub;
  Completer<String>? _connecting;
  Timer? _idle;
  Timer? _pongWait;
  Duration _activity = const Duration(seconds: 30);
  bool _closing = false;

  PusherState _state = PusherState.idle;
  String? _socketId;

  @override
  Stream<PusherMessage> get messages => _messages.stream;

  @override
  Stream<PusherStatus> get status => _status.stream;

  @override
  PusherState get state => _state;

  @override
  String? get socketId => _socketId;

  void _set(PusherState state, {String? error, int? code}) {
    _state = state;
    if (!_status.isClosed) _status.add(PusherStatus(state, error: error, code: code));
  }

  @override
  Future<String> connect() {
    if (_state == PusherState.connected && _socketId != null) return Future.value(_socketId);
    final running = _connecting;
    if (running != null) return running.future;
    final completer = _connecting = Completer<String>();
    _closing = false;
    _set(PusherState.connecting);
    () async {
      try {
        final wire = await _connector(config.uri).timeout(timeout);
        _wire = wire;
        _sub = wire.incoming.listen(_onText, onDone: () => _onClosed(wire.closeCode), onError: (Object e) => _onClosed(wire.closeCode, '$e'));
        await completer.future.timeout(timeout);
      } catch (e) {
        if (!completer.isCompleted) {
          _connecting = null;
          await _teardown();
          _set(PusherState.closed, error: '$e');
          completer.completeError(e is PusherException ? e : PusherException('$e'));
        }
      }
    }();
    return completer.future;
  }

  void _send(String event, Object? data) => _wire?.send(jsonEncode({'event': event, 'data': data ?? const {}}));

  void _touch() {
    _idle?.cancel();
    _pongWait?.cancel();
    if (_state != PusherState.connected) return;
    _idle = Timer(_activity, () {
      _send('pusher:ping', const {});
      _pongWait = Timer(const Duration(seconds: 30), () => _onClosed(4201, 'Pong reply not received'));
    });
  }

  static Object? decodeData(Object? raw) {
    if (raw is! String) return raw;
    try {
      return jsonDecode(raw);
    } on FormatException {
      return raw;
    }
  }

  void _onText(String text) {
    Json frame;
    try {
      frame = asJson(jsonDecode(text));
    } on FormatException {
      return;
    }
    final event = frame.str('event');
    final channel = frame.s('channel');
    final data = decodeData(frame['data']);
    switch (event) {
      case 'pusher:connection_established':
        final json = asJson(data);
        _socketId = json.s('socket_id');
        final activity = json.i('activity_timeout');
        if (activity != null && activity > 0) _activity = Duration(seconds: activity);
        _state = PusherState.connected;
        _touch();
        final c = _connecting;
        _connecting = null;
        if (_socketId == null) {
          c?.completeError(PusherException('No socket id'));
          return;
        }
        _set(PusherState.connected);
        c?.complete(_socketId);
        // Re-subscribe after a reconnect.
        for (final entry in _channels.entries.toList()) {
          if (!_pending.containsKey(entry.key)) _doSubscribe(entry.key, entry.value).ignore();
        }
        return;
      case 'pusher:ping':
        _send('pusher:pong', const {});
        _touch();
        return;
      case 'pusher:pong':
        _touch();
        return;
      case 'pusher:error':
        final json = asJson(data);
        final code = json.i('code');
        final message = json.str('message', 'Socket error');
        _set(_state, error: message, code: code);
        final c = _connecting;
        if (c != null && !c.isCompleted) {
          _connecting = null;
          c.completeError(PusherException(message, code: code));
        }
        return;
      case 'pusher_internal:subscription_succeeded':
        if (channel != null) {
          final pending = _pending.remove(channel);
          if (pending != null && !pending.isCompleted) pending.complete();
        }
        _touch();
        return;
      case 'pusher:subscription_error':
        if (channel != null) {
          final pending = _pending.remove(channel);
          final json = asJson(data);
          if (pending != null && !pending.isCompleted) pending.completeError(PusherException(json.str('error', 'Subscription refused'), code: json.i('status')));
        }
        return;
    }
    _touch();
    if (channel == null || event.startsWith('pusher:') || event.startsWith('pusher_internal:')) return;
    if (!_messages.isClosed) _messages.add(PusherMessage(channel: channel, event: event, data: data));
  }

  @override
  Future<void> subscribe(String channel, {PusherAuthorizer? authorize}) {
    _channels[channel] = authorize;
    return _doSubscribe(channel, authorize);
  }

  Future<void> _doSubscribe(String channel, PusherAuthorizer? authorize) async {
    final socketId = _socketId;
    if (_state != PusherState.connected || socketId == null) throw PusherException('Not connected');
    final completer = _pending[channel] = Completer<void>();
    try {
      String? auth;
      if (authorize != null) auth = await authorize(socketId, channel);
      _send('pusher:subscribe', {'channel': channel, 'auth': ?auth});
      await completer.future.timeout(timeout);
    } catch (e) {
      _pending.remove(channel);
      if (!completer.isCompleted) completer.complete();
      throw e is PusherException ? e : PusherException('$e');
    }
  }

  @override
  void unsubscribe(String channel) {
    _channels.remove(channel);
    _pending.remove(channel);
    if (_state == PusherState.connected) _send('pusher:unsubscribe', {'channel': channel});
  }

  void _onClosed(int? code, [String? error]) {
    if (_state == PusherState.closed) return;
    final wasClosing = _closing;
    _teardown();
    final c = _connecting;
    _connecting = null;
    if (c != null && !c.isCompleted) c.completeError(PusherException(error ?? 'Socket closed', code: code));
    for (final pending in _pending.values) {
      if (!pending.isCompleted) pending.completeError(PusherException('Socket closed', code: code));
    }
    _pending.clear();
    _set(PusherState.closed, error: wasClosing ? null : (error ?? 'Socket closed'), code: code);
  }

  Future<void> _teardown() async {
    _idle?.cancel();
    _pongWait?.cancel();
    _socketId = null;
    final sub = _sub;
    final wire = _wire;
    _sub = null;
    _wire = null;
    await sub?.cancel();
    await wire?.close();
  }

  @override
  Future<void> close() async {
    _closing = true;
    _channels.clear();
    if (_state != PusherState.closed && _state != PusherState.idle) _onClosed(1000);
    await _teardown();
    _messages.close().ignore();
    _status.close().ignore();
  }
}
