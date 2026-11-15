import 'package:customer_app/routing/deep_links.dart';
import 'package:customer_app/routing/guards.dart';
import 'package:customer_app/screens/catalog/product_filters.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  group('guardRedirect', () {
    test('no market → country & language picker (except splash/picker)', () {
      expect(guardRedirect(Routes.shell, hasMarket: false, signedIn: false), Routes.market);
      expect(guardRedirect(Routes.product, hasMarket: false, signedIn: true), Routes.market);
      expect(guardRedirect(Routes.splash, hasMarket: false, signedIn: false), isNull);
      expect(guardRedirect(Routes.market, hasMarket: false, signedIn: false), isNull);
    });

    test('account screens and checkout need sign-in', () {
      for (final r in [Routes.checkout, Routes.orders, Routes.order, Routes.wallet, Routes.addresses, Routes.notifications, Routes.returns, Routes.postAd]) {
        expect(guardRedirect(r, hasMarket: true, signedIn: false), Routes.signIn, reason: r);
        expect(guardRedirect(r, hasMarket: true, signedIn: true), isNull, reason: r);
      }
    });

    test('guests can browse, use the cart and send D8 booking / viewing requests', () {
      for (final r in [Routes.shell, Routes.product, Routes.cart, Routes.travelRequest, Routes.travelBooking, Routes.myBookings, Routes.souqRequest, Routes.souqAd, Routes.help]) {
        expect(guardRedirect(r, hasMarket: true, signedIn: false), isNull, reason: r);
      }
    });

    test('sign-in while signed in goes to the app', () {
      expect(guardRedirect(Routes.signIn, hasMarket: true, signedIn: true), Routes.shell);
    });
  });

  group('resolveStorefrontLink', () {
    test('products, search and categories', () {
      expect(resolveStorefrontLink('/products/soundmax')!.args, {'slug': 'soundmax'});
      expect(resolveStorefrontLink('/uae-en/products/soundmax')!.route, Routes.product);
      expect(resolveStorefrontLink('/search?q=air%20fryer')!.args, {'q': 'air fryer'});
      expect(resolveStorefrontLink('/category/electronics/mobiles')!.args, {'category': 'mobiles'});
      expect(resolveStorefrontLink('/electronics/mobiles')!.args, {'category': 'mobiles'});
    });

    test('notification links', () {
      expect(resolveStorefrontLink('/account/orders/NW-1')!.route, Routes.order);
      expect(resolveStorefrontLink('/account/returns/RT-1')!.args, {'number': 'RT-1'});
      expect(resolveStorefrontLink('/travel/bookings/TB-9')!.route, Routes.travelBooking);
      expect(resolveStorefrontLink('/travel/bookings/TB-9?token=abc')!.args, {'number': 'TB-9', 'token': 'abc'});
      expect(resolveStorefrontLink('/account/inquiries')!.route, Routes.viewingRequests);
      expect(resolveStorefrontLink('/classified/find/CL-1')!.args, {'number': 'CL-1'});
      expect(resolveStorefrontLink('/flash-sale/weekend-frenzy-1')!.args, {'id': 'weekend-frenzy-1'});
    });

    test('shell tabs', () {
      expect(resolveStorefrontLink('/deals')!.args, {'tab': 'deals'});
      expect(resolveStorefrontLink('/')!.route, Routes.shell);
    });

    test('external and unsafe links are not in-app', () {
      expect(resolveStorefrontLink('https://example.com'), isNull);
      expect(resolveStorefrontLink('//evil.com/x'), isNull);
      expect(resolveStorefrontLink('javascript:alert(1)'), isNull);
      expect(resolveStorefrontLink(null), isNull);
    });
  });

  group('ProductFilters', () {
    test('builds the products query', () {
      final f = ProductFilters.fromArgs({'q': ' fryer ', 'category': 'kitchen', 'brand': 'b2,b1'})
        ..minPrice = '100'
        ..rating = 4
        ..sort = 'price_asc';
      f.toggleAttribute('colour', 'Red');
      f.toggleAttribute('colour', 'Black');
      expect(f.query(page: 2, perPage: 20), {
        'q': 'fryer',
        'category': 'kitchen',
        'brand': 'b1,b2',
        'min_price': '100',
        'rating': 4,
        'attr[colour]': 'Black,Red',
        'sort': 'price_asc',
        'page': 2,
        'per_page': 20,
      });
      expect(f.activeCount, 6); // 2 brands + price + rating + 2 colours
    });

    test('facets query drops sort and paging; clear keeps the search', () {
      final f = ProductFilters(q: 'tv', sort: 'newest')..brands.add('x');
      expect(f.query(page: 3, forFacets: true), {'q': 'tv', 'brand': 'x'});
      f.clear();
      expect(f.query(), {'q': 'tv', 'sort': 'newest'});
      f.toggleAttribute('size', 'M');
      f.toggleAttribute('size', 'M');
      expect(f.attributes, isEmpty);
    });
  });
}
