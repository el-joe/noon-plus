import 'dart:convert';
import 'dart:typed_data';

import 'package:delivery_app/app.dart';
import 'package:delivery_app/data/models.dart';
import 'package:delivery_app/screens/cant_deliver.dart';
import 'package:delivery_app/screens/code_match.dart';
import 'package:delivery_app/screens/my_day.dart';
import 'package:delivery_app/screens/proof.dart';
import 'package:delivery_app/screens/stop_detail.dart';
import 'package:delivery_app/ui/signature_pad.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nawy_core/nawy_core.dart';
import 'package:nawy_core/testing.dart';

import '../helpers.dart';

void main() {
  group('code match (ADR 15)', () {
    testWidgets('shows the big code, has no code input, and confirms with codes_match only', (tester) async {
      final transport = FakeTransport({'POST delivery/assignments/DA-1001/confirm': assignmentJson(status: 'delivered', code: '')});
      await pumpScreen(tester, CodeMatchScreen(assignment: Assignment(assignmentJson())), transport);

      expect(find.text("Huda's delivery code"), findsOneWidget);
      for (final digit in ['4', '8', '1', '7']) {
        expect(find.descendant(of: find.byKey(const Key('delivery-code')), matching: find.text(digit)), findsOneWidget);
      }
      expect(find.byType(TextField), findsNothing, reason: 'the agent never types the code');

      await tester.tap(find.byKey(const Key('codes-match')));
      await tester.pumpAndSettle();

      final sent = transport.sent('POST delivery/assignments/DA-1001/confirm').single;
      final body = FakeTransport.bodyOf(sent);
      expect(body, {'codes_match': true});
      expect(utf8.decode(sent.body!), isNot(contains('4817')));
    });

    testWidgets('codes that differ do not confirm', (tester) async {
      final transport = FakeTransport();
      await pumpScreen(tester, CodeMatchScreen(assignment: Assignment(assignmentJson())), transport);
      await tester.tap(find.byKey(const Key('codes-differ')));
      await tester.pumpAndSettle();
      expect(find.text("Don't hand over the parcel"), findsOneWidget);
      expect(transport.requests, isEmpty);
    });

    testWidgets('COD with required proof goes through the proof step and sends the signature', (tester) async {
      final transport = FakeTransport({'POST delivery/assignments/DA-1001/confirm': assignmentJson(status: 'delivered')});
      await pumpScreen(tester, CodeMatchScreen(assignment: Assignment(assignmentJson(cod: '312.00', proofRequired: true))), transport);

      await tester.tap(find.byKey(const Key('codes-match')));
      await tester.pumpAndSettle();
      expect(find.byType(ProofScreen), findsOneWidget);
      expect(find.text('A photo or a signature is required for this parcel.'), findsOneWidget);

      final complete = find.byKey(const Key('complete-delivery'));
      expect(tester.widget<FilledButton>(complete).onPressed, isNull);

      // Sign, collect the cash (with change), then complete.
      final pad = find.byKey(const Key('signature-pad'));
      await tester.drag(pad, const Offset(120, 30));
      await tester.pump();
      await tester.enterText(find.byKey(const Key('cash-received')), '400');
      await tester.pump();
      expect(find.text('Change to give back: AED 88.00'), findsOneWidget);
      expect(tester.widget<FilledButton>(complete).onPressed, isNull, reason: 'cash not ticked yet');
      await tester.ensureVisible(find.byKey(const Key('cash-collected')));
      await tester.tap(find.byKey(const Key('cash-collected')));
      await tester.pump();
      expect(tester.widget<FilledButton>(complete).onPressed, isNotNull);

      await tester.runAsync(() async {
        await tester.ensureVisible(complete);
        await tester.tap(complete);
        // PNG export runs on the engine; give it real time.
        for (var i = 0; i < 20 && transport.sent('POST delivery/assignments/DA-1001/confirm').isEmpty; i++) {
          await Future<void>.delayed(const Duration(milliseconds: 50));
          await tester.pump();
        }
      });
      await tester.pumpAndSettle();

      final body = FakeTransport.bodyOf(transport.sent('POST delivery/assignments/DA-1001/confirm').single);
      expect(body['codes_match'], isTrue);
      expect(body['signature_data'], startsWith('data:image/png;base64,'));
      expect(find.byType(ProofScreen), findsNothing);
    });

    testWidgets('no camera: photo falls back to the signature', (tester) async {
      await pumpScreen(tester, ProofScreen(assignment: Assignment(assignmentJson(proofRequired: true)), picker: FakeMediaPicker(hasCamera: false)), FakeTransport());
      await tester.tap(find.byKey(const Key('take-photo')));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('camera-unavailable')), findsOneWidget);
    });

    testWidgets('a camera photo satisfies the proof and is sent as multipart', (tester) async {
      final transport = FakeTransport({'POST delivery/assignments/DA-1001/confirm': assignmentJson(status: 'delivered')});
      final picker = FakeMediaPicker(cameraResults: [PickedMedia.fromBytes('IMG_0001.png', _tinyPng())]);
      await pumpScreen(tester, ProofScreen(assignment: Assignment(assignmentJson(proofRequired: true, proofType: 'photo')), picker: picker), transport);
      await tester.tap(find.byKey(const Key('take-photo')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('complete-delivery')));
      await tester.pumpAndSettle();
      final sent = transport.sent('POST delivery/assignments/DA-1001/confirm').single;
      expect(sent.headers['Content-Type'], startsWith('multipart/form-data; boundary='));
      final text = latin1.decode(sent.body!);
      expect(text, contains('name="codes_match"'));
      expect(text, contains('name="photo"; filename="proof.png"'));
      expect(text, contains('Content-Type: image/png'));
      expect(picker.fullSizeRequests, [true]);
    });

    testWidgets('a photo over the proof limit is refused with the reason', (tester) async {
      final picker = FakeMediaPicker(cameraResults: [fakePhoto('huge.jpg', size: 8192 * 1024 + 1)]);
      await pumpScreen(tester, ProofScreen(assignment: Assignment(assignmentJson(proofRequired: true, proofType: 'photo')), picker: picker), FakeTransport());
      await tester.tap(find.byKey(const Key('take-photo')));
      await tester.pumpAndSettle();
      expect(find.text('huge.jpg is larger than 8 MB.'), findsOneWidget);
      expect(find.byKey(const Key('proof-photo')), findsNothing);
      expect(tester.widget<FilledButton>(find.byKey(const Key('complete-delivery'))).onPressed, isNull);
    });
  });

  group("can't deliver", () {
    testWidgets('lists the configured reasons and posts the chosen one with the note', (tester) async {
      final transport = FakeTransport({'POST delivery/assignments/DA-1001/fail': assignmentJson(status: 'failed')});
      await pumpScreen(tester, CantDeliverScreen(assignment: Assignment(assignmentJson())), transport);

      expect(find.text('Customer not available'), findsOneWidget);
      expect(find.text('Cash not ready'), findsOneWidget);
      expect(tester.widget<FilledButton>(find.byKey(const Key('fail-submit'))).onPressed, isNull);

      await tester.tap(find.byKey(const Key('reason-refused')));
      await tester.enterText(find.byKey(const Key('fail-note')), 'Said he ordered by mistake');
      await tester.pump();
      await tester.tap(find.byKey(const Key('fail-submit')));
      await tester.pumpAndSettle();

      expect(FakeTransport.bodyOf(transport.sent('POST delivery/assignments/DA-1001/fail').single), {'reason': 'refused', 'note': 'Said he ordered by mistake'});
    });

    testWidgets('shows the API refusal', (tester) async {
      final transport = FakeTransport({'POST delivery/assignments/DA-1001/fail': FakeTransport.error(422, 'transition_refused', 'This delivery is already finished.')});
      await pumpScreen(tester, CantDeliverScreen(assignment: Assignment(assignmentJson())), transport);
      await tester.tap(find.byKey(const Key('reason-not_home')));
      await tester.pump();
      await tester.tap(find.byKey(const Key('fail-submit')));
      await tester.pumpAndSettle();
      expect(find.text('This delivery is already finished.'), findsOneWidget);
      expect(find.byType(CantDeliverScreen), findsOneWidget);
    });
  });

  group('signature export', () {
    testWidgets('strokes render to a PNG with dart:ui', (tester) async {
      final controller = SignatureController()
        ..begin(const Offset(10, 10))
        ..extend(const Offset(80, 40))
        ..extend(const Offset(150, 20));
      expect(controller.isEmpty, isFalse);
      late Uint8List png;
      late String dataUrl;
      await tester.runAsync(() async {
        png = await controller.toPng(const Size(200, 80), pixelRatio: 1);
        dataUrl = await controller.toDataUrl(const Size(200, 80));
      });
      expect(png.sublist(0, 8), [0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A]);
      // IHDR width/height (big-endian) at bytes 16..23.
      final header = ByteData.sublistView(png, 16, 24);
      expect(header.getUint32(0), 200);
      expect(header.getUint32(4), 80);
      expect(dataUrl, startsWith('data:image/png;base64,'));
      controller.clear();
      expect(controller.isEmpty, isTrue);
    });
  });

  group('stop detail', () {
    testWidgets('picked-up stop shows navigate / arrive / deliver / fail and COD', (tester) async {
      final transport = FakeTransport({'GET delivery/assignments/DA-1001': assignmentJson(cod: '312.00')});
      await pumpScreen(tester, const StopDetailScreen(number: 'DA-1001'), transport);
      expect(find.text('Huda Khalid'), findsOneWidget);
      expect(find.text('AED 312.00'), findsOneWidget);
      for (final a in ['navigate', 'arrive', 'deliver', 'fail']) {
        expect(find.byKey(Key('action-$a')), findsOneWidget, reason: a);
      }
      await tester.tap(find.byKey(const Key('action-deliver')));
      await tester.pumpAndSettle();
      expect(find.byType(CodeMatchScreen), findsOneWidget);
    });

    testWidgets('accepting an offer', (tester) async {
      final transport = FakeTransport({
        'GET delivery/assignments/DA-1001': assignmentJson(status: 'assigned'),
        'POST delivery/assignments/DA-1001/accept': assignmentJson(status: 'accepted'),
      });
      await pumpScreen(tester, const StopDetailScreen(number: 'DA-1001'), transport);
      await tester.tap(find.byKey(const Key('action-accept')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('offer-accept')));
      await tester.pumpAndSettle();
      expect(transport.sent('POST delivery/assignments/DA-1001/accept'), hasLength(1));
    });
  });

  group('app', () {
    testWidgets('sign in stores the token and opens My day with COD held', (tester) async {
      final transport = FakeTransport({
        'POST delivery/auth/login': {'token': 'tok-1', 'agent': {'name': 'Rashid Ali'}},
        'GET delivery/me': {'name': 'Rashid Ali', 'available': true, 'status': 'active', 'cod_in_hand': '1240.0000'},
        'GET delivery/assignments': [assignmentJson(cod: '312.00')],
      });
      final session = await testSession(transport, signedIn: false);
      await tester.pumpWidget(DeliveryApp(session: session));
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const Key('email')), 'rashid@nawy.test');
      await tester.enterText(find.byKey(const Key('password')), 'secret');
      await tester.tap(find.byKey(const Key('sign-in')));
      await tester.pumpAndSettle();

      expect(FakeTransport.bodyOf(transport.sent('POST delivery/auth/login').single)['email'], 'rashid@nawy.test');
      expect(await session.store.read('token'), 'tok-1');
      expect(find.byType(MyDayScreen), findsOneWidget);
      expect(find.text('Good day, Rashid'), findsOneWidget);
      expect(find.text('AED 1,240.00'), findsOneWidget);
      expect(find.text('Still to collect today: AED 312.00'), findsOneWidget);
    });

    testWidgets('wrong password shows the API message', (tester) async {
      final transport = FakeTransport({'POST delivery/auth/login': FakeTransport.error(422, 'invalid_credentials', 'The email or password is not right.')});
      final session = await testSession(transport, signedIn: false);
      await tester.pumpWidget(DeliveryApp(session: session));
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const Key('email')), 'a@b.c');
      await tester.enterText(find.byKey(const Key('password')), 'x');
      await tester.tap(find.byKey(const Key('sign-in')));
      await tester.pumpAndSettle();
      expect(find.text('The email or password is not right.'), findsOneWidget);
    });

    testWidgets('Arabic runs right to left with Arabic labels', (tester) async {
      final transport = FakeTransport({'GET delivery/assignments/DA-1001': assignmentJson()});
      await pumpScreen(tester, CodeMatchScreen(assignment: Assignment(assignmentJson())), transport, locale: 'ar');
      expect(find.text('الرمزان متطابقان'), findsOneWidget);
      expect(Directionality.of(tester.element(find.byKey(const Key('codes-match')))), TextDirection.rtl);
      // The digits stay left to right.
      expect(Directionality.of(tester.element(find.text('4'))), TextDirection.ltr);
    });

    testWidgets('availability toggle posts the new state', (tester) async {
      var available = true;
      final transport = FakeTransport({
        'GET delivery/me': (HttpRequestData _) => FakeTransport.ok({'name': 'Rashid', 'available': available, 'status': available ? 'active' : 'unavailable', 'cod_in_hand': '0'}),
        'POST delivery/me/availability': (HttpRequestData r) {
          available = FakeTransport.bodyOf(r)['available'] as bool;
          return FakeTransport.ok({'available': available});
        },
      });
      await pumpScreen(tester, const MyDayScreen(), transport);
      expect(find.text('Online'), findsOneWidget);
      await tester.tap(find.byKey(const Key('availability')));
      await tester.pumpAndSettle();
      expect(available, isFalse);
      expect(find.text('Offline'), findsOneWidget);
    });
  });
}

Uint8List _tinyPng() => base64Decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
