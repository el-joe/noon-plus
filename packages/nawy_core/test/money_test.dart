import 'package:nawy_core/nawy_core.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  group('decimals per currency', () {
    test('OMR, KWD, BHD and JOD have 3 decimals; others 2', () {
      for (final c in ['OMR', 'KWD', 'BHD', 'JOD', 'omr']) {
        expect(Money.decimalsOf(c), 3, reason: c);
      }
      for (final c in ['AED', 'SAR', 'QAR', 'EGP']) {
        expect(Money.decimalsOf(c), 2, reason: c);
      }
    });

    test('formats with the right number of decimals', () {
      expect(Money.parse('12.5', 'OMR').format(), 'OMR 12.500');
      expect(Money.parse('3', 'KWD').format(), 'KWD 3.000');
      expect(Money.parse('0.1', 'BHD').amount, '0.100');
      expect(Money.parse('7.25', 'JOD').amount, '7.250');
      expect(Money.parse('249', 'AED').format(), 'AED 249.00');
      expect(Money.parse('99.9', 'SAR').amount, '99.90');
    });
  });

  group('parsing', () {
    test('rounds half-up to the currency', () {
      expect(Money.parse('1.2345', 'OMR').amount, '1.235');
      expect(Money.parse('1.2344', 'OMR').amount, '1.234');
      expect(Money.parse('1.005', 'AED').amount, '1.01');
      expect(Money.parse('1.004', 'AED').amount, '1.00');
      expect(Money.parse('-1.005', 'AED').amount, '-1.01');
      expect(Money.parse('.5', 'AED').amount, '0.50');
    });

    test('reads the API {amount, currency} map', () {
      final m = Money.fromJson({'amount': '1759.20', 'currency': 'AED'})!;
      expect(m.minor, BigInt.from(175920));
      expect(Money.fromJson(null), isNull);
      expect(Money.fromJson({'amount': '1'}), isNull);
    });

    test('garbage parses to zero', () {
      expect(Money.parse('abc', 'AED').isZero, isTrue);
      expect(Money.parse('', 'OMR').isZero, isTrue);
    });
  });

  group('formatting', () {
    test('groups thousands and puts the code after the amount in Arabic', () {
      expect(Money.parse('1234567.5', 'AED').format(), 'AED 1,234,567.50');
      expect(Money.parse('1234.5', 'KWD').format(locale: 'ar'), '1,234.500 KWD');
      expect(Money.parse('-2500', 'AED').grouped, '-2,500.00');
      expect(formatMoney(null), '—');
      expect(formatMoney({'amount': '5', 'currency': 'OMR'}), 'OMR 5.000');
    });
  });

  group('arithmetic', () {
    test('adds, subtracts and multiplies without float drift', () {
      final a = Money.parse('0.1', 'AED');
      final b = Money.parse('0.2', 'AED');
      expect((a + b).amount, '0.30');
      expect((Money.parse('10.000', 'OMR') - Money.parse('0.001', 'OMR')).amount, '9.999');
      expect(Money.parse('1.333', 'KWD').times(3).amount, '3.999');
    });

    test('refuses mixed currencies', () {
      expect(() => Money.parse('1', 'AED') + Money.parse('1', 'OMR'), throwsArgumentError);
    });

    test('percent off and progress', () {
      expect(Money.parse('249', 'AED').percentOffFrom(Money.parse('399', 'AED')), 37);
      expect(Money.parse('1759.20', 'AED').percentOffFrom(Money.parse('2199.00', 'AED')), 20);
      expect(Money.parse('500', 'AED').percentOffFrom(Money.parse('400', 'AED')), 0);
      expect(Money.parse('50', 'AED').progressTo(Money.parse('200', 'AED')), 0.25);
      expect(Money.parse('300', 'AED').progressTo(Money.parse('200', 'AED')), 1);
    });

    test('equality and ordering', () {
      expect(Money.parse('1.50', 'AED'), Money.parse('1.5', 'AED'));
      expect(Money.parse('2', 'AED').compareTo(Money.parse('1.99', 'AED')), greaterThan(0));
      expect(Money.parse('1.5', 'AED').toJson(), {'amount': '1.50', 'currency': 'AED'});
    });
  });
}
