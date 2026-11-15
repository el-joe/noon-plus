import 'package:nawy_core/nawy_core.dart';

import 'models.dart';

/// `v1/{market}/delivery/…` (Sanctum, ability `delivery`).
class DeliveryApi {
  DeliveryApi(this.api);

  final ApiClient api;

  static const logoutPath = 'auth/logout';

  Future<Json> login(String email, String password) async =>
      asJson((await api.post('delivery/auth/login', body: {'email': email, 'password': password, 'device_name': AppConfig.deviceName})).data);

  Future<Json> me() => api.getObject('delivery/me');
  Future<Json> setAvailable(bool available) async => asJson((await api.post('delivery/me/availability', body: {'available': available})).data);
  Future<Json> updateProfile(Json body) async => asJson((await api.patch('delivery/me', body: body)).data);
  Future<Json> earnings() => api.getObject('delivery/me/earnings');
  Future<Json> cod() => api.getObject('delivery/me/cod');
  Future<Json> documents() => api.getObject('delivery/me/documents');
  Future<ApiResponse<List<Json>>> notifications({int page = 1}) => api.getList('me/notifications', query: {'page': page});
  Future<void> readAllNotifications() => api.post('me/notifications/read-all');

  Future<List<Assignment>> assignments({String status = 'open'}) async =>
      (await api.getList('delivery/assignments', query: {'status': status})).data.map(Assignment.new).toList();

  Future<Assignment> assignment(String number) async => Assignment(await api.getObject('delivery/assignments/${enc(number)}'));

  Future<Assignment> _act(String number, String action, [Json? body]) async =>
      Assignment(asJson((await api.post('delivery/assignments/${enc(number)}/$action', body: body)).data));

  Future<Assignment> accept(String number) => _act(number, 'accept');
  Future<Assignment> decline(String number, String reason) => _act(number, 'decline', {'reason': reason});
  Future<Assignment> start(String number) => _act(number, 'start');
  Future<Assignment> arrive(String number) => _act(number, 'arrive');
  Future<Assignment> fail(String number, String reason, {String? note}) => _act(number, 'fail', {'reason': reason, 'note': ?note});

  /// "Codes match" (ADR 15), with the proof collected on the proof screen.
  Future<Assignment> confirm(String number, ConfirmRequest request) async {
    final path = 'delivery/assignments/${enc(number)}/confirm';
    final res = request.isMultipart ? await api.multipart(path, fields: request.fields, files: request.files) : await api.post(path, body: request.json);
    return Assignment(asJson(res.data));
  }
}
