import 'dart:convert';

import 'package:delivery_app/screens/documents.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nawy_core/nawy_core.dart';
import 'package:nawy_core/testing.dart';

import '../helpers.dart';

/// Agent documents: status, expiry, rejection, upload by camera / photo / PDF.
void main() {
  Map<String, dynamic> documents({List<Map<String, dynamic>> uploads = const [], List<int> missing = const [1]}) => {
        'requirements': [
          {'id': 1, 'code': 'driving_licence', 'name': 'Driving licence', 'mandatory': true, 'has_expiry': true, 'max_mb': 5},
          {'id': 2, 'code': 'photo', 'name': 'Profile photo', 'mandatory': false, 'has_expiry': false, 'max_mb': 2},
          {'id': 3, 'code': 'emirates_id', 'name': 'Emirates ID', 'mandatory': true, 'has_expiry': true, 'max_mb': 10},
        ],
        'uploads': uploads,
        'missing': missing,
      };

  Map<String, dynamic> upload(int id, int type, String status, {String? validUntil, int? days, bool soon = false, String? reason}) => {
        'id': id,
        'document_type_id': type,
        'type': 'x',
        'status': status,
        'valid_until': validUntil,
        'expires_in_days': days,
        'expiring_soon': soon,
        'rejection_reason': reason,
        'original_name': 'f.pdf',
        'mime': 'application/pdf',
        'size': 100,
        'uploaded_at': '2026-11-01T10:00:00+04:00',
      };

  /// Text of the multipart body (field values and file headers).
  String bodyText(HttpRequestData r) => latin1.decode(r.body!);

  testWidgets('shows status, expiry warning, rejection reason, missing banner and the right button', (tester) async {
    final transport = FakeTransport({
      'GET delivery/me/documents': documents(uploads: [
        upload(9, 3, 'approved', validUntil: '2026-12-10', days: 21, soon: true),
        upload(8, 2, 'rejected', reason: 'Face not visible'),
        upload(5, 3, 'expired', validUntil: '2026-01-01'),
      ]),
    });
    await pumpScreen(tester, const DocumentsScreen(), transport);

    expect(find.byKey(const Key('documents-missing-banner')), findsOneWidget);
    expect(find.textContaining('Driving licence'), findsWidgets);
    expect(find.text('Missing'), findsOneWidget);
    expect(find.text('Expires in 21 days — upload the renewed document.'), findsOneWidget);
    expect(find.text('Valid until 2026-12-10'), findsOneWidget);
    expect(find.text('Rejected: Face not visible'), findsOneWidget);
    expect(find.text('Upload'), findsOneWidget);
    expect(find.text('Re-upload'), findsOneWidget);
    expect(find.text('Replace'), findsOneWidget, reason: 'the newest Emirates ID is approved');
    expect(find.textContaining('web panel'), findsNothing);
  });

  testWidgets('PDF upload with expiry date: required first, then sent as multipart and the list reloads', (tester) async {
    var loads = 0;
    final transport = FakeTransport({
      'GET delivery/me/documents': (HttpRequestData _) {
        loads++;
        return FakeTransport.ok(documents(uploads: loads > 1 ? [upload(10, 1, 'pending', validUntil: '2027-11-20')] : const [], missing: loads > 1 ? const [] : const [1]));
      },
      'POST delivery/me/documents': FakeTransport.ok(upload(10, 1, 'pending', validUntil: '2027-11-20'), status: 201),
    });
    final picker = FakeMediaPicker(documentResults: [fakePdfFile('licence.pdf')]);
    await pumpScreen(tester, DocumentsScreen(picker: picker), transport);

    await tester.tap(find.byKey(const Key('document-upload-1')));
    await tester.pumpAndSettle();

    // Nothing chosen: both errors, nothing sent.
    await tester.tap(find.byKey(const Key('document-submit')));
    await tester.pumpAndSettle();
    expect(find.text('Choose a photo or a PDF of the document.'), findsOneWidget);
    expect(find.text('Enter the date the document expires.'), findsOneWidget);
    expect(transport.sent('POST delivery/me/documents'), isEmpty);

    await tester.tap(find.byKey(const Key('document-pdf')));
    await tester.pumpAndSettle();
    expect(find.textContaining('licence.pdf'), findsOneWidget);
    expect(picker.fittingRequests.toSet(), {5 * 1024 * 1024}, reason: 'photos fit the type limit (max_mb 5)');

    // Still no date.
    await tester.tap(find.byKey(const Key('document-submit')));
    await tester.pumpAndSettle();
    expect(find.text('Enter the date the document expires.'), findsOneWidget);
    expect(transport.sent('POST delivery/me/documents'), isEmpty);

    await tester.tap(find.byKey(const Key('document-expiry')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('OK'));
    await tester.pumpAndSettle();
    expect(find.text('Enter the date the document expires.'), findsNothing);

    await tester.tap(find.byKey(const Key('document-submit')));
    await tester.pumpAndSettle();
    final sent = transport.sent('POST delivery/me/documents').single;
    expect(sent.headers['Content-Type'], startsWith('multipart/form-data'));
    final text = bodyText(sent);
    expect(text, contains('name="document_type_id"\r\n\r\n1\r\n'));
    expect(RegExp(r'name="valid_until"\r\n\r\n\d{4}-\d{2}-\d{2}\r\n').hasMatch(text), isTrue);
    expect(text, contains('name="file"; filename="licence.pdf"'));
    expect(text, contains('Content-Type: application/pdf'));

    expect(find.text('Document sent for review'), findsOneWidget);
    expect(loads, 2);
    expect(find.byKey(const Key('documents-missing-banner')), findsNothing);
    expect(find.text('Pending'), findsOneWidget);
  });

  testWidgets('photo upload for a type without expiry has no date field; server errors show inline', (tester) async {
    var attempts = 0;
    final transport = FakeTransport({
      'GET delivery/me/documents': documents(),
      'POST delivery/me/documents': (HttpRequestData _) {
        attempts++;
        return attempts == 1 ? FakeTransport.error(422, 'validation_failed', 'Invalid', {'file': ['The file may not be greater than 2048 kilobytes.']}) : FakeTransport.ok(upload(11, 2, 'pending'), status: 201);
      },
    });
    final picker = FakeMediaPicker(cameraResults: [fakePhoto('IMG_1.jpg')]);
    await pumpScreen(tester, DocumentsScreen(picker: picker), transport);

    await tester.tap(find.byKey(const Key('document-upload-2')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('document-expiry')), findsNothing);

    await tester.tap(find.byKey(const Key('document-camera')));
    await tester.pumpAndSettle();
    expect(picker.fullSizeRequests, [true]);

    await tester.tap(find.byKey(const Key('document-submit')));
    await tester.pumpAndSettle();
    expect(find.text('The file may not be greater than 2048 kilobytes.'), findsOneWidget);
    final text = bodyText(transport.sent('POST delivery/me/documents').first);
    expect(text, contains('Content-Type: image/jpeg'));
    expect(text, isNot(contains('valid_until')));

    await tester.tap(find.byKey(const Key('document-submit')));
    await tester.pumpAndSettle();
    expect(attempts, 2);
    expect(find.byKey(const Key('document-submit')), findsNothing, reason: 'back on the list');
  });

  testWidgets('a file over the type limit is refused before upload', (tester) async {
    final picker = FakeMediaPicker(documentResults: [fakePdfFile('big.pdf', size: 3 * 1024 * 1024)]);
    final transport = FakeTransport({'GET delivery/me/documents': documents()});
    await pumpScreen(tester, DocumentsScreen(picker: picker), transport);
    await tester.tap(find.byKey(const Key('document-upload-2')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('document-pdf')));
    await tester.pumpAndSettle();
    expect(find.text('big.pdf is larger than 2 MB.'), findsOneWidget);
    expect(find.textContaining('big.pdf ·'), findsNothing);
  });

  testWidgets('Arabic labels', (tester) async {
    final transport = FakeTransport({'GET delivery/me/documents': documents()});
    await pumpScreen(tester, const DocumentsScreen(), transport, locale: 'ar');
    expect(find.text('مستندات مطلوبة ناقصة'), findsOneWidget);
    expect(find.text('رفع'), findsNWidgets(3));
  });
}
