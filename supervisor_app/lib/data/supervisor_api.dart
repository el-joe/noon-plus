import 'package:nawy_core/nawy_core.dart';

/// `v1/{market}/ops/supervisor/…` (Sanctum, ability `ops:supervisor`):
/// the carrier supervisor's own shipping company only.
class SupervisorApi {
  SupervisorApi(this.api);

  final ApiClient api;

  static const role = 'supervisor';
  static const logoutPath = 'ops/auth/logout';

  Future<Json> login(String email, String password) async =>
      asJson((await api.post('ops/auth/login', body: {'email': email, 'password': password, 'device_name': AppConfig.deviceName, 'role': role})).data);

  Future<Json> dashboard() => api.getObject('ops/supervisor/dashboard');
  Future<Json> dispatchBoard() => api.getObject('ops/supervisor/dispatch');

  /// [agentId] null = automatic assignment (`delivery.auto_assign`).
  Future<Json> assign(int shipmentId, {int? agentId}) async =>
      asJson((await api.post('ops/supervisor/dispatch/$shipmentId/assign', body: {'agent_id': agentId})).data);

  Future<List<Json>> failed() async => (await api.getList('ops/supervisor/failed')).data;

  /// `reschedule` {date}, `return` {reason}, `returned` (no body).
  Future<Json> failedAction(int shipmentId, String action, [Json body = const {}]) async =>
      asJson((await api.post('ops/supervisor/failed/$shipmentId/$action', body: body)).data);

  Future<List<Json>> drivers() async => (await api.getList('ops/supervisor/drivers')).data;

  Future<Json> setDriverStatus(int agentId, String status, {String? reason}) async =>
      asJson((await api.post('ops/supervisor/drivers/$agentId/status', body: {'status': status, 'reason': ?reason})).data);
}

/// `Y-m-d` for the reschedule date (country business day picked by the user).
String ymd(DateTime d) => '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

/// Attempts label ("2/3") and whether another attempt is still allowed.
String attemptsLabel(Json row) => '${row.i('attempts') ?? 0}/${row.i('max_attempts') ?? 0}';
bool attemptsLeft(Json row) => (row.i('attempts') ?? 0) < (row.i('max_attempts') ?? 0);
