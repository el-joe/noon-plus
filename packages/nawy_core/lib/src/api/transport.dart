// Platform HTTP transport: dart:io on mobile/desktop, fetch on the web.
export 'transport_io.dart' if (dart.library.js_interop) 'transport_web.dart' show createTransport;
