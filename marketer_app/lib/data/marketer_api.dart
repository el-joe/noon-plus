import 'package:nawy_core/nawy_core.dart';

/// `v1/{market}/ops/marketer/…` (Sanctum, ability `ops:marketer`).
class MarketerApi {
  MarketerApi(this.api);

  final ApiClient api;

  static const logoutPath = 'ops/auth/logout';

  Future<Json> login(String email, String password) async =>
      asJson((await api.post('ops/auth/login', body: {'email': email, 'password': password, 'device_name': AppConfig.deviceName, 'role': 'marketer'})).data);

  Future<Json> dashboard() => api.getObject('ops/marketer/dashboard');
  Future<List<Json>> campaigns() async => (await api.getList('ops/marketer/campaigns')).data;
  Future<Json> links() => api.getObject('ops/marketer/links');
  Future<ApiResponse<List<Json>>> conversions({int page = 1}) => api.getList('ops/marketer/conversions', query: {'page': page});
}

/// Sum of the conversions' commission (net) in [currency]; reversed rows
/// are left out, like the Studio overview.
Money commissionTotal(Iterable<Json> conversions, String currency) => conversions
    .where((c) => c.s('status') != 'reversed')
    .map((c) => Money.fromJson(c['commission']))
    .whereType<Money>()
    .where((m) => m.currency == currency)
    .fold(Money.zero(currency), (sum, m) => sum + m);

/// Conversion rate in whole percent (conversions / clicks), 0 without clicks.
int conversionRate(int clicks, int conversions) => clicks <= 0 ? 0 : (conversions * 100 / clicks).round();
