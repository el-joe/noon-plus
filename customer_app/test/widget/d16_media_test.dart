import 'package:customer_app/core/platform/platform_bridge.dart';
import 'package:customer_app/features/page_builder/blocks.dart';
import 'package:customer_app/routing/guards.dart';
import 'package:customer_app/ui/widgets/inline_video.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nawy_core/device.dart' show MediaPicker;
import 'package:nawy_core/testing.dart' show FakeMediaPicker, fakePhoto;
import 'package:video_player/video_player.dart';

import '../helpers.dart';

/// A player whose stream cannot be opened (no HLS support, network…).
class _FailingController extends VideoPlayerController {
  _FailingController(super.url) : super.networkUrl();

  @override
  Future<void> initialize() async => throw PlatformException(code: 'VideoError', message: 'cannot open');
}

/// Removes SnackBars that would sit over the submit button.
Future<void> clearToasts(WidgetTester tester) async {
  for (final m in tester.stateList<ScaffoldMessengerState>(find.byType(ScaffoldMessenger))) {
    m.removeCurrentSnackBar();
    m.clearSnackBars();
  }
  await tester.pump();
}

void main() {
  final opened = <String>[];
  late MediaPicker realPicker;
  setUp(() {
    opened.clear();
    realPicker = MediaPicker.current;
    PlatformBridge.debugOpenUrl = (url) async {
      opened.add(url);
      return true;
    };
  });
  tearDown(() {
    MediaPicker.current = realPicker;
    PlatformBridge.debugOpenUrl = null;
    InlineVideo.controllerFactory = defaultVideoController;
  });

  testWidgets('review: photos go as photos[] multipart with the other fields; the 5-photo limit holds', (tester) async {
    final picker = FakeMediaPicker(galleryResults: [
      [for (var i = 0; i < 4; i++) fakePhoto('p$i.jpg')],
      [fakePhoto('p4.jpg'), fakePhoto('p5.jpg')],
    ]);
    MediaPicker.current = picker;
    final (state, transport) = await makeState(token: 'tok', routes: {
      'GET me/reviewable-items': [
        {'order_item_id': 11, 'title': 'Earbuds', 'product': {'title': 'SoundMax Earbuds'}},
      ],
      'POST reviews': FakeTransport.ok({'id': 1, 'status': 'pending'}, status: 201),
    });
    await pumpApp(tester, state);
    await pushRoute(tester, Routes.writeReview, {'order_item_id': 11});
    await settle(tester);
    await tester.tap(find.byKey(const ValueKey('star-4')));
    await tester.enterText(find.byType(TextField).at(0), 'Great');
    await tester.enterText(find.byType(TextField).at(1), 'Clear sound and long battery.');
    await tester.ensureVisible(find.byKey(const Key('attach-gallery')));
    await tester.tap(find.byKey(const Key('attach-gallery')));
    await settle(tester);
    await tester.tap(find.byKey(const Key('attach-gallery')));
    await settle(tester);
    expect(picker.galleryLimits, [5, 1]);
    expect(find.text('You can add up to 5.'), findsOneWidget);
    expect(find.text('5 of 5'), findsOneWidget);

    await clearToasts(tester);
    await tester.ensureVisible(find.text('Submit review').last);
    await tester.tap(find.text('Submit review').last);
    await settle(tester);
    final body = MultipartBody.of(transport.sent('POST reviews').single);
    expect(body.fields, {'order_item_id': '11', 'rating': '4', 'title': 'Great', 'body': 'Clear sound and long battery.'});
    expect(body.files.length, 5);
    expect(body.files.map((f) => f.$1).toSet(), {'photos[]'});
    expect(body.files.first.$3, 'image/jpeg');
    expect(transport.sent('POST reviews').single.headers['Authorization'], 'Bearer tok');
  });

  testWidgets('return: items as bracket fields plus photos[]; a too-large photo is refused', (tester) async {
    MediaPicker.current = FakeMediaPicker(cameraResults: [fakePhoto('big.jpg', size: 5120 * 1024 + 10), fakePhoto('box.jpg')]);
    final (state, transport) = await makeState(token: 'tok', routes: {
      'POST returns': FakeTransport.ok({'number': 'RT-1'}, status: 201),
      'GET returns/RT-1': {'number': 'RT-1', 'status': 'requested', 'items': []},
    });
    await pumpApp(tester, state);
    await pushRoute(tester, Routes.returnNew, {
      'shipment': 'SH-1',
      'order': {
        'shipments': [
          {
            'number': 'SH-1',
            'items': [
              {'id': 21, 'title': 'Earbuds', 'quantity': 2},
            ],
          },
        ],
      },
    });
    await settle(tester);
    await tester.tap(find.byIcon(Icons.add).first);
    await tester.tap(find.text('Defective'));
    await tester.ensureVisible(find.byKey(const Key('attach-camera')));
    await tester.tap(find.byKey(const Key('attach-camera')));
    await settle(tester);
    expect(find.text('big.jpg is larger than 5 MB.'), findsOneWidget);
    await tester.tap(find.byKey(const Key('attach-camera')));
    await settle(tester);
    await clearToasts(tester);
    await tester.ensureVisible(find.byType(FilledButton).last);
    await tester.tap(find.byType(FilledButton).last);
    await settle(tester);
    final body = MultipartBody.of(transport.sent('POST returns').single);
    expect(body.fields['shipment'], 'SH-1');
    expect(body.fields['items[0][order_item_id]'], '21');
    expect(body.fields['items[0][quantity]'], '1');
    expect(body.fields['reason'], 'defective');
    expect(body.fields.containsKey('comment'), isFalse);
    expect(body.files.single.$1, 'photos[]');
    expect(body.files.single.$2, 'box.jpg');
  });

  testWidgets('support: new ticket and reply send attachments[]', (tester) async {
    MediaPicker.current = FakeMediaPicker(galleryResults: [
      [fakePhoto('receipt.png', png: true)],
      [fakePhoto('screen.jpg')],
    ]);
    final ticket = {
      'number': 'TK-1',
      'subject': 'Charged twice',
      'status': 'open',
      'category': 'payment',
      'priority': 'normal',
      'can_reply': true,
      'messages': [],
      'history': [],
    };
    final (state, transport) = await makeState(token: 'tok', routes: {
      'GET support/categories': {
        'categories': [{'value': 'payment', 'label': 'Payment'}],
        'priorities': ['normal'],
      },
      'POST support/tickets': FakeTransport.ok(ticket, status: 201),
      'GET support/tickets/TK-1': ticket,
      'POST support/tickets/TK-1/messages': ticket,
    });
    await pumpApp(tester, state);
    await pushRoute(tester, Routes.supportNew);
    await settle(tester);
    await tester.tap(find.byKey(const ValueKey('support-category')));
    await settle(tester);
    await tester.tap(find.text('Payment').last);
    await settle(tester);
    await tester.enterText(find.byKey(const ValueKey('support-subject')), 'Charged twice');
    await tester.enterText(find.byKey(const ValueKey('support-message')), 'I see two charges for one order.');
    await tester.ensureVisible(find.byKey(const Key('attach-gallery')));
    await tester.tap(find.byKey(const Key('attach-gallery')));
    await settle(tester);
    await tester.ensureVisible(find.byKey(const ValueKey('support-submit')));
    // The attachment row has a third button ("Choose file (PDF)") now.
    await tester.drag(find.byType(ListView).last, const Offset(0, -300));
    await settle(tester, 2);
    await tester.tap(find.byKey(const ValueKey('support-submit')));
    await settle(tester);
    final created = MultipartBody.of(transport.sent('POST support/tickets').single);
    expect(created.fields, {'category': 'payment', 'priority': 'normal', 'subject': 'Charged twice', 'message': 'I see two charges for one order.'});
    expect(created.files.single, ('attachments[]', 'receipt.png', 'image/png', 72));

    // Reply on the ticket screen.
    await clearToasts(tester);
    await tester.tap(find.byKey(const ValueKey('support-reply-attach')));
    await settle(tester);
    await tester.tap(find.byKey(const Key('attach-gallery')));
    await settle(tester);
    await tester.enterText(find.byKey(const ValueKey('support-reply')), 'Here is a screenshot.');
    await tester.tap(find.byKey(const ValueKey('support-reply-send')));
    await settle(tester);
    final reply = MultipartBody.of(transport.sent('POST support/tickets/TK-1/messages').single);
    expect(reply.fields, {'body': 'Here is a screenshot.'});
    expect(reply.files.single.$1, 'attachments[]');
  });

  testWidgets('post ad: each photo is uploaded as `photo` after the ad is created, before submit', (tester) async {
    MediaPicker.current = FakeMediaPicker(galleryResults: [
      [fakePhoto('front.jpg'), fakePhoto('back.jpg')],
    ]);
    final (state, transport) = await makeState(token: 'tok', routes: {
      'GET classifieds/categories': [
        {'id': 3, 'slug': 'phones', 'name': 'Phones', 'children': [], 'max_photos': 2},
      ],
      'GET classifieds/categories/phones/attributes': {'attributes': []},
      'POST classifieds/ads': FakeTransport.ok({'number': 'AD-9'}, status: 201),
      'POST classifieds/ads/AD-9/photos': FakeTransport.ok({'id': 1, 'type': 'photo'}, status: 201),
      'POST classifieds/ads/AD-9/submit': {'status': 'pending_review'},
    });
    await pumpApp(tester, state);
    await pushRoute(tester, Routes.postAd);
    await settle(tester);
    await tester.tap(find.text('Phones'));
    await settle(tester);
    await tester.enterText(find.byType(TextField).at(0), 'iPhone 15');
    await tester.enterText(find.byType(TextField).at(3), 'Dubai');
    await tester.ensureVisible(find.byKey(const Key('attach-gallery')));
    // The form has a location row now; keep the photo buttons on screen.
    await tester.drag(find.byType(ListView).last, const Offset(0, -300));
    await settle(tester, 2);
    await tester.tap(find.byKey(const Key('attach-gallery')));
    await settle(tester);
    expect(find.text('2 of 2'), findsOneWidget);
    await tester.ensureVisible(find.byKey(const ValueKey('post-ad-submit')));
    await tester.drag(find.byType(ListView).last, const Offset(0, -300));
    await settle(tester, 2);
    await tester.tap(find.byKey(const ValueKey('post-ad-submit')));
    await settle(tester);
    final uploads = transport.sent('POST classifieds/ads/AD-9/photos');
    expect(uploads.length, 2);
    expect(MultipartBody.of(uploads.first).files.single.$1, 'photo');
    expect(MultipartBody.of(uploads.last).files.single.$2, 'back.jpg');
    final keys = transport.requests.map(transport.keyFor).where((k) => k.startsWith('POST classifieds/ads')).toList();
    expect(keys, ['POST classifieds/ads', 'POST classifieds/ads/AD-9/photos', 'POST classifieds/ads/AD-9/photos', 'POST classifieds/ads/AD-9/submit']);
  });

  testWidgets('avatar: gallery photo goes to me/avatar; a photo over 2 MB is refused before upload', (tester) async {
    MediaPicker.current = FakeMediaPicker(galleryResults: [
      [fakePhoto('me.jpg')],
    ], hasCamera: false);
    final me = {'id': 1, 'name': 'Sara Ali', 'email': 'sara@example.com', 'avatar_url': null};
    final (state, transport) = await makeState(token: 'tok', routes: {
      'GET me': me,
      'POST me/avatar': {...me, 'avatar_url': 'https://cdn.test/a.jpg'},
    });
    await pumpApp(tester, state);
    await pushRoute(tester, Routes.profile);
    await settle(tester);
    await tester.tap(find.byKey(const ValueKey('avatar-change')));
    await settle(tester);
    final body = MultipartBody.of(transport.sent('POST me/avatar').single);
    expect(body.files.single.$1, 'avatar');
    expect(state.user?['avatar_url'], 'https://cdn.test/a.jpg');

    MediaPicker.current = FakeMediaPicker(galleryResults: [
      [fakePhoto('huge.jpg', size: 3 * 1024 * 1024)],
    ], hasCamera: false);
    await tester.tap(find.byKey(const ValueKey('avatar-change')));
    await settle(tester);
    expect(transport.sent('POST me/avatar').length, 1);
    expect(find.text('huge.jpg is larger than 2 MB.'), findsOneWidget);
  });

  testWidgets('video ad: a stream the player cannot open falls back to the system player', (tester) async {
    InlineVideo.controllerFactory = _FailingController.new;
    await tester.pumpWidget(const MaterialApp(home: Scaffold(body: AdVideoTile(url: 'https://cdn.test/ad.m3u8', headline: 'Big sale'))));
    expect(find.text('Big sale'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('ad-video-play')));
    await tester.pump();
    await tester.pump();
    expect(opened, ['https://cdn.test/ad.m3u8']);
    expect(find.byKey(const ValueKey('video-failed')), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('video-open-external')));
    expect(opened.length, 2);
  });

  testWidgets('live watch: the stream plays in the app (failure path opens the player)', (tester) async {
    InlineVideo.controllerFactory = _FailingController.new;
    final (state, _) = await makeState(routes: {
      'GET live-streams/demo': {
        'slug': 'demo',
        'title': 'Demo stream',
        'status': 'ended',
        'playback': {'media_url': 'https://cdn.test/rec.mp4'},
        'products': [],
      },
    });
    await pumpApp(tester, state);
    await pushRoute(tester, Routes.liveWatch, {'slug': 'demo'});
    await settle(tester);
    await tester.tap(find.byKey(const ValueKey('live-play')));
    await settle(tester, 2);
    expect(opened, ['https://cdn.test/rec.mp4']);
    expect(find.text('Open in player'), findsOneWidget);
  });

  testWidgets('souq: the revealed seller phone opens the dialler (P12)', (tester) async {
    final (state, _) = await makeState(token: 'tok', routes: {
      'GET classifieds/ads/AD-1': {
        'number': 'AD-1',
        'title': 'Bike',
        'status': 'active',
        'media': [],
        'seller': {'has_phone': true},
        'similar': [],
      },
      'POST classifieds/ads/AD-1/phone': {'phone': '+971 50 123 4567'},
    });
    await pumpApp(tester, state);
    await pushRoute(tester, Routes.souqAd, {'number': 'AD-1'});
    await settle(tester);
    await tester.ensureVisible(find.byKey(const ValueKey('ad-phone')));
    await tester.tap(find.byKey(const ValueKey('ad-phone')));
    await settle(tester);
    expect(find.text('+971 50 123 4567'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('ad-phone')));
    await settle(tester);
    expect(opened, ['tel:+971501234567']);
  });
}
