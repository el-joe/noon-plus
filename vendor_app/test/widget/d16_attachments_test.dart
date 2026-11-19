import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nawy_core/nawy_core.dart';
import 'package:nawy_core/testing.dart';
import 'package:vendor_app/data/vendor_api.dart';
import 'package:vendor_app/screens/support.dart';

import '../helpers.dart';
import 'support_test.dart' show base, optionsJson, ticketJson;

/// Owner-review D16 / P7: support ticket photos through image_picker.
void main() {
  late FakeMediaPicker picker;
  setUp(() => MediaPicker.current = picker = FakeMediaPicker());
  tearDown(() => MediaPicker.current = DeviceMediaPicker());

  test('attachment limits come from support/categories', () {
    final rules = SupportOptions({'max_attachments': 2, 'attachment_max_kb': 1024, 'attachment_types': ['jpg', 'png', 'pdf']}).attachmentRules;
    expect(rules.maxCount, 2);
    expect(rules.maxBytes, 1024 * 1024);
    expect(rules.mimeTypes, {'image/jpeg', 'image/png', 'application/pdf'});
    expect(SupportOptions(const {}).attachmentRules.mimeTypes, UploadRules.supportAttachments.mimeTypes);
  });

  testWidgets('new ticket with photos is sent as multipart attachments[]', (tester) async {
    picker.galleryResults = [
      [fakePhoto('a.jpg'), fakePhoto('b.png', png: true), fakePhoto('too-big.jpg', size: 5120 * 1024 + 1)],
    ];
    final transport = FakeTransport({
      'GET $base/categories': optionsJson,
      'POST $base/tickets': (HttpRequestData _) => FakeTransport.ok(ticketJson(number: 'TK-AE-000009', status: 'open'), status: 201),
      'GET $base/tickets/TK-AE-000009': ticketJson(number: 'TK-AE-000009', status: 'open'),
    });
    await pumpScreen(tester, const NewTicketScreen(), transport);
    await tester.tap(find.byKey(const Key('ticket-category')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Payouts').last);
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('ticket-subject')), 'Payout missing');
    await tester.enterText(find.byKey(const Key('ticket-message')), 'Our October payout has not arrived.');
    await tester.ensureVisible(find.byKey(const Key('attach-gallery')));
    await tester.tap(find.byKey(const Key('attach-gallery')));
    await tester.pumpAndSettle();
    expect(picker.galleryLimits, [5]);
    expect(find.text('too-big.jpg is larger than 5 MB.'), findsOneWidget);

    await tester.ensureVisible(find.byKey(const Key('ticket-submit')));
    await tester.tap(find.byKey(const Key('ticket-submit')));
    await tester.pumpAndSettle();

    final sent = transport.sent('POST $base/tickets').single;
    expect(sent.headers['Content-Type'], startsWith('multipart/form-data; boundary='));
    final body = latin1.decode(sent.body!);
    expect(body, contains('name="category"\r\n\r\npayouts'));
    expect(body, contains('name="attachments[]"; filename="a.jpg"\r\nContent-Type: image/jpeg'));
    expect(body, contains('name="attachments[]"; filename="b.png"\r\nContent-Type: image/png'));
    expect(body, isNot(contains('too-big')));
  });

  testWidgets('a reply can carry a camera photo; without photos it stays JSON', (tester) async {
    picker.cameraResults = [fakePhoto('shot.jpg')];
    final transport = FakeTransport({
      'GET $base/tickets/TK-AE-000001': ticketJson(),
      'POST $base/tickets/TK-AE-000001/messages': (HttpRequestData _) => FakeTransport.ok(ticketJson(), status: 201),
    });
    await pumpScreen(tester, const TicketDetailScreen(number: 'TK-AE-000001'), transport);
    await tester.enterText(find.byKey(const Key('reply-body')), 'Here is the receipt');
    await tester.ensureVisible(find.byKey(const Key('attach-camera')));
    await tester.tap(find.byKey(const Key('attach-camera')));
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.byKey(const Key('reply-send')));
    await tester.tap(find.byKey(const Key('reply-send')));
    await tester.pumpAndSettle();
    final first = latin1.decode(transport.sent('POST $base/tickets/TK-AE-000001/messages').single.body!);
    expect(first, contains('name="body"\r\n\r\nHere is the receipt'));
    expect(first, contains('name="attachments[]"; filename="shot.jpg"'));
    expect(find.byKey(const Key('attachment-0')), findsNothing);

    await tester.enterText(find.byKey(const Key('reply-body')), 'Any news?');
    await tester.tap(find.byKey(const Key('reply-send')));
    await tester.pumpAndSettle();
    expect(FakeTransport.bodyOf(transport.sent('POST $base/tickets/TK-AE-000001/messages').last), {'body': 'Any news?'});
  });

  testWidgets('a reply can carry a PDF from the device files (file_picker)', (tester) async {
    picker.documentResults = [fakePdfFile('invoice.pdf')];
    final transport = FakeTransport({
      'GET $base/tickets/TK-AE-000001': ticketJson(),
      'POST $base/tickets/TK-AE-000001/messages': (HttpRequestData _) => FakeTransport.ok(ticketJson(), status: 201),
    });
    await pumpScreen(tester, const TicketDetailScreen(number: 'TK-AE-000001'), transport);
    await tester.enterText(find.byKey(const Key('reply-body')), 'The invoice');
    await tester.ensureVisible(find.byKey(const Key('attach-file')));
    expect(find.text('Choose file (PDF)'), findsOneWidget);
    await tester.tap(find.byKey(const Key('attach-file')));
    await tester.pumpAndSettle();
    expect(picker.documentExtensionRequests.single, contains('pdf'));
    await tester.ensureVisible(find.byKey(const Key('reply-send')));
    await tester.tap(find.byKey(const Key('reply-send')));
    await tester.pumpAndSettle();
    final body = latin1.decode(transport.sent('POST $base/tickets/TK-AE-000001/messages').single.body!);
    expect(body, contains('name="attachments[]"; filename="invoice.pdf"\r\nContent-Type: application/pdf'));
  });
}
