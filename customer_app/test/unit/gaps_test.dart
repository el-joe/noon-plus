import 'package:customer_app/core/json.dart';
import 'package:customer_app/features/page_builder/blocks.dart';
import 'package:customer_app/features/page_builder/settings.dart';
import 'package:customer_app/l10n/l10n.dart';
import 'package:customer_app/screens/account/account_tab.dart';
import 'package:customer_app/screens/account/orders_screens.dart';
import 'package:customer_app/screens/account/static_page_screen.dart';
import 'package:customer_app/screens/catalog/pdp_extras.dart';
import 'package:customer_app/screens/catalog/product_list_screen.dart';
import 'package:customer_app/screens/checkout/checkout_screen.dart';
import 'package:customer_app/screens/checkout/payment_flow.dart';
import 'package:customer_app/screens/souq/souq_screens.dart';
import 'package:customer_app/screens/travel/trip_gallery.dart';
import 'package:customer_app/ui/widgets/common.dart';
import 'package:customer_app/ui/widgets/product_card.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers.dart';

/// Pure helpers with no direct test so far: page-builder settings readers,
/// JSON readers, small formatters and status mappers.
void main() {
  group('page builder settings', () {
    test('localised keys: title_<locale>, then title, then {en, ar}', () {
      expect(const BlockSettings({'title_ar': 'عروض', 'title': 'Deals'}, 'ar').title, 'عروض');
      expect(const BlockSettings({'title_ar': 'عروض', 'title': 'Deals'}, 'en').title, 'Deals');
      expect(const BlockSettings({'title': {'en': 'Deals', 'ar': 'عروض'}}, 'ar').title, 'عروض');
      expect(const BlockSettings({'title': '  ', 'heading': 'Top picks'}, 'en').title, 'Top picks');
      expect(const BlockSettings({'title_en': 'Local'}, 'en').str('title', localised: false), isNull);
      expect(const BlockSettings({'columns': 4}, 'en').str('columns'), '4');
    });

    test('number and flag accept strings and fall back', () {
      const s = BlockSettings({'n': '3', 'bad': 'x', 'f1': 1, 'f0': '0', 'ft': 'true', 'fb': 'yes'}, 'en');
      expect(s.number('n', 1), 3);
      expect(s.number('bad', 7), 7);
      expect(s.number('missing', 2.5), 2.5);
      expect(s.flag('f1'), isTrue);
      expect(s.flag('f0', true), isFalse);
      expect(s.flag('ft'), isTrue);
      expect(s.flag('fb', true), isTrue);
      expect(s.flag('missing'), isFalse);
    });

    test('background must be a #rrggbb colour (string or {color})', () {
      expect(const BlockSettings({'background': '#FEEE00'}, 'en').background, '#FEEE00');
      expect(const BlockSettings({'background': {'color': '#1b1b1b'}}, 'en').background, '#1b1b1b');
      expect(const BlockSettings({'background': 'red'}, 'en').background, isNull);
      expect(const BlockSettings({'background': '#FFF'}, 'en').background, isNull);
      expect(const BlockSettings({'background': 'url(javascript:x)'}, 'en').background, isNull);
    });

    test('view all link, never localised', () {
      expect(const BlockSettings({'view_all_link': '/deals'}, 'ar').viewAll, '/deals');
      expect(const BlockSettings({'view_all_ar': '/x'}, 'ar').viewAll, isNull);
    });

    test('items read every editor spelling; only http(s) images are kept', () {
      const s = BlockSettings({
        'slides': [
          {'image_m': 'https://cdn/m.jpg', 'image_d': 'https://cdn/d.jpg', 'href': '/p/1', 'name_ar': 'شاشة', 'name': 'TV', 'offer': '20%', 'badge': 'New', 'code': 'SAVE'},
          {'src': 'javascript:alert(1)', 'url': '/c/2', 'title': 'Phones'},
          {'logo': '//cdn/logo.png'},
          'not a map',
        ],
      }, 'ar');
      final items = s.items('slides');
      expect(items, hasLength(3));
      expect(items[0].image, 'https://cdn/m.jpg');
      expect(items[0].link, '/p/1');
      expect(items[0].label, 'شاشة');
      expect(items[0].caption, '20%');
      expect(items[0].sticker, 'New');
      expect(items[0].code, 'SAVE');
      expect(items[1].image, isNull);
      expect(items[1].link, '/c/2');
      expect(items[1].isEmpty, isFalse);
      expect(items[2].image, isNull);
      expect(items[2].isEmpty, isTrue);
    });

    test('safeImageUrl accepts only absolute http(s)', () {
      expect(safeImageUrl('https://a/b.png'), 'https://a/b.png');
      expect(safeImageUrl('http://a/b.png'), 'http://a/b.png');
      expect(safeImageUrl('data:image/png;base64,AAA'), isNull);
      expect(safeImageUrl('/storage/b.png'), isNull);
      expect(safeImageUrl(null), isNull);
    });
  });

  group('customer JsonRead copy', () {
    test('tolerant readers never throw on the wrong type', () {
      final Json j = {'n': '7', 'f': 1.9, 'b': '1', 'm': [1], 'l': {'a': 1}, 's': ['x', '', ' y '], 'csv': 'a\nb, c'};
      expect(j.i('n'), 7);
      expect(j.i('f'), 1);
      expect(j.d('n'), 7.0);
      expect(j.b('b'), isTrue);
      expect(j.m('m'), isNull);
      expect(j.l('l'), isEmpty);
      expect(j.strings('s'), ['x', ' y ']);
      expect(j.strings('csv'), ['a', 'b', 'c']);
      expect(j.str('missing', '-'), '-');
      expect(asJsonList([{'a': 1}, 2, null]), [{'a': 1}]);
    });
  });

  group('text helpers', () {
    test('stripHtml drops scripts, styles and tags; keeps line breaks', () {
      expect(stripHtml('<p>Hello <b>there</b></p><script>alert(1)</script><style>p{}</style>Bye'), 'Hello there\nBye');
      expect(stripHtml('a<br/>b<BR>c'), 'a\nb\nc');
      expect(stripHtml('<p>A</p><p></p><p></p><p>B</p>'), 'A\n\nB');
      expect(stripHtml('5 &lt; 6 &amp;&nbsp;&quot;ok&quot;'), '5 < 6 & "ok"');
    });

    test('stripHtml / plainText decode an escaped entity only once', () {
      // "&amp;lt;" is the text "&lt;" — decoding &amp; first turns it into "<".
      expect(stripHtml('Type &amp;lt;b&amp;gt;'), 'Type &lt;b&gt;');
      expect(plainText('Type &amp;lt;b&amp;gt;'), 'Type &lt;b&gt;');
    });

    test('compactCount', () {
      expect(compactCount(999), '999');
      expect(compactCount(1000), '1.0K');
      expect(compactCount(1240), '1.2K');
      expect(compactCount(2500000), '2.5M');
    });

    test('specValue flattens lists, maps and bools', () {
      expect(specValue(null), '');
      expect(specValue(true), '✓');
      expect(specValue(false), '✗');
      expect(specValue(['Red', 'Blue', 3]), 'Red, Blue, 3');
      expect(specValue({'value': 120, 'unit': 'm²'}), '120 m²');
    });

    test('checkout address line skips missing and empty parts', () {
      expect(addressLine({'building': 'Sadaf 4', 'apartment': '', 'street': null, 'area': 'Marina', 'city': 'Dubai'}), 'Sadaf 4, Marina, Dubai');
      expect(addressLine({}), '');
    });

    test('formatMoneyJson keeps the API amount and orders the code by locale', () {
      expect(formatMoneyJson(money('12.500', 'OMR'), 'en'), 'OMR 12.500');
      expect(formatMoneyJson(money('12.500', 'OMR'), 'ar'), '12.500 OMR');
      expect(formatMoneyJson(null, 'en'), '');
      expect(formatMoneyJson({'amount': '1'}, 'en'), '');
    });
  });

  group('status mappers', () {
    test('payment next_action → step', () {
      expect(paymentStep({'next_action': {'type': 'redirect', 'url': 'https://pay'}}), PaymentStep.redirect);
      expect(paymentStep({'next_action': {'type': 'wait'}}), PaymentStep.wait);
      expect(paymentStep({'next_action': {'type': 'retry'}}), PaymentStep.retry);
      expect(paymentStep({'next_action': null}), PaymentStep.done);
      expect(paymentStep(null), PaymentStep.done);
    });

    test('shipment progress maps processing / completed onto the steps', () {
      expect(shipmentStepIndex('placed'), 0);
      expect(shipmentStepIndex('processing'), shipmentStepIndex('confirmed'));
      expect(shipmentStepIndex('completed'), shipmentSteps.length - 1);
      expect(shipmentStepIndex('cancelled'), -1);
      expect(shipmentStepIndex(null), -1);
    });

    test('newestFirst sorts by created_at without changing the input', () {
      final items = <Json>[
        {'id': 1, 'created_at': '2026-01-02T10:00:00Z'},
        {'id': 2, 'created_at': '2026-03-01T10:00:00Z'},
        {'id': 3},
      ];
      expect(newestFirst(items).map((e) => e['id']), [2, 1, 3]);
      expect(items.first['id'], 1);
    });

    test('card badges need a label', () {
      expect(cardBadges({'badges': [{'key': 'best', 'label': 'Best seller'}, {'key': 'x', 'label': ''}, {'key': 'y'}]}).map((b) => b['key']), ['best']);
      expect(cardBadges({}), isEmpty);
    });

    test('trip photos: cover first, gallery without duplicates or empty urls', () {
      final photos = tripPhotos({
        'title': 'Salalah',
        'image': {'url': 'https://cdn/cover.jpg'},
        'gallery': [{'url': 'https://cdn/cover.jpg'}, {'url': 'https://cdn/2.jpg', 'alt': 'Beach'}, {'alt': 'no url'}],
      });
      expect(photos.map((p) => p['url']), ['https://cdn/cover.jpg', 'https://cdn/2.jpg']);
      expect(photos.first['alt'], 'Salalah');
      expect(tripPhotos({'cover_url': 'https://cdn/c.jpg'}).single['url'], 'https://cdn/c.jpg');
      expect(tripPhotos({}), isEmpty);
    });
  });

  testWidgets('coupon headline: label, BOGO, percent, amount (3 decimals), code', (tester) async {
    final out = <String>[];
    await tester.pumpWidget(L10nScope(
      l10n: const L10n('en'),
      child: Builder(builder: (context) {
        out
          ..add(couponHeadline(context, {'label': 'Ramadan deal', 'percent': '10'}))
          ..add(couponHeadline(context, {'type': 'bogo', 'buy_quantity': 2}))
          ..add(couponHeadline(context, {'percent': '12.50'}))
          ..add(couponHeadline(context, {'percent': '0', 'amount': money('2.5', 'KWD')}))
          ..add(couponHeadline(context, {'percent': '0', 'amount': money('0', 'AED'), 'code': 'WELCOME'}));
        return const SizedBox();
      }),
    ));
    expect(out, ['Ramadan deal', 'Buy 2 get 1', '12.5% off', 'KWD 2.500 off', 'WELCOME']);
  });
}
