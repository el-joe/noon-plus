import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nawy_core/nawy_core.dart';
import 'package:nawy_core/testing.dart';

/// Ops-app documents (`ops/{role}/documents`): status, expiry warnings,
/// rejection reason, upload / re-upload by camera / gallery / PDF, download.
void main() {
  const base = 'ops/vendor/documents';

  Map<String, dynamic> upload(int id, int type, String status, {String? validUntil, int? days, bool soon = false, String? reason}) => {
        'id': id,
        'document_type_id': type,
        'type': 'x',
        'status': status,
        'valid_until': validUntil,
        'expires_in_days': days,
        'expiring_soon': soon,
        'rejection_reason': reason,
        'can_reupload': status == 'rejected' || status == 'expired',
        'original_name': 'licence.pdf',
        'mime': 'application/pdf',
        'size': 100,
        'uploaded_at': '2026-11-01T10:00:00+04:00',
      };

  Map<String, dynamic> listing({List<Map<String, dynamic>> uploads = const [], List<int> missing = const [1]}) => {
        'requirements': [
          {'id': 1, 'code': 'trade_licence', 'name': 'Trade licence', 'mandatory': true, 'has_expiry': true, 'max_mb': 5},
          {'id': 2, 'code': 'vat', 'name': 'VAT certificate', 'mandatory': false, 'has_expiry': false, 'max_mb': 2},
          {'id': 3, 'code': 'id', 'name': 'Owner ID', 'mandatory': true, 'has_expiry': true, 'max_mb': 10},
        ],
        'uploads': uploads,
        'missing': missing,
      };

  Future<void> pump(WidgetTester tester, FakeTransport transport, {MediaPicker? picker, String locale = 'en'}) async {
    tester.view.physicalSize = const Size(1080, 2400);
    tester.view.devicePixelRatio = 2.6;
    addTearDown(tester.view.reset);
    final session = Session(api: ApiClient(baseUrl: 'http://x/api/v1', transport: transport), store: MemorySecureStore({'country': 'uae', 'locale': locale, 'token': 't'}));
    await session.load();
    await tester.pumpWidget(SessionScope(
      session: session,
      child: L10nScope(
        l10n: L10n(locale),
        child: MaterialApp(
          theme: buildNawyTheme(),
          locale: Locale(locale),
          supportedLocales: const [Locale('en'), Locale('ar')],
          localizationsDelegates: GlobalMaterialLocalizations.delegates,
          home: PartnerDocumentsScreen(base: base, picker: picker),
        ),
      ),
    ));
    await tester.pumpAndSettle();
  }

  testWidgets('shows status, expiring-soon and missing banners, the rejection reason and the right buttons', (tester) async {
    await pump(tester, FakeTransport({
      'GET $base': listing(uploads: [
        upload(9, 3, 'approved', validUntil: '2026-12-10', days: 21, soon: true),
        upload(8, 2, 'rejected', reason: 'Blurred scan'),
      ]),
    }));

    expect(find.byKey(const Key('documents-missing-banner')), findsOneWidget);
    expect(find.byKey(const Key('documents-expiring-banner')), findsOneWidget);
    expect(find.text('Missing'), findsOneWidget);
    expect(find.text('Expires in 21 days — upload the renewed document.'), findsOneWidget);
    expect(find.text('Rejected: Blurred scan'), findsOneWidget);
    expect(find.text('Upload'), findsOneWidget);
    expect(find.text('Re-upload'), findsOneWidget);
    expect(find.text('Replace'), findsOneWidget);
    expect(find.byKey(const Key('document-view-3')), findsOneWidget);
    expect(find.byKey(const Key('document-view-1')), findsNothing);
  });

  testWidgets('re-uploads a rejected document from the gallery and reloads', (tester) async {
    var loads = 0;
    final transport = FakeTransport({
      'GET $base': (HttpRequestData _) {
        loads++;
        return FakeTransport.ok(listing(uploads: [upload(8, 2, loads > 1 ? 'pending' : 'rejected', reason: loads > 1 ? null : 'Blurred scan')]));
      },
      'POST $base': FakeTransport.ok(upload(8, 2, 'pending'), status: 201),
    });
    final picker = FakeMediaPicker(galleryResults: [
      [fakePhoto('vat.jpg')],
    ]);
    await pump(tester, transport, picker: picker);

    await tester.tap(find.byKey(const Key('document-upload-2')));
    await tester.pumpAndSettle();
    expect(find.text('Rejected: Blurred scan'), findsOneWidget);
    expect(find.byKey(const Key('document-expiry')), findsNothing);
    await tester.tap(find.byKey(const Key('document-gallery')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('document-submit')));
    await tester.pumpAndSettle();

    final text = latin1.decode(transport.sent('POST $base').single.body!);
    expect(text, contains('name="document_type_id"\r\n\r\n2\r\n'));
    expect(text, contains('filename="vat.jpg"'));
    expect(loads, 2);
    expect(find.text('Pending'), findsOneWidget);
    expect(find.text('Nawy is reviewing this document.'), findsOneWidget);
  });

  testWidgets('a PDF for a type with expiry needs the date; server errors show inline', (tester) async {
    final transport = FakeTransport({
      'GET $base': listing(),
      'POST $base': FakeTransport.error(422, 'validation_failed', 'Invalid', {'valid_until': ['The valid until must be a date after today.']}),
    });
    await pump(tester, transport, picker: FakeMediaPicker(documentResults: [fakePdfFile('licence.pdf')]));

    await tester.tap(find.byKey(const Key('document-upload-1')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('document-pdf')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('document-submit')));
    await tester.pumpAndSettle();
    expect(find.text('Enter the date the document expires.'), findsOneWidget);
    expect(transport.sent('POST $base'), isEmpty);

    await tester.tap(find.byKey(const Key('document-expiry')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('OK'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('document-submit')));
    await tester.pumpAndSettle();
    expect(find.text('The valid until must be a date after today.'), findsOneWidget);
  });

  testWidgets('downloads the own file and says where it was saved', (tester) async {
    final saved = <String>[];
    PlatformBridge.debugSaveDocument = (name, bytes) async {
      saved.add(name);
      return '/docs/$name';
    };
    addTearDown(() => PlatformBridge.debugSaveDocument = null);
    await pump(tester, FakeTransport({
      'GET $base': listing(uploads: [upload(9, 3, 'approved', validUntil: '2027-12-10', days: 300)]),
      'GET $base/9/file': (HttpRequestData _) => HttpResponseData(200, '', {'content-type': 'application/pdf', 'content-disposition': 'attachment; filename="id.pdf"'}, [1, 2, 3]),
    }));

    await tester.tap(find.byKey(const Key('document-view-3')));
    await tester.pumpAndSettle();
    expect(saved, ['id.pdf']);
    expect(find.text('Saved to /docs/id.pdf'), findsOneWidget);
  });

  testWidgets('Arabic labels', (tester) async {
    await pump(tester, FakeTransport({'GET $base': listing()}), locale: 'ar');
    expect(find.text('المستندات'), findsOneWidget);
    expect(find.text('مستندات مطلوبة ناقصة'), findsOneWidget);
  });
}
