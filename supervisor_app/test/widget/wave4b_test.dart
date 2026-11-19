import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nawy_core/nawy_core.dart';
import 'package:nawy_core/testing.dart';
import 'package:supervisor_app/app.dart';
import 'package:supervisor_app/screens/drivers.dart';

import '../helpers.dart';

final driver = {'id': 5, 'name': 'Rashid Ali', 'status': 'active', 'phone': null, 'vehicle': {'type': 'bike', 'plate': null}, 'open_assignments': 3, 'cod_in_hand': moneyJson('0.00'), 'last_ping_at': null};

void main() {
  testWidgets('a refused suspension shows the API reason in a dialog', (tester) async {
    final transport = FakeTransport({
      'GET ops/supervisor/drivers': [driver],
      'POST ops/supervisor/drivers/5/status': FakeTransport.error(422, 'not_allowed', 'This driver still has 3 open deliveries. Reassign them first.'),
    });
    await pumpScreen(tester, const DriversScreen(), transport);
    await tester.tap(find.byKey(const Key('suspend-5')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Submit'));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('suspend-refused')), findsOneWidget);
    expect(find.text("Can't suspend yet"), findsOneWidget);
    expect(find.text('This driver still has 3 open deliveries. Reassign them first.'), findsOneWidget);
  });

  testWidgets('invite driver: posts the invitation and shows "Invitation sent"', (tester) async {
    final transport = FakeTransport({
      'GET ops/supervisor/drivers': [driver],
      'POST ops/supervisor/drivers': FakeTransport.ok({'invited': true, 'email': 'new@driver.test'}, status: 202),
    });
    await pumpScreen(tester, const DriversScreen(), transport);
    await tester.tap(find.byKey(const Key('invite-driver')));
    await tester.pumpAndSettle();
    expect(tester.widget<FilledButton>(find.byKey(const Key('invite-send'))).onPressed, isNull);
    await tester.enterText(find.byKey(const Key('invite-name')), 'New Driver');
    await tester.enterText(find.byKey(const Key('invite-email')), 'New@Driver.test');
    await tester.enterText(find.byKey(const Key('invite-phone')), '0501234567');
    await tester.pump();
    await tester.tap(find.byKey(const Key('invite-send')));
    await tester.pumpAndSettle();
    expect(FakeTransport.bodyOf(transport.sent('POST ops/supervisor/drivers').single), {'name': 'New Driver', 'email': 'New@Driver.test', 'phone': '0501234567'});
    expect(find.byKey(const Key('invitation-sent')), findsOneWidget);
    expect(find.text('Invitation sent'), findsOneWidget);
    expect(find.textContaining('new@driver.test'), findsOneWidget);
  });

  testWidgets('invite errors stay on the sheet', (tester) async {
    final transport = FakeTransport({
      'GET ops/supervisor/drivers': [driver],
      'POST ops/supervisor/drivers': FakeTransport.error(422, 'validation_failed', 'Invalid.', {'phone': ['Enter a valid phone number.']}),
    });
    await pumpScreen(tester, const DriversScreen(), transport);
    await tester.tap(find.byKey(const Key('invite-driver')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('invite-name')), 'New Driver');
    await tester.enterText(find.byKey(const Key('invite-email')), 'new@driver.test');
    await tester.enterText(find.byKey(const Key('invite-phone')), '12');
    await tester.pump();
    await tester.tap(find.byKey(const Key('invite-send')));
    await tester.pumpAndSettle();
    expect(find.text('Enter a valid phone number.'), findsOneWidget);
    expect(find.byKey(const Key('invitation-sent')), findsNothing);
  });

  testWidgets('a staff role refused (403 forbidden) keeps the session and explains', (tester) async {
    final transport = FakeTransport({
      'GET ops/supervisor/drivers': FakeTransport.error(403, 'forbidden', 'This action is unauthorized.'),
    });
    await pumpScreen(tester, const DriversScreen(), transport);
    expect(find.text('Your role does not allow this. Ask the account owner for access.'), findsOneWidget);
    expect(tester.element(find.byType(DriversScreen)).session.signedIn, isTrue);
  });

  testWidgets('401 after 30 days returns to sign-in with a message', (tester) async {
    final transport = FakeTransport({'GET ops/supervisor/dashboard': FakeTransport.error(401, 'unauthenticated', 'Unauthenticated.')});
    final session = await testSession(transport);
    await tester.pumpWidget(SupervisorApp(session: session));
    await tester.pumpAndSettle();
    expect(session.signedIn, isFalse);
    expect(find.text('Your session has expired. Please sign in again.'), findsOneWidget);
  });
}
