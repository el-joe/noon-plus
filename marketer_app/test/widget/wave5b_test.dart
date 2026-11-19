import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:marketer_app/data/marketer_api.dart';
import 'package:marketer_app/l10n/strings.dart';
import 'package:marketer_app/screens/campaigns.dart';
import 'package:marketer_app/screens/conversions.dart';
import 'package:nawy_core/nawy_core.dart';
import 'package:nawy_core/testing.dart';

import '../helpers.dart';

Map<String, dynamic> campaign(int id, String status) => {'id': id, 'name': 'Campaign $id', 'status': status, 'commission': '8%', 'conversions': 1};

void main() {
  test('paid sits between released and reversed', () {
    final released = conversionStatuses.indexOf('released');
    expect(conversionStatuses[released + 1], 'paid');
    expect(conversionStatuses[released + 2], 'reversed');
    expect(L10n('en', en: marketerEn, ar: marketerAr).status('paid'), 'Paid');
    expect(L10n('ar', en: marketerEn, ar: marketerAr).status('paid'), 'مدفوعة');
    expect(L10n('en', en: marketerEn, ar: marketerAr).status('released'), isNot('Paid'));
  });

  test('paid conversions count towards the commission total', () {
    final rows = [conversionJson(1, status: 'paid', commission: '10.00'), conversionJson(2, status: 'reversed', commission: '5.00')];
    expect(commissionTotal(rows, 'AED').amount, '10.00');
  });

  test('completed is terminal like done', () {
    expect(isCampaignEnded('completed'), isTrue);
    expect(isCampaignEnded('done'), isTrue);
    expect(isCampaignEnded('active'), isFalse);
    expect(campaignChipStatus('completed'), 'done');
    expect(StatusChip.colors(campaignChipStatus('completed')), StatusChip.colors('done'));
    expect(L10n('en', en: marketerEn, ar: marketerAr).status('completed'), 'Completed');
    expect(L10n('ar', en: marketerEn, ar: marketerAr).status('completed'), 'مكتملة');
  });

  testWidgets('a paid conversion shows "Paid"', (tester) async {
    final transport = FakeTransport({'GET ops/marketer/conversions': FakeTransport.ok([conversionJson(1, status: 'paid')], meta: {'page': 1, 'last_page': 1})});
    await pumpScreen(tester, const ConversionsScreen(), transport);
    expect(find.text('Paid'), findsOneWidget);
  });

  testWidgets('a completed campaign shows "Completed" in the done colour (AR too)', (tester) async {
    final transport = FakeTransport({'GET ops/marketer/campaigns': [campaign(1, 'completed'), campaign(2, 'done')]});
    await pumpScreen(tester, const CampaignsScreen(), transport);
    expect(find.text('Completed'), findsOneWidget);
    expect(find.text('Ended'), findsOneWidget);
    final chip = tester.widget<StatusChip>(find.descendant(of: find.byKey(const Key('campaign-1')), matching: find.byType(StatusChip)));
    expect(chip.status, 'done');
  });

  testWidgets('Arabic labels for completed and paid', (tester) async {
    final transport = FakeTransport({'GET ops/marketer/campaigns': [campaign(1, 'completed')]});
    await pumpScreen(tester, const CampaignsScreen(), transport, locale: 'ar');
    expect(find.text('مكتملة'), findsOneWidget);
  });
}
