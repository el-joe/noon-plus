import 'dart:async';
import 'dart:io';

import 'pusher_socket.dart';

/// `dart:io` [WebSocket] wire (Android, iOS, desktop). No pub packages
/// (project rule: no `web_socket_channel` / pusher package).
class _IoPusherWire implements PusherWire {
  _IoPusherWire(this._socket);

  final WebSocket _socket;

  @override
  Stream<String> get incoming => _socket.where((m) => m is String).cast<String>();

  @override
  void send(String text) {
    if (_socket.readyState == WebSocket.open) _socket.add(text);
  }

  @override
  int? get closeCode => _socket.closeCode;

  @override
  Future<void> close([int code = 1000]) async {
    await _socket.close(code).timeout(const Duration(seconds: 2), onTimeout: () {});
  }
}

const bool pusherWireSupported = true;

Future<PusherWire> connectPusherWire(Uri uri, {Duration timeout = const Duration(seconds: 15)}) async {
  final socket = await WebSocket.connect(uri.toString()).timeout(timeout);
  socket.pingInterval = const Duration(seconds: 25);
  return _IoPusherWire(socket);
}
