import 'package:flutter_test/flutter_test.dart';
import 'package:marketer_app/data/marketer_api.dart';
import 'package:marketer_app/l10n/strings.dart';
import 'package:nawy_core/nawy_core.dart';
import 'package:nawy_core/testing.dart';

import '../helpers.dart';

void main() {
  test('commission total skips other currencies and rows without a readable commission', () {
    final rows = [
      conversionJson(1, commission: '10.25'),
      {'status': 'approved', 'commission': moneyJson('99.000', 'OMR')},
      {'status': 'approved', 'commission': null},
      {'status': 'approved', 'commission': '5.00'},
      {'status': 'approved'},
    ];
    expect(commissionTotal(rows, 'AED').amount, '10.25');
    expect(commissionTotal(rows, 'OMR').format(), 'OMR 99.000');
    expect(commissionTotal(const [], 'KWD').format(), 'KWD 0.000');
  });

  test('commission total with clawback rows follows the backend (only reversed is left out)', () {
    final rows = [conversionJson(1, status: 'clawback_pending', commission: '3.00'), conversionJson(2, status: 'recovered', commission: '2.00')];
    expect(commissionTotal(rows, 'AED').amount, '5.00');
  });

  test('conversion rate rounds half up and is not capped', () {
    expect(conversionRate(8, 1), 13);
    expect(conversionRate(-5, 2), 0);
    expect(conversionRate(2, 3), 150);
    expect(conversionRate(1000, 0), 0);
  });

  test('campaign status helpers', () {
    expect(isCampaignEnded(null), isFalse);
    for (final s in endedCampaignStatuses) {
      expect(isCampaignEnded(s), isTrue, reason: s);
    }
    expect(isCampaignEnded('paused'), isFalse);
    expect(campaignChipStatus('active'), 'active');
    expect(campaignChipStatus('cancelled'), 'cancelled');
  });

  test('every conversion and ended campaign status has an EN and AR label', () {
    for (final s in [...conversionStatuses, ...endedCampaignStatuses]) {
      expect(marketerEn['status.$s'], isNotNull, reason: s);
      expect(marketerAr['status.$s'], isNotNull, reason: s);
    }
  });

  test('conversions request sends the page', () async {
    final t = FakeTransport({'GET ops/marketer/conversions': FakeTransport.ok([conversionJson(1)], meta: {'page': 2, 'last_page': 3})});
    final res = await MarketerApi(ApiClient(baseUrl: 'http://x/api/v1', transport: t)..market = 'uae-en').conversions(page: 2);
    expect(t.requests.single.uri.query, 'page=2');
    expect(res.hasMore, isTrue);
    expect(res.data.single['id'], 1);
  });
}
