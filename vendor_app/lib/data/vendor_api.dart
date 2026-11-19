import 'package:nawy_core/nawy_core.dart';

/// `v1/{market}/ops/vendor/…` (Sanctum, ability `ops:vendor`).
class VendorApi {
  VendorApi(this.api);

  final ApiClient api;

  static const logoutPath = 'ops/auth/logout';

  Future<Json> login(String email, String password) async =>
      asJson((await api.post('ops/auth/login', body: {'email': email, 'password': password, 'device_name': AppConfig.deviceName, 'role': 'vendor'})).data);

  Future<Json> dashboard() => api.getObject('ops/vendor/dashboard');

  /// Seller Agreement gate (QC-017): `{required, signed, can_sign,
  /// version:{id,title,text}|null}`. Every other `ops/vendor/*` route answers
  /// 403 `seller_agreement_required` until the owner signs.
  Future<SellerAgreement> agreement() async => SellerAgreement(await api.getObject('ops/vendor/agreement'));

  /// Click-wrap signature (store owner only; 403 `owner_only`, 409
  /// `version_outdated`).
  Future<void> signAgreement(int versionId, String fullName) =>
      api.post('ops/vendor/agreement/sign', body: {'version_id': versionId, 'full_name': fullName, 'accept': true});

  Future<ApiResponse<List<Json>>> orders({String? status, String? q, int page = 1}) =>
      api.getList('ops/vendor/orders', query: {'status': status, 'q': q, 'page': page});

  Future<Json> order(int id) => api.getObject('ops/vendor/orders/$id');

  Future<Json> advance(int id, String to) async => asJson((await api.post('ops/vendor/orders/$id/advance', body: {'to': to})).data);

  Future<Json> cancel(int id, String reason) async => asJson((await api.post('ops/vendor/orders/$id/cancel', body: {'reason': reason})).data);

  Future<ApiResponse<List<Json>>> stock({String? q, bool low = false, int page = 1}) =>
      api.getList('ops/vendor/stock', query: {'q': q, 'low': low ? true : null, 'page': page});

  Future<Json> adjustStock(int listingId, StockAdjustment adjustment) async =>
      asJson((await api.post('ops/vendor/stock/$listingId', body: adjustment.json)).data);

  // Fulfilled by Nawy (FBN): warehouse stock and inbound plans. Refusals
  // (wrong state, product not enrolled) are 422 `transition_refused`.

  /// FBN stock per SKU with `warehouses[]` rows (on hand / reserved /
  /// available / damaged) and `low_stock`, `long_term`, `unsellable` flags.
  Future<List<Json>> fbnStock({bool low = false}) async => (await api.getList('ops/vendor/fbn/stock', query: {'low': low ? true : null})).data;

  Future<List<Json>> fbnWarehouses() async => (await api.getList('ops/vendor/fbn/warehouses')).data;

  Future<ApiResponse<List<Json>>> inboundPlans({String? status, int page = 1}) =>
      api.getList('ops/vendor/fbn/inbound', query: {'status': status, 'page': page});

  Future<Json> inboundPlan(int id) => api.getObject('ops/vendor/fbn/inbound/$id');

  /// FBN enrolment per listing: `fulfilment` = `fbn` | `fbm` | null (all).
  /// Rows: listing_id, sku, title, status, fulfilment, warehouse_units,
  /// can_enrol, can_leave; `meta.has_warehouses`.
  Future<ApiResponse<List<Json>>> fbnListings({String? fulfilment, int page = 1}) =>
      api.getList('ops/vendor/fbn/listings', query: {'fulfilment': fulfilment, 'page': page});

  /// 422 `transition_refused` (e.g. warehouse stock left) carries the reason.
  Future<Json> enrolFbn(int listingId) async => asJson((await api.post('ops/vendor/fbn/listings/$listingId/enrol')).data);
  Future<Json> leaveFbn(int listingId) async => asJson((await api.post('ops/vendor/fbn/listings/$listingId/leave')).data);

  /// Carton labels PDF of a submitted plan (409 before submit).
  Future<ApiFile> inboundLabels(int id) => api.download('ops/vendor/fbn/inbound/$id/labels.pdf');

  Future<Json> createInboundPlan(InboundDraft draft) async => asJson((await api.post('ops/vendor/fbn/inbound', body: draft.json)).data);

  /// `submit`, `ship` (`carrier_name`, `tracking_number`) or `cancel`.
  Future<Json> inboundAction(int id, String action, {Json body = const {}}) async =>
      asJson((await api.post('ops/vendor/fbn/inbound/$id/$action', body: body)).data);

  // Support tickets (QC-007): the store team's shared tickets. These routes
  // work before the Seller Agreement is signed. Refusals are 422
  // `transition_refused` with the reason in the message.

