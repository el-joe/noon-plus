import 'package:nawy_core/nawy_core.dart';

/// `cod_in_hand` of `GET delivery/me`: a Money object `{amount, currency}`
/// since wave 5a (QA-042); older APIs sent a bare decimal string in the
/// market currency. Both are read; anything else is zero.
Money codInHandOf(Json me, String currency) => Money.lenient(me['cod_in_hand'], currency) ?? Money.zero(currency);

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

  /// The parcel label payload (`<shipment number>.<check>`, Code 39).
  String? get barcode => json.s('barcode');
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

  /// On the road with the parcel (picked up, out for delivery, arrived):
  /// the location is shared even with the app in the background. Backend
  /// `AssignmentStatus::PickedUp`; arriving only records `arrived_at`.
  bool get isInProgress => status == 'picked_up';

  /// The sub-order was cancelled (QA-005): nothing more to do on this stop.
  /// Before pickup the shipment is cancelled; after pickup it is returning
  /// to origin.
  bool get isCancelled => status == 'cancelled';

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
  const ConfirmRequest({this.signatureDataUrl, this.photo, this.photoType = 'image/jpeg'});

  final String? signatureDataUrl;
  final List<int>? photo;

  /// Content type of [photo] (sniffed by the picker; the camera gives JPEG).
  final String photoType;

  bool get isMultipart => photo != null;

  Json get json => {'codes_match': true, 'signature_data': ?signatureDataUrl};

  Map<String, String> get fields => {'codes_match': '1', 'signature_data': ?signatureDataUrl};

  List<FilePart> get files => [if (photo != null) FilePart(field: 'photo', filename: 'proof.${extensionFor(photoType) ?? 'jpg'}', bytes: photo!, contentType: photoType)];
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

/// Stops that were open at the last load ([before]) and are gone now: the
/// candidates for a "cancelled" notice (the API drops cancelled
/// assignments from the open list).
Set<String> vanishedStops(Set<String> before, Iterable<Assignment> now) => before.difference({for (final a in now) a.number});

/// Remembers the open stops between loads and finds the ones that were
/// cancelled since (QA-005), so the list can tell the agent.
class CancelledWatch {
  Set<String>? _known;

  /// Cancelled stops among those that left [open] since the last call.
  Future<List<Assignment>> check(Future<List<Assignment>> Function(Set<String> numbers) cancelledAmong, List<Assignment> open) async {
    final before = _known;
    _known = {for (final a in open) a.number};
    if (before == null) return const [];
    final gone = vanishedStops(before, open);
    return gone.isEmpty ? const [] : cancelledAmong(gone);
  }
}

/// Normalises a scanned or typed parcel code (`nw-ae 1028810-1` →
/// `NW-AE1028810-1`, `*SH-NW-AE-1-1.X*` → `SH-NW-AE-1-1.X`): trims,
/// upper-cases, drops spaces and the Code 39 start/stop `*`.
String normaliseParcelCode(String raw) => raw.trim().toUpperCase().replaceAll(RegExp(r'\s+'), '').replaceAll(RegExp(r'^\*+|\*+$'), '');

/// Code 39 characters in value order (index = value) for the mod 43 check.
const code39Charset = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ-. \$/+%';

/// The Code 39 mod 43 check character of [value] (sum of the character
/// values mod 43). Null when [value] has a character Code 39 can't encode.
String? parcelCheckChar(String value) {
  var sum = 0;
  for (final ch in value.split('')) {
    final v = code39Charset.indexOf(ch);
    if (v < 0) return null;
    sum += v;
  }
  return code39Charset[sum % 43];
}

/// A parcel label payload: `<shipment number>.<check>` (backend parcel
/// label, Code 39), or a number typed by hand.
class ParcelBarcode {
  const ParcelBarcode._(this.code, this.base, {required this.labelled, required this.checkValid});

  /// The normalised code as read.
  final String code;

  /// The shipment number of a label, else the whole code.
  final String base;

  /// Read from a label (`base.check`), not typed by hand.
  final bool labelled;

  /// The check character matches [base] (always true when not [labelled]).
  final bool checkValid;
}

/// Splits a scanned label into the shipment number and its check
/// character. The check is the last character after the last `.`; a check
/// character that is itself `.` (value 37) gives `…..`, and a space (value
/// 38) is lost to trimming, so a code ending in `.` is a valid label when
/// the check of the rest is a space.
ParcelBarcode parseParcelBarcode(String raw) {
  final code = normaliseParcelCode(raw);
  if (!code.contains('.')) return ParcelBarcode._(code, code, labelled: false, checkValid: true);
  if (code.endsWith('.')) {
    final base = code.substring(0, code.length - 1);
    // `base..` (check `.`) or `base.` + a trimmed space.
    if (base.endsWith('.') && base.length > 1 && parcelCheckChar(base.substring(0, base.length - 1)) == '.') {
      return ParcelBarcode._(code, base.substring(0, base.length - 1), labelled: true, checkValid: true);
    }
    return ParcelBarcode._(code, base, labelled: true, checkValid: base.isNotEmpty && parcelCheckChar(base) == ' ');
  }
  final dot = code.lastIndexOf('.');
  final base = code.substring(0, dot);
  final check = code.substring(dot + 1);
  return ParcelBarcode._(code, base, labelled: true, checkValid: base.isNotEmpty && check.length == 1 && parcelCheckChar(base) == check);
}

/// The outcome of checking a scanned or typed code against a stop.
enum ParcelMatch {
  /// This stop's parcel.
  match,

  /// A label whose check character is wrong: misread, scan again.
  badLabel,

  /// A valid label of another shipment.
  otherParcel,

  /// A typed code that is none of this stop's numbers.
  noMatch,

  /// Nothing entered.
  empty,
}

/// Checks [code] (Figma "12 Pickup scan") against this stop. A label
/// (`<shipment>.<check>`) must have a valid check character and this stop's
/// shipment number; a code typed by hand may be the shipment, assignment or
/// order number.
ParcelMatch matchParcelCode(Assignment a, String code) {
  final parsed = parseParcelBarcode(code);
  if (parsed.code.isEmpty) return ParcelMatch.empty;
  if (parsed.labelled) {
    if (!parsed.checkValid) return ParcelMatch.badLabel;
    // The label carries the shipment (AWB) number; the API sends the full
    // payload as `barcode`. Without it, fall back to the shown number.
    final expected = (a.barcode ?? '').isNotEmpty ? parseParcelBarcode(a.barcode!).base : normaliseParcelCode(a.shipmentNumber);
    return parsed.base == expected ? ParcelMatch.match : ParcelMatch.otherParcel;
  }
  return {a.shipmentNumber, a.number, a.orderNumber, if ((a.barcode ?? '').isNotEmpty) parseParcelBarcode(a.barcode!).base}.where((n) => n.isNotEmpty).map(normaliseParcelCode).contains(parsed.code) ? ParcelMatch.match : ParcelMatch.noMatch;
}

/// Whether [code] is this stop's parcel ([matchParcelCode]).
bool pickupCodeMatches(Assignment a, String code) => matchParcelCode(a, code) == ParcelMatch.match;
