/// Build-time configuration (`--dart-define`).
///
/// `API_URL` is the backend API root without the market, e.g.
/// `https://api.nawy.example/api/v1`. The Android emulator reaches the host at
/// 10.0.2.2: `--dart-define=API_URL=http://10.0.2.2:8000/api/v1`.
class AppConfig {
  const AppConfig._();

  static const String apiUrl = String.fromEnvironment('API_URL', defaultValue: 'http://localhost:8000/api/v1');

  /// Device name sent with the sign-in (shows in the login history).
  static const String deviceName = String.fromEnvironment('DEVICE_NAME', defaultValue: 'Nawy ops app');

  /// The 8 country codes (MASTER_PLAN §1). Markets are `{code}-{en|ar}`.
  static const countries = ['uae', 'ksa', 'egy', 'kwt', 'qat', 'bhr', 'omn', 'jor'];
}
