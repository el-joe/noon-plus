import 'package:flutter_test/flutter_test.dart';
import 'package:nawy_core/nawy_core.dart';

void main() {
  group('Money.lenient (QA-042)', () {
    test('reads a Money map with its own currency', () {
      final m = Money.lenient({'amount': '1240.5000', 'currency': 'OMR'}, 'AED')!;
      expect(m.currency, 'OMR');
      expect(m.amount, '1240.500');
    });

    test('reads the older string or number in the fallback currency', () {
      expect(Money.lenient('1240.0000', 'AED')!.format(), 'AED 1,240.00');
      expect(Money.lenient(12.5, 'KWD')!.amount, '12.500');
      expect(Money.lenient('0', 'AED')!.isZero, isTrue);
    });

    test('null or unreadable is null', () {
      expect(Money.lenient(null, 'AED'), isNull);
      expect(Money.lenient('abc', 'AED'), isNull);
      expect(Money.lenient('', 'AED'), isNull);
      expect(Money.lenient({'amount': '1'}, 'AED'), isNull);
    });
  });

  test('ApiException.isTransitionRefused covers transition_refused and not_allowed (422 only)', () {
    expect(ApiException(422, 'transition_refused', 'No').isTransitionRefused, isTrue);
    expect(ApiException(422, 'not_allowed', 'No').isTransitionRefused, isTrue);
    expect(ApiException(403, 'transition_refused', 'No').isTransitionRefused, isFalse);
    expect(ApiException(422, 'validation_failed', 'No').isTransitionRefused, isFalse);
  });

  test('new statuses get chip colours', () {
    expect(StatusChip.colors('paid').$2, NawyColors.textSuccess);
    expect(StatusChip.colors('resolved').$2, NawyColors.textSuccess);
    expect(StatusChip.colors('waiting_customer').$2, NawyColors.textBrand);
  });
}
