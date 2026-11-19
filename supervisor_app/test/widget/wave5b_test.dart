import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nawy_core/testing.dart';
import 'package:supervisor_app/data/supervisor_api.dart';
import 'package:supervisor_app/screens/failed.dart';

import '../helpers.dart';

Map<String, dynamic> row({String? lastFailedAt}) => {
      'id': 78, 'number': 'SH-78', 'sub_order_number': 'NW-AE-1000078-1', 'status': 'failed', 'failure_reason': 'not_home',
      'attempts': 3, 'max_attempts': 3, 'city': 'Dubai', 'rescheduled_for': null, 'last_failed_at': lastFailedAt, 'actions': <String>[],
    };

void main() {
  test('lastFailedAt reads the latest attempt time (QA-026)', () {
    expect(lastFailedAt(row(lastFailedAt: '2026-11-15T06:08:24+00:00')), DateTime.utc(2026, 11, 15, 6, 8, 24).toLocal());
    expect(lastFailedAt(row()), isNull);
    expect(lastFailedAt(row(lastFailedAt: 'soon')), isNull);
  });

  testWidgets('the failed list shows when the last attempt failed', (tester) async {
    final transport = FakeTransport({'GET ops/supervisor/failed': [row(lastFailedAt: '2026-11-15T06:08:24+00:00'), {...row(), 'id': 79}]});
    await pumpScreen(tester, const FailedScreen(), transport);
    final text = tester.widget<Text>(find.byKey(const Key('last-failed-78')));
    expect(text.data, startsWith('Last failed '));
    expect(find.byKey(const Key('last-failed-79')), findsNothing);
  });
}
