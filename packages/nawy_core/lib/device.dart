/// Device features for apps that keep their own core (customer_app):
/// camera / gallery picking with the API upload limits, the attachment
/// field, tap-to-call, and the url_launcher / path_provider bridge (import
/// with a prefix where the app has its own `PlatformBridge`).
library;

export 'src/api/api_client.dart' show FilePart, encodeMultipart;
export 'src/platform/media_picker.dart';
export 'src/platform/platform_bridge.dart';
export 'src/ui/media_widgets.dart';
