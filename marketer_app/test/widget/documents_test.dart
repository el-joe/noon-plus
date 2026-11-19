import 'package:marketer_app/screens/shell.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nawy_core/nawy_core.dart';
import 'package:nawy_core/testing.dart';

import '../helpers.dart';

/// Settings → Documents (`ops/marketer/documents`, shared [PartnerDocumentsScreen]).
void main() {
  testWidgets('opens the workspace documents from Settings with status, expiry and rejection', (tester) async {
    final transport = FakeTransport({
      'GET ops/marketer/documents': {
        'requirements': [
          {'id': 1, 'code': 'licence', 'name': 'Emirates ID', 'mandatory': true, 'has_expiry': true, 'max_mb': 5},
          {'id': 2, 'code': 'other', 'name': 'Other paper', 'mandatory': false, 'has_expiry': false, 'max_mb': 2},
        ],
        'uploads': [
          {'id': 4, 'document_type_id': 1, 'status': 'approved', 'valid_until': '2026-12-01', 'expires_in_days': 12, 'expiring_soon': true, 'rejection_reason': null},
          {'id': 5, 'document_type_id': 2, 'status': 'rejected', 'valid_until': null, 'expires_in_days': null, 'expiring_soon': false, 'rejection_reason': 'Unreadable'},
        ],
        'missing': <int>[],
      },
    });
    await pumpScreen(tester, const MarketerShell(), transport);

    await tester.tap(find.byIcon(Icons.settings_outlined));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('open-documents')));
    await tester.pumpAndSettle();

    expect(transport.sent('GET ops/marketer/documents'), isNotEmpty);
    expect(find.text('Emirates ID'), findsWidgets);
    expect(find.text('Expires in 12 days — upload the renewed document.'), findsOneWidget);
    expect(find.text('Rejected: Unreadable'), findsOneWidget);
    expect(find.text('Re-upload'), findsOneWidget);
    expect(find.byKey(const Key('documents-expiring-banner')), findsOneWidget);
  });
}
