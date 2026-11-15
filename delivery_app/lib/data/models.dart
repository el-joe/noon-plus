import 'package:nawy_core/nawy_core.dart';

/// One delivery assignment as `GET delivery/assignments/{number}` returns it
/// (backend `AgentAssignmentResource`, ADR 15: the code is shown to the agent
/// only while the assignment is open).
class Assignment {
  Assignment(this.json);

  final Json json;

  String get number => json.str('number');
  String get status => json.str('status');
  int get attempt => json.i('attempt') ?? 1;
  String? get deliveryCode => json.s('delivery_code');
  String get shipmentNumber => json.str('shipment_number');
  String get orderNumber => json.str('order_number');
  String? get paymentMethod => json.s('payment_method');
  Money? get codAmount => Money.fromJson(json['cod_amount']);
  bool get isCod => codAmount != null && !codAmount!.isZero;
  String? get seller => json.m('pickup')?.s('seller');
  Json? get customer => json.m('customer');
  String? get customerName => customer?.s('name');
  String? get customerPhone => customer?.s('phone');
  List<Json> get items => json.l('items');
  Json get proof => json.m('proof') ?? const {};
  bool get proofRequired => proof.b('required');
  String get proofType => proof.str('type', 'either');
  bool get hasPhoto => proof.b('has_photo');
  bool get hasSignature => proof.b('has_signature');
  List<String> get failureReasons => json.strings('failure_reasons');
  String? get failureReason => json.s('failure_reason');
  DateTime? at(String key) => DateTime.tryParse(json.str(key))?.toLocal();
  DateTime? get slaDueAt => at('sla_due_at');
  DateTime? get arrivedAt => at('arrived_at');

  bool get isOpen => const {'assigned', 'accepted', 'picked_up'}.contains(status);

  /// Single address line for maps and lists ("Building 4, Apt 905, Marina, Dubai").
  String get addressLine {
    final address = customer?.m('address') ?? const {};
    const order = ['building', 'apartment', 'street', 'area', 'district', 'city', 'line1', 'line2'];
    final parts = <String>[];
    for (final key in order) {
      final v = address.s(key);
      if (v != null && v.trim().isNotEmpty && !parts.contains(v.trim())) parts.add(v.trim());
    }
    address.forEach((key, value) {
      if (!order.contains(key) && value is String && value.trim().isNotEmpty && !const {'country', 'kind', 'label', 'landmark', 'notes', 'type'}.contains(key) && !parts.contains(value.trim())) {
        parts.add(value.trim());
      }
    });
    return parts.join(', ');
  }

  String? get landmark => customer?.m('address')?.s('landmark') ?? customer?.m('address')?.s('notes');
}

/// What the agent can do next on a stop (lifecycle catalogue §2.4, owner
/// review W1/W3): assigned → accept / decline; accepted → start (picked up,
/// sub-order out for delivery); picked_up → navigate, arrive (records the
/// time only), deliver (code match) or fail.
enum StopAction { accept, decline, start, navigate, arrive, deliver, fail }

List<StopAction> actionsFor(Assignment a) => switch (a.status) {
      'assigned' => const [StopAction.accept, StopAction.decline],
      'accepted' => const [StopAction.start],
      'picked_up' => [StopAction.navigate, if (a.arrivedAt == null) StopAction.arrive, StopAction.deliver, StopAction.fail],
      _ => const [],
    };

/// After "Codes match": proof first when the API says it is required (COD and
/// above the configured value) — W5.
bool needsProofStep(Assignment a) => a.proofRequired && !a.hasPhoto && !a.hasSignature;

/// Whether the collected proof satisfies `delivery.proof_type`
/// (`photo`, `signature` or `either`).
bool proofSatisfied({required String proofType, required bool hasPhoto, required bool hasSignature}) => switch (proofType) {
      'photo' => hasPhoto,
      'signature' => hasSignature,
      'both' => hasPhoto && hasSignature,
      _ => hasPhoto || hasSignature,
    };

/// The "Codes match" confirmation sent to `POST …/confirm`. The agent never
/// types or sends the code itself (ADR 15) — only `codes_match`.
class ConfirmRequest {
  const ConfirmRequest({this.signatureDataUrl, this.photo});

  final String? signatureDataUrl;
  final List<int>? photo;

  bool get isMultipart => photo != null;

  Json get json => {'codes_match': true, 'signature_data': ?signatureDataUrl};

  Map<String, String> get fields => {'codes_match': '1', 'signature_data': ?signatureDataUrl};

  List<FilePart> get files => [if (photo != null) FilePart(field: 'photo', filename: 'proof.jpg', bytes: photo!, contentType: 'image/jpeg')];
}

/// Cash-on-delivery maths (no floats; [Money] minor units, half-up).
class CodMath {
  const CodMath._();

  /// Total of a list of `{amount, currency}` maps, in [currency].
  static Money total(Iterable<dynamic> amounts, String currency) =>
      amounts.map(Money.fromJson).whereType<Money>().where((m) => m.currency == currency).fold(Money.zero(currency), (sum, m) => sum + m);

  /// Change to hand back when the customer pays [received] for [due]; null
  /// when [received] is short of [due].
  static Money? changeDue(Money due, Money received) {
    final change = received - due;
    return change.isNegative ? null : change;
  }

  /// Cash in hand after collecting [due] on top of [inHand].
  static Money afterCollecting(Money inHand, Money due) => inHand + due;

  /// COD still to collect on the open stops.
  static Money toCollect(Iterable<Assignment> stops, String currency) =>
      stops.where((s) => s.isOpen && s.codAmount?.currency == currency).fold(Money.zero(currency), (sum, s) => sum + s.codAmount!);
}
