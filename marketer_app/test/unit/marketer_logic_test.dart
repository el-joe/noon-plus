import 'package:flutter_test/flutter_test.dart';
import 'package:marketer_app/data/marketer_api.dart';
import 'package:marketer_app/l10n/strings.dart';

import '../helpers.dart';

void main() {
  test('commission total leaves reversed conversions out', () {
    final rows = [conversionJson(1), conversionJson(2, commission: '12.50'), conversionJson(3, status: 'reversed', commission: '99.00')];
    expect(commissionTotal(rows, 'AED').amount, '52.50');
  });

  test('commission total keeps 3 decimals for OMR', () {
    final rows = [
      {'status': 'approved', 'commission': moneyJson('1.2345', 'OMR')},
      {'status': 'pending', 'commission': moneyJson('0.100', 'OMR')},
    ];
    expect(commissionTotal(rows, 'OMR').format(), 'OMR 1.335');
  });

  test('conversion rate', () {
    expect(conversionRate(0, 3), 0);
    expect(conversionRate(200, 7), 4);
    expect(conversionRate(3, 1), 33);
  });

  test('EN and AR tables have the same keys', () {
    expect(marketerAr.keys.toSet(), marketerEn.keys.toSet());
  });
}
