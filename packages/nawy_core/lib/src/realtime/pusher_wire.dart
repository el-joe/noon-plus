// Platform WebSocket for the Pusher client: dart:io on mobile/desktop; the
// web build reports "unsupported" (the apps fall back to the browser page).
export 'pusher_wire_io.dart' if (dart.library.js_interop) 'pusher_wire_web.dart' show connectPusherWire, pusherWireSupported;
