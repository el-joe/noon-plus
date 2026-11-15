import 'package:flutter_test/flutter_test.dart';
import 'package:supervisor_app/data/supervisor_api.dart';
import 'package:supervisor_app/l10n/strings.dart';

void main() {
  test('reschedule dates are Y-m-d with zero padding', () {
    expect(ymd(DateTime(2026, 3, 7)), '2026-03-07');
    expect(ymd(DateTime(2026, 11, 15, 23, 59)), '2026-11-15');
  });

  test('attempts label and whether another attempt is allowed', () {
    expect(attemptsLabel({'attempts': 2, 'max_attempts': 3}), '2/3');
    expect(attemptsLeft({'attempts': 2, 'max_attempts': 3}), isTrue);
    expect(attemptsLeft({'attempts': 3, 'max_attempts': 3}), isFalse);
    expect(attemptsLabel({}), '0/0');
  });

  test('EN and AR tables have the same keys', () {
    expect(supervisorAr.keys.toSet(), supervisorEn.keys.toSet());
  });
}
