import 'pusher_socket.dart';

/// The web build has no `dart:io` WebSocket here; callers check
/// [pusherWireSupported] and fall back (the storefront page plays live).
const bool pusherWireSupported = false;

Future<PusherWire> connectPusherWire(Uri uri, {Duration timeout = const Duration(seconds: 15)}) =>
    Future.error(UnsupportedError('Realtime sockets are not supported in this build'));