  Future<SupportOptions> supportOptions() async => SupportOptions(await api.getObject('ops/vendor/support/categories'));

  /// Newest activity first; `meta {total, page, per_page, last_page}`.
  Future<ApiResponse<List<Json>>> supportTickets({String? status, int page = 1}) =>
      api.getList('ops/vendor/support/tickets', query: {'status': status, 'page': page, 'per_page': 20});

  Future<SupportTicket> supportTicket(String number) async => SupportTicket(await api.getObject('ops/vendor/support/tickets/${enc(number)}'));

  /// JSON without files; `multipart/form-data` with `attachments[]` when
  /// photos were picked (image_picker, owner-review D16).
  Future<SupportTicket> openTicket(TicketDraft draft) async {
    const path = 'ops/vendor/support/tickets';
    final res = draft.attachments.isEmpty
        ? await api.post(path, body: draft.json)
        : await api.multipart(path, fields: draft.fields, files: [for (final f in draft.attachments) f.part('attachments[]')]);
    return SupportTicket(asJson(res.data));
  }

  Future<SupportTicket> replyToTicket(String number, String body, {List<PickedMedia> attachments = const []}) async {
    final path = 'ops/vendor/support/tickets/${enc(number)}/messages';
    final res = attachments.isEmpty
        ? await api.post(path, body: {'body': body.trim()})
        : await api.multipart(path, fields: {'body': body.trim()}, files: [for (final f in attachments) f.part('attachments[]')]);
    return SupportTicket(asJson(res.data));
  }

  Future<SupportTicket> reopenTicket(String number, {String? message}) async {
    final note = message?.trim();
    return SupportTicket(asJson((await api.post('ops/vendor/support/tickets/${enc(number)}/reopen', body: {if (note != null && note.isNotEmpty) 'message': note})).data));
  }

  Future<SupportTicket> rateTicket(String number, int rating, {String? comment}) async {
    final note = comment?.trim();
    return SupportTicket(asJson((await api.post('ops/vendor/support/tickets/${enc(number)}/rating', body: {'rating': rating, if (note != null && note.isNotEmpty) 'comment': note})).data));
  }
}

/// Ticket states (lifecycle §9.3), as filter chips (null = all).
const supportStatuses = ['open', 'in_progress', 'waiting_customer', 'resolved', 'closed'];
const supportFilters = <String?>[null, ...supportStatuses];

/// One `{value, label}` choice of `support/categories`.
typedef SupportChoice = ({String value, String label});

/// `GET ops/vendor/support/categories`.
class SupportOptions {
  SupportOptions(this.json);

  final Json json;

  List<SupportChoice> get categories => [
        for (final c in json.l('categories'))
          if ((c.s('value') ?? '').isNotEmpty) (value: c.str('value'), label: c.str('label', c.str('value'))),
      ];

  /// Requester priorities (low · normal · high). The API sends `{value,
  /// label}` objects; plain strings are accepted too.
  List<SupportChoice> get priorities {
    final raw = json['priorities'];
    final out = <SupportChoice>[];
    if (raw is List) {
      for (final p in raw) {
        if (p is String && p.isNotEmpty) out.add((value: p, label: ''));
        if (p is Map) {
          final m = p.cast<String, dynamic>();
          if ((m.s('value') ?? '').isNotEmpty) out.add((value: m.str('value'), label: m.str('label')));
        }
      }
    }
    return out.isEmpty ? const [(value: 'low', label: ''), (value: 'normal', label: ''), (value: 'high', label: '')] : out;
  }

  int get maxAttachments => json.i('max_attachments') ?? 5;

  /// The API's attachment limits (`max_attachments`, `attachment_max_kb`,
  /// `attachment_types`), defaulting to 5 × 5 MB JPG/PNG/WebP/PDF.
  UploadRules get attachmentRules {
    final types = json.strings('attachment_types').map((t) => mimeFromName('x.$t')).whereType<String>().toSet();
    return UploadRules(
      maxCount: maxAttachments,
      maxBytes: (json.i('attachment_max_kb') ?? 5120) * 1024,
      mimeTypes: types.isEmpty ? UploadRules.supportAttachments.mimeTypes : types,
    );
  }
}

/// A support ticket (list row or detail with `messages` and `history`).
class SupportTicket {
  SupportTicket(this.json);

  final Json json;

  String get number => json.str('number');
  String get subject => json.str('subject');
  String get status => json.str('status');
  String? get statusLabel => json.s('status_label');
  String get category => json.str('category');
  String get categoryLabel => json.str('category_label', category);
  String get priority => json.str('priority', 'normal');
  String? get order => json.s('order');
  String? get shipment => json.s('shipment');
  bool get canReply => json.b('can_reply');
  bool get canReopen => json.b('can_reopen');
  bool get canRate => json.b('can_rate');
  Json? get rating => json.m('rating');
  int? get ratingScore => rating?.i('score');
  List<Json> get messages => json.l('messages');
  List<Json> get history => json.l('history');

