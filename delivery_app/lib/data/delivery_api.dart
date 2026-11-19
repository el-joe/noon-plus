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

  /// Uploads one document (`file`: pdf / jpg / jpeg / png / webp up to the
  /// type's `max_mb`; `valid_until` YYYY-MM-DD, required when the type has an
  /// expiry). 422 field errors on `file` / `valid_until`.
  Future<Json> uploadDocument({required int typeId, required PickedMedia file, String? validUntil}) async => asJson((await api.multipart(
        'delivery/me/documents',
        fields: {'document_type_id': '$typeId', 'valid_until': ?validUntil},
        files: [file.part('file')],
      ))
          .data);

  Future<List<Json>> payslips() async => (await api.getList('delivery/me/payslips')).data;
  Future<Json> payslip(String number) => api.getObject('delivery/me/payslips/${enc(number)}');
  Future<ApiFile> payslipPdf(String number) => api.download('delivery/me/payslips/${enc(number)}/pdf');

  Future<Json> wallet() => api.getObject('delivery/me/wallet');

  /// `amount` as a decimal string (`150.00`); `iban` without spaces.
  Future<Json> withdraw({required String amount, required String accountHolder, required String bankName, required String iban}) async =>
      asJson((await api.post('delivery/me/withdrawals', body: {'amount': amount, 'account_holder': accountHolder, 'bank_name': bankName, 'iban': iban})).data);

  Future<Json> cancelWithdrawal(String number) async => asJson((await api.post('delivery/me/withdrawals/${enc(number)}/cancel')).data);
  Future<ApiResponse<List<Json>>> notifications({int page = 1}) => api.getList('me/notifications', query: {'page': page});
  Future<void> readAllNotifications() => api.post('me/notifications/read-all');

  /// The agent's stops. The open list never shows a `cancelled` assignment,
  /// even if an older API still returned one.
  Future<List<Assignment>> assignments({String status = 'open'}) async {
    final list = (await api.getList('delivery/assignments', query: {'status': status})).data.map(Assignment.new).toList();
    return status == 'open' ? list.where((a) => !a.isCancelled).toList() : list;
  }

  /// Of the stops that left the open list, the ones that were cancelled.
  Future<List<Assignment>> cancelledAmong(Set<String> numbers) async {
    final found = <Assignment>[];
    for (final number in numbers) {
      try {
        final a = await assignment(number);
        if (a.isCancelled) found.add(a);
      } on ApiException {
        // Gone or not visible any more: nothing to tell the agent.
      }
    }
    return found;
  }

  Future<Assignment> assignment(String number) async => Assignment(await api.getObject('delivery/assignments/${enc(number)}'));

  Future<Assignment> _act(String number, String action, [Json? body]) async =>
      Assignment(asJson((await api.post('delivery/assignments/${enc(number)}/$action', body: body)).data));

  Future<Assignment> accept(String number) => _act(number, 'accept');
  Future<Assignment> decline(String number, String reason) => _act(number, 'decline', {'reason': reason});
  Future<Assignment> start(String number) => _act(number, 'start');
  Future<Assignment> arrive(String number) => _act(number, 'arrive');
  /// The server's check of a scanned parcel label (authoritative): 200
  /// `{matches: true, shipment_number}`, or 422 `wrong_parcel`.
  Future<Json> scan(String number, String code) async => asJson((await api.post('delivery/assignments/${enc(number)}/scan', body: {'code': code})).data);

  Future<Assignment> fail(String number, String reason, {String? note}) => _act(number, 'fail', {'reason': reason, 'note': ?note});

  /// "Codes match" (ADR 15), with the proof collected on the proof screen.
  Future<Assignment> confirm(String number, ConfirmRequest request) async {
    final path = 'delivery/assignments/${enc(number)}/confirm';
    final res = request.isMultipart ? await api.multipart(path, fields: request.fields, files: request.files) : await api.post(path, body: request.json);
    return Assignment(asJson(res.data));
  }
}
