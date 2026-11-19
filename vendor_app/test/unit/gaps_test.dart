import 'package:flutter_test/flutter_test.dart';
import 'package:nawy_core/nawy_core.dart';
import 'package:nawy_core/testing.dart';
import 'package:vendor_app/data/vendor_api.dart';
import 'package:vendor_app/l10n/strings.dart';

void main() {
  VendorApi apiWith(FakeTransport t) => VendorApi(ApiClient(baseUrl: 'http://x/api/v1', transport: t)..market = 'uae-en');

  test('support ticket defaults and fallbacks', () {
    final t = SupportTicket({'number': 'ST-1', 'category': 'payouts', 'created_at': '2026-11-01T08:00:00Z', 'can_reply': 1, 'rating': {'score': '4'}});
    expect(t.priority, 'normal');
    expect(t.categoryLabel, 'payouts');
    expect(t.canReply, isTrue);
    expect(t.canReopen, isFalse);
    expect(t.ratingScore, 4);
    expect(t.lastActivity, DateTime.utc(2026, 11, 1, 8).toLocal());
    expect(SupportTicket({'last_message_at': '2026-11-02T08:00:00Z', 'created_at': '2026-11-01T08:00:00Z'}).lastActivity, DateTime.utc(2026, 11, 2, 8).toLocal());
    expect(SupportTicket({}).lastActivity, isNull);
    expect(SupportTicket({}).messages, isEmpty);
  });

  test('support options: categories need a value, label falls back to it; max attachments default 5', () {
    final o = SupportOptions({
      'categories': [{'value': 'orders'}, {'value': '', 'label': 'Empty'}, {'label': 'No value'}, {'value': 'payouts', 'label': 'Payouts'}],
      'priorities': [{'label': 'no value'}, ''],
    });
    expect(o.categories, [(value: 'orders', label: 'orders'), (value: 'payouts', label: 'Payouts')]);
    expect(o.priorities.map((p) => p.value), ['low', 'normal', 'high']);
    expect(o.maxAttachments, 5);
    expect(SupportOptions({'max_attachments': '3'}).maxAttachments, 3);
  });

  test('draft without priority leaves it out; validation boundaries are inclusive', () {
    expect(const TicketDraft(category: 'orders', subject: 'Late', message: 'My order is late').json, {'category': 'orders', 'subject': 'Late', 'message': 'My order is late'});
    expect(TicketDraft.validate(category: 'orders', subject: 'abc', message: 'x' * 10), isNull);
    expect(TicketDraft.validate(category: 'orders', subject: 'x' * 160, message: 'x' * 5000), isNull);
    expect(TicketDraft.validate(category: '', subject: 'abc', message: 'x' * 10), 'support.errCategory');
    expect(replyError('x' * 5000), isNull);
  });

  test('stock boundaries and the low-stock threshold from the row', () {
    expect(StockAdjustment.validate(raw: '100000', remove: false, reason: 'restock', current: 0), isNull);
    expect(StockAdjustment.validate(raw: '', remove: false, reason: 'restock', current: 0), 'stock.errQuantity');
    expect(StockAdjustment.validate(raw: '1', remove: false, reason: null, current: 0), 'stock.errReason');
    expect(StockAdjustment.validate(raw: '1', remove: true, reason: 'correction', current: 0), 'stock.errInsufficient');
    expect(isLowStock({'stock': 10, 'low_stock_threshold': 10}), isTrue);
    expect(isLowStock({'stock': '2', 'low_stock_threshold': '1'}), isFalse);
    expect(isLowStock({}), isFalse);
  });

  test('seller agreement parsing tolerates 0/1 flags and a missing version', () {
    final a = SellerAgreement({'required': 1, 'signed': '0', 'can_sign': 'true', 'version': {'id': '4', 'title': 'Terms v4', 'text': 'Body'}});
    expect(a.blocking, isTrue);
    expect(a.canSign, isTrue);
    expect(a.versionId, 4);
    expect(a.title, 'Terms v4');
    final none = SellerAgreement({'required': false});
    expect(none.blocking, isFalse);
    expect(none.versionId, isNull);
    expect(none.text, '');
  });

  test('reopen and rate leave out blank notes; stock query sends low only when on', () async {
    final ticket = {'number': 'ST 1', 'status': 'open'};
    final t = FakeTransport({
      'POST ops/vendor/support/tickets/ST 1/reopen': ticket,
      'POST ops/vendor/support/tickets/ST 1/rating': ticket,
    });
    final api = apiWith(t);
    await api.reopenTicket('ST 1', message: '   ');
    await api.rateTicket('ST 1', 5, comment: ' Fast ');
    await api.stock(q: '', page: 2);
    await api.stock(low: true);
    expect(FakeTransport.bodyOf(t.requests[0]), isEmpty);
    expect(t.requests[0].uri.path, endsWith('/tickets/ST%201/reopen'));
    expect(FakeTransport.bodyOf(t.requests[1]), {'rating': 5, 'comment': 'Fast'});
    expect(t.requests[2].uri.query, 'page=2');
    expect(t.requests[3].uri.query, 'low=1&page=1');
  });

  test('every order filter and support status has an EN and AR label', () {
    for (final s in [...orderFilters.whereType<String>(), ...supportStatuses]) {
      expect(vendorEn['status.$s'], isNotNull, reason: s);
      expect(vendorAr['status.$s'], isNotNull, reason: s);
    }
  });
}