  DateTime? at(String key) => DateTime.tryParse(json.str(key))?.toLocal();
  DateTime? get lastActivity => at('last_message_at') ?? at('created_at');
}

/// A new ticket. [validate] returns an l10n key or null (API limits:
/// subject 3–160, message 10–5000).
class TicketDraft {
  const TicketDraft({required this.category, required this.subject, required this.message, this.priority, this.attachments = const []});

  final String category;
  final String subject;
  final String message;
  final String? priority;
  final List<PickedMedia> attachments;

  static String? validate({required String? category, required String subject, required String message}) {
    if (category == null || category.isEmpty) return 'support.errCategory';
    final s = subject.trim().length;
    if (s < 3 || s > 160) return 'support.errSubject';
    final m = message.trim().length;
    if (m < 10 || m > 5000) return 'support.errMessage';
    return null;
  }

  Json get json => {'category': category, 'subject': subject.trim(), 'message': message.trim(), 'priority': ?priority};

  /// The same fields for a multipart request.
  Map<String, String> get fields => {'category': category, 'subject': subject.trim(), 'message': message.trim(), 'priority': ?priority};
}

/// Null when a reply body is valid (2–5000 characters), else an l10n key.
String? replyError(String body) {
  final n = body.trim().length;
  return n < 2 || n > 5000 ? 'support.errReply' : null;
}

/// Order status filters shown as chips (null = all).
const orderFilters = <String?>[null, 'placed', 'confirmed', 'processing', 'packed'];

/// Stock reasons (backend `nawy.vendors.stock_reasons`).
const stockReasons = ['restock', 'correction', 'damaged', 'lost'];

/// A stock move: positive adds units, negative removes (Seller Center rule).
class StockAdjustment {
  const StockAdjustment(this.quantity, this.reason);

  final int quantity;
  final String reason;

  Json get json => {'quantity': quantity, 'reason': reason};

  /// Null when valid, otherwise an l10n key. [current] is the units in stock.
  static String? validate({required String raw, required bool remove, required String? reason, required int current}) {
    final value = int.tryParse(raw.trim());
    if (value == null || value <= 0) return 'stock.errQuantity';
    if (value > 100000) return 'stock.errTooMany';
    if (reason == null || !stockReasons.contains(reason)) return 'stock.errReason';
    if (remove && value > current) return 'stock.errInsufficient';
    return null;
  }

  static StockAdjustment from({required String raw, required bool remove, required String reason}) {
    final value = int.parse(raw.trim());
    return StockAdjustment(remove ? -value : value, reason);
  }

  /// Units after the move.
  int applyTo(int current) => current + quantity;
}

/// Low stock = 1 to threshold units (backend `LOW_STOCK_THRESHOLD`).
bool isLowStock(Json row) {
  final stock = row.i('stock') ?? 0;
  final threshold = row.i('low_stock_threshold') ?? 5;
  return stock >= 1 && stock <= threshold;
}

class SellerAgreement {
  SellerAgreement(this.json);

  final Json json;

  bool get required => json.b('required');
  bool get signed => json.b('signed');
  bool get canSign => json.b('can_sign');
  Json? get version => json.m('version');
  int? get versionId => version?.i('id');
  String get title => version?.s('title') ?? '';
  String get text => version?.s('text') ?? '';

  /// The app must show the agreement before anything else.
  bool get blocking => required && !signed;
}

/// 403 code the vendor API returns until the agreement is signed.
const agreementRequiredCode = 'seller_agreement_required';

/// Inbound plan statuses (draft → submitted → in transit → arrived →
/// receiving → received → closed; cancelled), as filter chips (null = all).
const inboundStatuses = ['draft', 'submitted', 'in_transit', 'arrived', 'receiving', 'received', 'closed', 'cancelled'];

/// A new FBN inbound plan: warehouse, `{listing_id: units}` and whether to
/// submit it straight away.
class InboundDraft {
  InboundDraft({required this.warehouseId, required this.units, this.submit = true, this.cartons});

  final int? warehouseId;
  final Map<int, int> units;
  final bool submit;
  final int? cartons;

  /// The l10n key of the first problem, or null when it can be sent.
  String? validate() {
    if (warehouseId == null) return 'fbn.errWarehouse';
    if (units.values.every((u) => u <= 0)) return 'fbn.errLines';
    if (units.values.any((u) => u > 100000)) return 'stock.errTooMany';
    return null;
  }

  Json get json => {
        'warehouse_id': warehouseId,
        'submit': submit,
        if (cartons != null) 'cartons': cartons,
        'lines': [
          for (final e in units.entries)
            if (e.value > 0) {'listing_id': e.key, 'quantity': e.value},
        ],
      };
}
