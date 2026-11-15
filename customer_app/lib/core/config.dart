/// Build-time configuration (`--dart-define`).
///
/// `API_URL` is the backend API root without the market, e.g.
/// `https://api.nawy.example/api/v1`. The Android emulator reaches the host
/// machine at 10.0.2.2, so pass `--dart-define=API_URL=http://10.0.2.2:8000/api/v1`
/// there.
class AppConfig {
  const AppConfig._();

  static const String apiUrl = String.fromEnvironment('API_URL', defaultValue: 'http://localhost:8000/api/v1');

  /// Storefront origin, used for "open on the web" links (help, legal pages).
  static const String storefrontUrl = String.fromEnvironment('STOREFRONT_URL', defaultValue: 'http://localhost:3000');

  /// Device name sent with the email-code sign-in (shows in Security → Devices).
  static const String deviceName = String.fromEnvironment('DEVICE_NAME', defaultValue: 'Nawy app');
}
