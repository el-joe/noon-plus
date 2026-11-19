import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nawy_core/nawy_core.dart';
import 'package:nawy_core/testing.dart';
import 'package:vendor_app/app.dart';
import 'package:vendor_app/data/vendor_api.dart';
import 'package:vendor_app/l10n/strings.dart';
import 'package:vendor_app/screens/agreement.dart';
import 'package:vendor_app/screens/support.dart';

import '../helpers.dart';

const base = 'ops/vendor/support';

Map<String, dynamic> ticketJson({
  String number = 'TK-AE-000001',
  String status = 'in_progress',
  bool canReply = true,
  bool canReopen = false,
  bool canRate = false,
  Map<String, dynamic>? rating,
  bool detail = true,
}) =>
    {
      'number': number,
      'category': 'payouts',
      'category_label': 'Payouts',
      'subject': 'Payout for October is missing',
      'priority': 'high',
      'status': status,
      'status_label': status,
      'order': null,
      'shipment': null,
      'assigned': true,
      'reply_expected_by': '2026-11-18T12:00:00+04:00',
      'last_message_at': '2026-11-17T10:00:00+04:00',
      'can_reply': canReply,
      'can_reopen': canReopen,
      'reopen_until': canReopen ? '2026-11-25T10:00:00+04:00' : null,
      'can_rate': canRate,
      'rating': rating,
      'created_at': '2026-11-16T09:00:00+04:00',
      if (detail) ...{
        'messages': [
          {'id': 1, 'from': 'requester', 'mine': true, 'body': 'Our October payout has not arrived.', 'attachments': [], 'created_at': '2026-11-16T09:00:00+04:00'},
          {
            'id': 2,
            'from': 'support',
            'mine': false,
            'body': 'We are checking with finance.',
            'attachments': [
              {'name': 'statement.pdf', 'mime': 'application/pdf', 'size': 2048, 'url': 'https://api.test/signed/1'},
            ],
            'created_at': '2026-11-17T10:00:00+04:00',
          },
        ],
        'history': [
          {'from': null, 'to': 'open', 'by': 'vendor', 'at': '2026-11-16T09:00:00+04:00'},
          {'from': 'open', 'to': 'in_progress', 'by': 'admin', 'at': '2026-11-17T10:00:00+04:00'},
        ],
      },
    };

final optionsJson = {
  'categories': [
    {'value': 'selling', 'label': 'Selling on Nawy'},
    {'value': 'payouts', 'label': 'Payouts'},
  ],
  'priorities': [
    {'value': 'low', 'label': 'Low'},
    {'value': 'normal', 'label': 'Normal'},
    {'value': 'high', 'label': 'High'},
  ],
  'max_attachments': 5,
  'attachment_types': ['jpg', 'jpeg', 'png', 'webp', 'pdf'],
  'attachment_max_kb': 5120,
};

