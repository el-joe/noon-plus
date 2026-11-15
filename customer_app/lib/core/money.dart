import 'json.dart';

/// Money with no floating point: an integer number of minor units plus the
/// currency (MASTER_PLAN §4a; backend `MoneyFactory`). OMR, KWD, BHD and JOD
/// have 3 decimals, every other market currency has 2. Rounding is half-up,
/// like the backend (brick/money RoundingMode::HALF_UP).
class Money implements Comparable<Money> {
  const Money._(this.minor, this.currency);

  /// Integer minor units (fils / baisa / cents).
  final BigInt minor;
  final String currency;

  static const threeDecimalCurrencies = {'OMR', 'KWD', 'BHD', 'JOD'};

  static int decimalsOf(String currency) => threeDecimalCurrencies.contains(currency.toUpperCase()) ? 3 : 2;

  int get decimals => decimalsOf(currency);

  factory Money.zero(String currency) => Money._(BigInt.zero, currency.toUpperCase());

  factory Money.minorUnits(BigInt minor, String currency) => Money._(minor, currency.toUpperCase());

  /// Parse a decimal string ("249.00", "-1.2345", "12") rounding half-up to
  /// the currency's decimals. Returns zero for anything unparsable.
  factory Money.parse(String amount, String currency) {
    final code = currency.toUpperCase();
    final decimals = decimalsOf(code);
    final match = RegExp(r'^\s*(-?)(\d*)(?:\.(\d*))?\s*$').firstMatch(amount);
    if (match == null || ((match.group(2) ?? '').isEmpty && (match.group(3) ?? '').isEmpty)) {
      return Money._(BigInt.zero, code);
    }
    final negative = match.group(1) == '-';
    final whole = match.group(2)!.isEmpty ? '0' : match.group(2)!;
    final frac = match.group(3) ?? '';
    final kept = frac.padRight(decimals, '0').substring(0, decimals);
    var minor = BigInt.parse(whole) * BigInt.from(10).pow(decimals) + BigInt.parse(kept.isEmpty ? '0' : kept);
    if (frac.length > decimals && frac.codeUnitAt(decimals) >= 0x35 /* '5' */) {
      minor += BigInt.one;
    }
    return Money._(negative ? -minor : minor, code);
  }

  /// `{amount, currency}` from the API, or null.
  static Money? fromJson(dynamic json) {
    if (json is! Map) return null;
    final map = json.cast<String, dynamic>();
    final amount = map.s('amount');
    final currency = map.s('currency');
    if (amount == null || currency == null) return null;
    return Money.parse(amount, currency);
  }

  bool get isZero => minor == BigInt.zero;
  bool get isNegative => minor < BigInt.zero;

  void _same(Money other) {
    if (other.currency != currency) {
      throw ArgumentError('Currency mismatch: $currency vs ${other.currency}');
    }
  }

  Money operator +(Money other) {
    _same(other);
    return Money._(minor + other.minor, currency);
  }

  Money operator -(Money other) {
    _same(other);
    return Money._(minor - other.minor, currency);
  }

  Money times(int quantity) => Money._(minor * BigInt.from(quantity), currency);

  /// Percent off between a was-price and this price, rounded down (e.g. 37).
  int percentOffFrom(Money was) {
    _same(was);
    if (was.minor <= BigInt.zero || was.minor <= minor) return 0;
    return (((was.minor - minor) * BigInt.from(100)) ~/ was.minor).toInt();
  }

  /// 0..1 progress of this amount towards [target] (free-delivery bar).
  double progressTo(Money target) {
    _same(target);
    if (target.minor <= BigInt.zero) return 1;
    if (minor >= target.minor) return 1;
    if (minor <= BigInt.zero) return 0;
    return (minor * BigInt.from(1000) ~/ target.minor).toInt() / 1000;
  }

  /// Plain decimal amount ("1249.500"), the API's own format.
  String get amount {
    final negative = minor.isNegative;
    final digits = minor.abs().toString().padLeft(decimals + 1, '0');
    final whole = digits.substring(0, digits.length - decimals);
    final frac = digits.substring(digits.length - decimals);
    return '${negative ? '-' : ''}$whole${decimals > 0 ? '.$frac' : ''}';
  }

  /// Grouped amount without the currency ("1,249.500").
  String get grouped {
    final raw = amount;
    final negative = raw.startsWith('-');
    final parts = (negative ? raw.substring(1) : raw).split('.');
    final whole = parts[0];
    final buffer = StringBuffer();
    for (var i = 0; i < whole.length; i++) {
      if (i > 0 && (whole.length - i) % 3 == 0) buffer.write(',');
      buffer.write(whole[i]);
    }
    return '${negative ? '-' : ''}$buffer${parts.length > 1 ? '.${parts[1]}' : ''}';
  }

  /// "AED 1,249.00" / "OMR 12.500". Arabic keeps Western digits (as the web
  /// storefront does) and puts the code after the amount.
  String format({String locale = 'en'}) => locale == 'ar' ? '$grouped $currency' : '$currency $grouped';

  Json toJson() => {'amount': amount, 'currency': currency};

  @override
  int compareTo(Money other) {
    _same(other);
    return minor.compareTo(other.minor);
  }

  @override
  bool operator ==(Object other) => other is Money && other.minor == minor && other.currency == currency;

  @override
  int get hashCode => Object.hash(minor, currency);

  @override
  String toString() => format();
}

/// Format an API money map, or a dash when it is null.
String formatMoney(dynamic json, {String locale = 'en', String empty = '—'}) => Money.fromJson(json)?.format(locale: locale) ?? empty;
