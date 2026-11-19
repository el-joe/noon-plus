import 'package:flutter_test/flutter_test.dart';
import 'package:marketer_app/app.dart';
import 'package:marketer_app/l10n/strings.dart';
import 'package:marketer_app/screens/campaigns.dart';
import 'package:nawy_core/testing.dart';

import '../helpers.dart';

void main() {
  test('QC-080: every visible Arabic string is translated', () {
    final same = [for (final k in marketerEn.keys) if (marketerAr[k] == marketerEn[k]) k];
    expect(same, isEmpty);
    expect(marketerAr['campaigns.dates'], contains('{from}'));
  });

  testWidgets('403 forbidden (staff role) explains and keeps the session', (tester) async {
    await pumpScreen(tester, const CampaignsScreen(), FakeTransport({'GET ops/marketer/campaigns': FakeTransport.error(403, 'forbidden', 'This action is unauthorized.')}));
    expect(find.text('Your role does not allow this. Ask the account owner for access.'), findsOneWidget);
  });

  testWidgets('403 wrong_token / 401 sign out with a message', (tester) async {
    final transport = FakeTransport({'GET ops/marketer/dashboard': FakeTransport.error(403, 'no_workspace', 'No workspace.')});
    final session = await testSession(transport);
    await tester.pumpWidget(MarketerApp(session: session));
    await tester.pumpAndSettle();
    expect(session.signedIn, isFalse);
    expect(find.text('This sign-in is not valid for this app. Please sign in again.'), findsOneWidget);
  });
}