void main() {
  group('logic', () {
    test('ticket draft validation follows the API limits', () {
      expect(TicketDraft.validate(category: null, subject: 'Payout', message: 'Where is my payout?'), 'support.errCategory');
      expect(TicketDraft.validate(category: 'payouts', subject: ' a ', message: 'Where is my payout?'), 'support.errSubject');
      expect(TicketDraft.validate(category: 'payouts', subject: 'x' * 161, message: 'Where is my payout?'), 'support.errSubject');
      expect(TicketDraft.validate(category: 'payouts', subject: 'Payout', message: 'short'), 'support.errMessage');
      expect(TicketDraft.validate(category: 'payouts', subject: 'Payout', message: 'x' * 5001), 'support.errMessage');
      expect(TicketDraft.validate(category: 'payouts', subject: 'Payout', message: 'Where is my payout?'), isNull);
      expect(const TicketDraft(category: 'payouts', subject: ' Payout ', message: ' Where is it? ', priority: 'high').json,
          {'category': 'payouts', 'subject': 'Payout', 'message': 'Where is it?', 'priority': 'high'});
    });

    test('reply length is 2 to 5000', () {
      expect(replyError(' a '), 'support.errReply');
      expect(replyError('ok'), isNull);
      expect(replyError('x' * 5001), 'support.errReply');
    });

    test('priorities accept objects or plain strings', () {
      expect(SupportOptions(optionsJson).priorities.map((p) => p.value), ['low', 'normal', 'high']);
      expect(SupportOptions({'priorities': ['low', 'high']}).priorities.map((p) => p.value), ['low', 'high']);
      expect(SupportOptions({}).priorities.map((p) => p.value), ['low', 'normal', 'high']);
      expect(SupportOptions(optionsJson).categories.map((c) => c.label), ['Selling on Nawy', 'Payouts']);
    });

    test('status labels exist in EN and AR', () {
      for (final s in supportStatuses) {
        expect(vendorEn['status.$s'], isNotNull, reason: s);
        expect(vendorAr['status.$s'], isNotNull, reason: s);
      }
      expect(L10n('en', en: vendorEn, ar: vendorAr).status('waiting_customer'), 'Waiting for you');
      expect(L10n('ar', en: vendorEn, ar: vendorAr).status('resolved'), 'تم الحل');
    });
  });

  group('support list', () {
    testWidgets('lists tickets, filters by status and pages by last_page', (tester) async {
      final transport = FakeTransport({
        'GET $base/tickets': (HttpRequestData r) {
          final page = int.parse(r.uri.queryParameters['page'] ?? '1');
          final status = r.uri.queryParameters['status'];
          if (status == 'resolved') return FakeTransport.ok([], meta: {'total': 0, 'page': 1, 'per_page': 20, 'last_page': 1});
          return FakeTransport.ok([ticketJson(number: 'TK-AE-00000$page', detail: false)], meta: {'total': 2, 'page': page, 'per_page': 20, 'last_page': 2});
        },
      });
      await pumpScreen(tester, const SupportListScreen(), transport);
      expect(find.byKey(const Key('ticket-TK-AE-000001')), findsOneWidget);
      expect(find.text('Payout for October is missing'), findsOneWidget);
      expect(find.text('In progress'), findsWidgets);

      await tester.tap(find.byKey(const Key('support-more')));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('ticket-TK-AE-000002')), findsOneWidget);
      expect(find.byKey(const Key('support-more')), findsNothing);

      await tester.ensureVisible(find.byKey(const Key('support-filter-resolved')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('support-filter-resolved')));
      await tester.pumpAndSettle();
      expect(transport.requests.last.uri.queryParameters['status'], 'resolved');
      expect(find.text('No tickets here'), findsOneWidget);
    });

    testWidgets('Arabic status filter labels', (tester) async {
      final transport = FakeTransport({'GET $base/tickets': []});
      await pumpScreen(tester, const SupportListScreen(), transport, locale: 'ar');
      expect(find.text('بانتظار ردك'), findsOneWidget);
      expect(find.text('مغلقة'), findsOneWidget);
    });
  });

  group('new ticket', () {
    testWidgets('validates, sends the fields and opens the new ticket', (tester) async {
      final transport = FakeTransport({
        'GET $base/categories': optionsJson,
        'POST $base/tickets': (HttpRequestData _) => FakeTransport.ok(ticketJson(number: 'TK-AE-000009', status: 'open'), status: 201),
        'GET $base/tickets/TK-AE-000009': ticketJson(number: 'TK-AE-000009', status: 'open'),
      });
      await pumpScreen(tester, const NewTicketScreen(), transport);
      expect(find.byKey(const Key('ticket-attachments')), findsOneWidget);

      await tester.tap(find.byKey(const Key('ticket-submit')));
      await tester.pumpAndSettle();
      expect(find.text('Choose a category.'), findsOneWidget);
      expect(transport.sent('POST $base/tickets'), isEmpty);

      await tester.tap(find.byKey(const Key('ticket-category')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Payouts').last);
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('ticket-priority-high')));
      await tester.enterText(find.byKey(const Key('ticket-subject')), 'Payout missing');
      await tester.enterText(find.byKey(const Key('ticket-message')), 'Our October payout has not arrived.');
      await tester.tap(find.byKey(const Key('ticket-submit')));
      await tester.pumpAndSettle();

      expect(FakeTransport.bodyOf(transport.sent('POST $base/tickets').single),
          {'category': 'payouts', 'subject': 'Payout missing', 'message': 'Our October payout has not arrived.', 'priority': 'high'});
      expect(find.byType(TicketDetailScreen), findsOneWidget);
      expect(find.text('TK-AE-000009'), findsOneWidget);
    });

    testWidgets('shows the API field error', (tester) async {
      final transport = FakeTransport({
        'GET $base/categories': optionsJson,
        'POST $base/tickets': FakeTransport.error(422, 'validation_failed', 'The given data was invalid.', {'subject': ['The subject is too vague.']}),
      });
      await pumpScreen(tester, const NewTicketScreen(), transport);
      await tester.tap(find.byKey(const Key('ticket-category')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Selling on Nawy').last);
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const Key('ticket-subject')), 'Help');
      await tester.enterText(find.byKey(const Key('ticket-message')), 'Please help me with selling.');
      await tester.tap(find.byKey(const Key('ticket-submit')));
      await tester.pumpAndSettle();
      expect(find.text('The subject is too vague.'), findsWidgets);
      expect(FakeTransport.bodyOf(transport.sent('POST $base/tickets').single)['priority'], 'normal');
    });
  });

  group('ticket detail', () {
    testWidgets('shows the thread and history; reply posts the body and reloads', (tester) async {
      final transport = FakeTransport({
        'GET $base/tickets/TK-AE-000001': ticketJson(),
        'POST $base/tickets/TK-AE-000001/messages': (HttpRequestData _) => FakeTransport.ok(ticketJson(), status: 201),
      });
      await pumpScreen(tester, const TicketDetailScreen(number: 'TK-AE-000001'), transport);
      expect(find.text('Our October payout has not arrived.'), findsOneWidget);
      expect(find.text('We are checking with finance.'), findsOneWidget);
      expect(find.text('statement.pdf'), findsOneWidget);
      expect(find.textContaining('Nawy support'), findsOneWidget);
      expect(find.textContaining('Open → In progress'), findsOneWidget);
      expect(find.byKey(const Key('ticket-reopen')), findsNothing);
      expect(find.byKey(const Key('star-1')), findsNothing);

      await tester.tap(find.byKey(const Key('reply-send')));
      await tester.pumpAndSettle();
      expect(transport.sent('POST $base/tickets/TK-AE-000001/messages'), isEmpty);

      await tester.enterText(find.byKey(const Key('reply-body')), 'Any news?');
      await tester.tap(find.byKey(const Key('reply-send')));
      await tester.pumpAndSettle();
      expect(FakeTransport.bodyOf(transport.sent('POST $base/tickets/TK-AE-000001/messages').single), {'body': 'Any news?'});
      expect(transport.sent('GET $base/tickets/TK-AE-000001'), hasLength(2));
      expect(find.text('Reply sent'), findsOneWidget);
    });

    testWidgets('transition_refused shows the API message and reloads the ticket', (tester) async {
      final transport = FakeTransport({
        'GET $base/tickets/TK-AE-000001': ticketJson(),
        'POST $base/tickets/TK-AE-000001/messages': FakeTransport.error(422, 'transition_refused', 'This ticket is resolved. Reopen it to reply.'),
      });
      await pumpScreen(tester, const TicketDetailScreen(number: 'TK-AE-000001'), transport);
      await tester.enterText(find.byKey(const Key('reply-body')), 'Hello again');
      await tester.tap(find.byKey(const Key('reply-send')));
      await tester.pumpAndSettle();
      expect(find.text('This ticket is resolved. Reopen it to reply.'), findsOneWidget);
      expect(transport.sent('GET $base/tickets/TK-AE-000001'), hasLength(2));
    });

    testWidgets('resolved: no reply box; reopen with a note', (tester) async {
      var status = 'resolved';
      final transport = FakeTransport({
        'GET $base/tickets/TK-AE-000001': (HttpRequestData _) =>
            FakeTransport.ok(ticketJson(status: status, canReply: status != 'resolved', canReopen: status == 'resolved', canRate: status == 'resolved')),
        'POST $base/tickets/TK-AE-000001/reopen': (HttpRequestData _) {
          status = 'open';
          return FakeTransport.ok(ticketJson(status: 'open'));
        },
      });
      await pumpScreen(tester, const TicketDetailScreen(number: 'TK-AE-000001'), transport);
      expect(find.byKey(const Key('reply-body')), findsNothing);
      expect(find.byKey(const Key('ticket-resolved-note')), findsOneWidget);

      await tester.tap(find.byKey(const Key('ticket-reopen')));
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const Key('reopen-note')), 'Still not paid');
      await tester.tap(find.byKey(const Key('reopen-confirm')));
      await tester.pumpAndSettle();
      expect(FakeTransport.bodyOf(transport.sent('POST $base/tickets/TK-AE-000001/reopen').single), {'message': 'Still not paid'});
      expect(find.byKey(const Key('reply-body')), findsOneWidget);
      expect(find.byKey(const Key('ticket-reopen')), findsNothing);
    });

    testWidgets('rate a resolved ticket with stars and a comment', (tester) async {
      var rated = false;
      final transport = FakeTransport({
        'GET $base/tickets/TK-AE-000001': (HttpRequestData _) => FakeTransport.ok(ticketJson(
              status: 'resolved',
              canReply: false,
              canReopen: true,
              canRate: !rated,
              rating: rated ? {'score': 4, 'comment': 'Quick help', 'rated_at': '2026-11-18T09:00:00+04:00'} : null,
            )),
        'POST $base/tickets/TK-AE-000001/rating': (HttpRequestData _) {
          rated = true;
          return FakeTransport.ok(ticketJson(status: 'resolved'));
        },
      });
      await pumpScreen(tester, const TicketDetailScreen(number: 'TK-AE-000001'), transport);
      expect(tester.widget<FilledButton>(find.byKey(const Key('rating-send'))).onPressed, isNull);
      await tester.tap(find.byKey(const Key('star-4')));
      await tester.pump();
      await tester.enterText(find.byKey(const Key('rating-comment')), 'Quick help');
      await tester.ensureVisible(find.byKey(const Key('rating-send')));
      await tester.tap(find.byKey(const Key('rating-send')));
      await tester.pumpAndSettle();
      expect(FakeTransport.bodyOf(transport.sent('POST $base/tickets/TK-AE-000001/rating').single), {'rating': 4, 'comment': 'Quick help'});
      expect(find.text('Your rating: 4 of 5'), findsOneWidget);
      expect(find.byKey(const Key('star-1')), findsNothing);
    });

    testWidgets('closed: nothing to do', (tester) async {
      final transport = FakeTransport({'GET $base/tickets/TK-AE-000001': ticketJson(status: 'closed', canReply: false)});
      await pumpScreen(tester, const TicketDetailScreen(number: 'TK-AE-000001'), transport);
      expect(find.byKey(const Key('ticket-closed-note')), findsOneWidget);
      expect(find.byKey(const Key('reply-body')), findsNothing);
      expect(find.text('Closed'), findsOneWidget);
    });
  });

  group('access', () {
    testWidgets('support opens from the agreement screen before signing', (tester) async {
      final transport = FakeTransport({
        'GET ops/vendor/agreement': {'required': true, 'signed': false, 'can_sign': false, 'version': {'id': 4, 'title': 'Agreement', 'text': '...'}},
        'GET $base/tickets': [ticketJson(detail: false)],
      });
      final session = await testSession(transport);
      await tester.pumpWidget(VendorApp(session: session));
      await tester.pumpAndSettle();
      expect(find.byType(AgreementScreen), findsOneWidget);
      await tester.tap(find.byKey(const Key('agreement-support')));
      await tester.pumpAndSettle();
      expect(find.byType(SupportListScreen), findsOneWidget);
      expect(find.byKey(const Key('ticket-TK-AE-000001')), findsOneWidget);
    });

    testWidgets('support opens from Settings', (tester) async {
      final transport = FakeTransport({
        'GET ops/vendor/agreement': {'required': true, 'signed': true, 'can_sign': true, 'version': null},
        'GET ops/vendor/dashboard': {'kpis': {}},
        'GET $base/tickets': [],
      });
      final session = await testSession(transport);
      await tester.pumpWidget(VendorApp(session: session));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Settings').last);
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('open-support')));
      await tester.pumpAndSettle();
      expect(find.byType(SupportListScreen), findsOneWidget);
      expect(find.text('No tickets here'), findsOneWidget);
    });
  });
}
