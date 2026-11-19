import 'package:flutter_test/flutter_test.dart';
import 'package:nawy_core/nawy_core.dart';
import 'package:nawy_core/testing.dart';
import 'package:supervisor_app/data/supervisor_api.dart';
import 'package:supervisor_app/l10n/strings.dart';

void main() {
  SupervisorApi apiWith(FakeTransport t) => SupervisorApi(ApiClient(baseUrl: 'http://x/api/v1', transport: t)..market = 'uae-en');

  test('attempts read numeric strings; no max means no attempt left', () {
    expect(attemptsLabel({'attempts': '1', 'max_attempts': '3'}), '1/3');
    expect(attemptsLeft({'attempts': '1', 'max_attempts': '3'}), isTrue);
    expect(attemptsLeft({'attempts': 0}), isFalse);
    expect(attemptsLeft({'attempts': 4, 'max_attempts': 3}), isFalse);
  });

  test('ymd pads every part and ignores the time of day', () {
    expect(ymd(DateTime(987, 1, 2)), '0987-01-02');
    expect(ymd(DateTime(2026, 12, 31, 23, 59, 59)), '2026-12-31');
  });

  test('lastFailedAt tolerates a missing or non-string value', () {
    expect(lastFailedAt({}), isNull);
    expect(lastFailedAt({'last_failed_at': 12}), isNull);
    expect(lastFailedAt({'last_failed_at': '2026-11-15T06:08:24Z'})!.isUtc, isFalse);
  });

  test('invite and status bodies leave out empty optionals; failed actions default to an empty body', () async {
    final t = FakeTransport({
      'POST ops/supervisor/drivers': {'invited': true},
      'POST ops/supervisor/drivers/4/status': {'status': 'active'},
      'POST ops/supervisor/failed/78/returned': {'status': 'returned'},
      'POST ops/supervisor/failed/78/reschedule': {'status': 'rescheduled'},
    });
    final api = apiWith(t);
    await api.inviteDriver(name: 'Omar', email: 'omar@x.test');
    await api.setDriverStatus(4, 'active');
    await api.failedAction(78, 'returned');
    await api.failedAction(78, 'reschedule', {'date': ymd(DateTime(2026, 11, 20))});
    expect(FakeTransport.bodyOf(t.requests[0]), {'name': 'Omar', 'email': 'omar@x.test'});
    expect(FakeTransport.bodyOf(t.requests[1]), {'status': 'active'});
    expect(FakeTransport.bodyOf(t.requests[2]), isEmpty);
    expect(FakeTransport.bodyOf(t.requests[3]), {'date': '2026-11-20'});
  });

  test('shipment and driver statuses have EN and AR labels', () {
    for (final s in ['assigned', 'accepted', 'picked_up', 'label_created', 'failed', 'rescheduled', 'returning_to_origin', 'returned', 'active', 'unavailable', 'suspended', 'pending_documents']) {
      expect(supervisorEn['status.$s'], isNotNull, reason: s);
      expect(supervisorAr['status.$s'], isNotNull, reason: s);
    }
    expect(L10n('ar', en: supervisorEn, ar: supervisorAr).status('suspended'), 'موقوف');
  });
}
