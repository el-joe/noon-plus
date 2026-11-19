import 'dart:async';
import 'dart:convert';

import '../realtime/pusher_socket.dart';

/// In-memory [PusherSocket]: `connect` answers [socketIdValue] (or fails with
/// [connectError]); `subscribe` calls the authorizer (so the app's
/// `broadcasting/auth` POST runs) and succeeds unless [subscribeError] is
/// set. Tests push channel events with [emit] and drop the socket with
/// [drop].
class FakePusherSocket implements PusherSocket {
  FakePusherSocket({this.config, this.socketIdValue = '123.456', this.connectError, this.subscribeError});

  final PusherConfig? config;
  final String socketIdValue;
  Object? connectError;
  Object? subscribeError;

  final _messages = StreamController<PusherMessage>.broadcast(sync: true);
  final _status = StreamController<PusherStatus>.broadcast(sync: true);

  /// Channel → the `auth` string the app's authorizer returned.
  final Map<String, String?> subscriptions = {};
  final List<String> unsubscribed = [];
  int connects = 0;
  bool closed = false;

  PusherState _state = PusherState.idle;

  @override
  Stream<PusherMessage> get messages => _messages.stream;

  @override
  Stream<PusherStatus> get status => _status.stream;

  @override
  PusherState get state => _state;

  @override
  String? get socketId => _state == PusherState.connected ? socketIdValue : null;

  @override
  Future<String> connect() async {
    connects++;
    final error = connectError;
    if (error != null) {
      _state = PusherState.closed;
      throw error is PusherException ? error : PusherException('$error');
    }
    _state = PusherState.connected;
    _status.add(const PusherStatus(PusherState.connected));
    return socketIdValue;
  }

  @override
  Future<void> subscribe(String channel, {PusherAuthorizer? authorize}) async {
    if (_state != PusherState.connected) throw PusherException('Not connected');
    final auth = authorize == null ? null : await authorize(socketIdValue, channel);
    final error = subscribeError;
    if (error != null) throw error is PusherException ? error : PusherException('$error');
    subscriptions[channel] = auth;
  }

  @override
  void unsubscribe(String channel) {
    subscriptions.remove(channel);
    unsubscribed.add(channel);
  }

  /// Deliver an event (data as sent on the wire: a map is fine).
  void emit(String channel, String event, Object? data) {
    if (_messages.isClosed) return;
    _messages.add(PusherMessage(channel: channel, event: event, data: data is String ? PusherClient.decodeData(data) : data));
  }

  /// The socket closed unexpectedly.
  void drop({String error = 'Socket closed', int? code = 1006}) {
    _state = PusherState.closed;
    if (!_status.isClosed) _status.add(PusherStatus(PusherState.closed, error: error, code: code));
  }

  @override
  Future<void> close() async {
    closed = true;
    _state = PusherState.closed;
    // Not awaited: a closed broadcast controller's done future can wait on
    // listeners the caller already cancelled.
    _messages.close().ignore();
    _status.close().ignore();
  }
}

/// In-memory [PusherWire] for the protocol tests: [sent] holds the decoded
/// frames the client wrote; [receive] feeds server frames.
class FakePusherWire implements PusherWire {
  final _incoming = StreamController<String>();
  final List<Map<String, dynamic>> sent = [];
  bool closed = false;

  @override
  int? closeCode;

  @override
  Stream<String> get incoming => _incoming.stream;

  @override
  void send(String text) => sent.add((jsonDecode(text) as Map).cast<String, dynamic>());

  /// A server frame; `data` is JSON-encoded into a string like Pusher does.
  void receive(String event, {Object? data, String? channel}) =>
      _incoming.add(jsonEncode({'event': event, 'channel': ?channel, 'data': data is String ? data : jsonEncode(data ?? const {})}));

  void serverClose([int code = 1006]) {
    closeCode = code;
    _incoming.close();
  }

  List<Map<String, dynamic>> sentEvents(String event) => sent.where((f) => f['event'] == event).toList();

  @override
  Future<void> close([int code = 1000]) async {
    closed = true;
    closeCode ??= code;
    if (!_incoming.isClosed) await _incoming.close();
  }
}
