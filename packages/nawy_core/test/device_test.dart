import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nawy_core/nawy_core.dart';
import 'package:nawy_core/testing.dart';

void main() {
  group('SecureStore migration (D16 / P1)', () {
    late Directory dir;
    setUp(() => dir = Directory.systemTemp.createTempSync('nawy_store_'));
    tearDown(() => dir.deleteSync(recursive: true));

    test('copies the old JSON file into secure storage once, then deletes it', () async {
      final legacy = File('${dir.path}/store.json')..writeAsStringSync(jsonEncode({'token': 'abc', 'market': 'uae-en'}));
      File('${legacy.path}.tmp').writeAsStringSync('{}');
      final vault = FakeSecureVault();

      final store = await createDefaultStore('nawy_test', vault: vault, legacyFile: () async => legacy);

      expect(store, isA<KeychainSecureStore>());
      expect(await store.read('token'), 'abc');
      expect(vault.values, {'token': 'abc', 'market': 'uae-en'});
      expect(legacy.existsSync(), isFalse);
      expect(File('${legacy.path}.tmp').existsSync(), isFalse);

      // A second start finds no file and keeps the values.
      final again = await createDefaultStore('nawy_test', vault: vault, legacyFile: () async => legacy);
      expect(await again.read('market'), 'uae-en');
    });

    test('values already in secure storage win over the file', () async {
      final legacy = File('${dir.path}/store.json')..writeAsStringSync(jsonEncode({'token': 'old', 'locale': 'ar'}));
      final store = KeychainSecureStore(FakeSecureVault({'token': 'new'}));
      expect(await migrateLegacyStore(legacy, store), 1);
      expect(await store.read('token'), 'new');
      expect(await store.read('locale'), 'ar');
    });

    test('a corrupt file is deleted; a missing file is a no-op', () async {
      final legacy = File('${dir.path}/store.json')..writeAsStringSync('{not json');
      final store = MemorySecureStore();
      expect(await migrateLegacyStore(legacy, store), 0);
      expect(legacy.existsSync(), isFalse);
      expect(await migrateLegacyStore(legacy, store), 0);
    });

    test('falls back to memory when the platform has no secure storage', () async {
      final store = await createDefaultStore('nawy_test', vault: FakeSecureVault()..fail = true, legacyFile: () async => null);
      expect(store, isA<MemorySecureStore>());
    });

    test('Keychain store caches reads and deletes on null', () async {
      final vault = FakeSecureVault({'a': '1'});
      final store = KeychainSecureStore(vault);
      expect(await store.read('a'), '1');
      expect(await store.read('a'), '1');
      expect(vault.reads, 1);
      await store.delete('a');
      expect(vault.values, isEmpty);
      expect(await store.read('a'), isNull);
    });
  });

  group('links (P6 / P12)', () {
    tearDown(() => PlatformBridge.debugLaunch = null);

    test('tel: links keep + and digits only', () {
      expect(PlatformBridge.telUri('+971 50 123-4567').toString(), 'tel:+971501234567');
      expect(PlatformBridge.telUri('0096899112233').toString(), 'tel:+96899112233');
      expect(PlatformBridge.telUri('050 1234').toString(), 'tel:0501234');
      expect(PlatformBridge.telUri('—'), isNull);
      expect(PlatformBridge.telUri(null), isNull);
    });

    test('maps links per platform', () {
      expect(PlatformBridge.mapsUri('Marina, Dubai', android: true).toString(), 'geo:0,0?q=Marina%2C%20Dubai');
      expect(PlatformBridge.mapsUri('Marina', android: false, ios: true).host, 'maps.apple.com');
      expect(PlatformBridge.mapsUri('Marina', android: false, ios: false).host, 'www.google.com');
    });

    test('openUrl only launches http(s); callPhone launches tel:', () async {
      final launched = <Uri>[];
      PlatformBridge.debugLaunch = (uri) async {
        launched.add(uri);
        return true;
      };
      expect(await PlatformBridge.openUrl('javascript:alert(1)'), isFalse);
      expect(await PlatformBridge.openUrl('https://pay.example/3ds'), isTrue);
      expect(await PlatformBridge.callPhone('+968 9911 2233'), isTrue);
      expect(await PlatformBridge.callPhone(''), isFalse);
      expect(launched.map((u) => u.toString()), ['https://pay.example/3ds', 'tel:+96899112233']);
    });
  });

  group('upload rules', () {
    test('types are sniffed from the bytes', () {
      expect(sniffMime(fakeJpeg()), 'image/jpeg');
      expect(sniffMime(fakePng()), 'image/png');
      expect(sniffMime(utf8.encode('%PDF-1.7')), 'application/pdf');
      expect(sniffMime(utf8.encode('RIFF\x00\x00\x00\x00WEBPVP8 ')), 'image/webp');
      expect(sniffMime(utf8.encode('\x00\x00\x00\x18ftypheic')), 'image/heic');
      expect(sniffMime(const [1, 2, 3]), isNull);
      final heicNamedJpeg = PickedMedia.fromBytes('IMG_1.HEIC', fakeJpeg());
      expect(heicNamedJpeg.mimeType, 'image/jpeg');
      expect(heicNamedJpeg.uploadName, 'IMG_1.jpg');
      expect(heicNamedJpeg.part('photos[]').contentType, 'image/jpeg');
    });

    test('count, size and type limits', () {
      const rules = UploadRules.reviewPhotos;
      final existing = [for (var i = 0; i < 4; i++) fakePhoto('p$i.jpg')];
      final big = fakePhoto('big.jpg', size: 5120 * 1024 + 1);
      final heic = PickedMedia.fromBytes('x.heic', utf8.encode('\x00\x00\x00\x18ftypheic'));
      final result = rules.check(existing, [fakePhoto('ok.jpg'), big, heic, fakePhoto('extra.jpg')]);
      expect(result.accepted.map((f) => f.name), ['ok.jpg']);
      expect(result.rejected.map((r) => (r.$1.name, r.$2)), [('big.jpg', UploadRejection.tooBig), ('x.heic', UploadRejection.wrongType), ('extra.jpg', UploadRejection.tooMany)]);
      expect(UploadRules.avatar.maxSizeLabel, '2 MB');
      expect(UploadRules.avatar.check(const [], [PickedMedia.fromBytes('a.gif', utf8.encode('GIF89a'))]).rejected.single.$2, UploadRejection.wrongType);
      expect(UploadRules.supportAttachments.mimeTypes, contains('application/pdf'));
      expect(UploadRules.adPhotos(maxCount: 3).maxCount, 3);
      expect(UploadRules.adPhotos(maxCount: 3, maxKb: 500).maxBytes, 500 * 1024);
    });

    test('photo settings fit the size limit: quality first, then size, never under 640 px', () {
      // 8 MB / 5 MB / 2 MB limits keep the 2048 px @ 85 default.
      for (final kb in [8192, 5120, 2048]) {
        expect(photoSettingsFor(kb * 1024), (2048.0, 85), reason: '$kb KB');
      }
      final (d1, q1) = photoSettingsFor(1024 * 1024);
      expect(q1, 65);
      expect(d1, lessThan(2048));
      expect(estimatedJpegBytes(d1, q1), lessThanOrEqualTo(1024 * 1024 * 0.8));
      final (d2, q2) = photoSettingsFor(300 * 1024);
      expect((d2 >= 640, q2), (true, 65));
      expect(estimatedJpegBytes(d2, q2), lessThanOrEqualTo(300 * 1024 * 0.8));
      expect(photoSettingsFor(10 * 1024), (640.0, 65));
    });

    test('DeviceMediaPicker.fitting keeps itself when the default fits, else a smaller picker', () {
      final picker = DeviceMediaPicker();
      expect(identical(picker.fitting(8192 * 1024), picker), isTrue);
      final small = picker.fitting(500 * 1024) as DeviceMediaPicker;
      expect(small.quality, 65);
      expect(small.maxDimension, lessThan(2048));
    });
  });

  group('AttachmentPicker with a fake picker', () {
    Future<List<PickedMedia>> pump(WidgetTester tester, FakeMediaPicker picker, {List<PickedMedia> initial = const [], UploadRules rules = UploadRules.returnPhotos}) async {
      var files = initial;
      await tester.pumpWidget(MaterialApp(
        home: Scaffold(
          body: StatefulBuilder(
            builder: (context, setState) => AttachmentPicker(files: files, rules: rules, picker: picker, onChanged: (next) => setState(() => files = next)),
          ),
        ),
      ));
      return files;
    }

    testWidgets('camera and gallery add photos within the limit; remove works', (tester) async {
      final picker = FakeMediaPicker(cameraResults: [fakePhoto('cam.jpg')], galleryResults: [
        [fakePhoto('g1.jpg'), fakePhoto('g2.jpg', size: 6 * 1024 * 1024)],
      ]);
      await pump(tester, picker);
      expect(find.text('Up to 5 · 5 MB each'), findsOneWidget);

      await tester.tap(find.byKey(const Key('attach-camera')));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('attachment-0')), findsOneWidget);
      expect(find.text('1 of 5'), findsOneWidget);

      await tester.tap(find.byKey(const Key('attach-gallery')));
      await tester.pumpAndSettle();
      expect(picker.galleryLimits, [4]);
      // Every pick asks for photos sized to the field's limit.
      expect(picker.fittingRequests, [5120 * 1024, 5120 * 1024]);
      expect(find.byKey(const Key('attachment-1')), findsOneWidget);
      expect(find.byKey(const Key('attachment-2')), findsNothing);
      expect(find.text('g2.jpg is larger than 5 MB.'), findsOneWidget);

      await tester.tap(find.byKey(const Key('attachment-remove-0')));
      await tester.pumpAndSettle();
      expect(find.text('1 of 5'), findsOneWidget);
    });

    testWidgets('fields that take PDFs offer "Choose file (PDF)"; photo-only fields do not', (tester) async {
      final picker = FakeMediaPicker(documentResults: [fakePdfFile('invoice.pdf'), fakePdfFile('huge.pdf', size: 6 * 1024 * 1024)]);
      await pump(tester, picker);
      expect(find.byKey(const Key('attach-file')), findsNothing);

      await pump(tester, picker, rules: UploadRules.supportAttachments);
      await tester.tap(find.byKey(const Key('attach-file')));
      await tester.pumpAndSettle();
      expect(picker.documentExtensionRequests.single, unorderedEquals(['jpg', 'jpeg', 'png', 'webp', 'pdf']));
      expect(find.byKey(const Key('attachment-0')), findsOneWidget);
      expect(find.byIcon(Icons.picture_as_pdf_outlined), findsWidgets);
      expect(find.text('1 of 5'), findsOneWidget);

      await tester.tap(find.byKey(const Key('attach-file')));
      await tester.pumpAndSettle();
      expect(find.text('huge.pdf is larger than 5 MB.'), findsOneWidget);
      expect(find.byKey(const Key('attachment-1')), findsNothing);
    });

    test('document extensions follow the field types', () {
      expect(documentExtensionsFor({'application/pdf'}), ['pdf']);
      expect(documentExtensionsFor({'image/jpeg', 'image/png'}), ['jpg', 'jpeg', 'png']);
    });

    testWidgets('buttons are disabled when the limit is reached; no camera hides the button', (tester) async {
      final picker = FakeMediaPicker(hasCamera: false);
      await pump(tester, picker, initial: [fakePhoto('a.jpg')], rules: UploadRules.avatar);
      expect(find.byKey(const Key('attach-camera')), findsNothing);
      expect(tester.widget<ButtonStyleButton>(find.byKey(const Key('attach-gallery'))).onPressed, isNull);
    });
  });

  test('theme uses the bundled fonts', () {
    buildNawyTheme();
    expect(NawyText.body.fontFamily, 'packages/nawy_core/Inter');
    expect(NawyText.body.fontFamilyFallback!.first, 'packages/nawy_core/Cairo');
    buildNawyTheme(arabic: true);
    expect(NawyText.body.fontFamily, 'packages/nawy_core/Cairo');
    buildNawyTheme();
    for (final f in ['fonts/inter/Inter-Variable.ttf', 'fonts/inter/OFL.txt', 'fonts/cairo/Cairo-Variable.ttf', 'fonts/cairo/OFL.txt']) {
      expect(File(f).existsSync(), isTrue, reason: f);
    }
  });
}
