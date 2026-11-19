import 'guards.dart';

/// An in-app destination: a named route plus its arguments.
class AppLink {
  const AppLink(this.route, [this.args = const {}]);

  final String route;
  final Map<String, dynamic> args;

  @override
  String toString() => 'AppLink($route, $args)';
}

/// Map a storefront path (page-builder links, notification `link`s) to a
/// screen. Paths may carry a market prefix (`/uae-en/...`). Returns null for
/// external URLs and anything unsafe; callers open http(s) URLs in the browser.
AppLink? resolveStorefrontLink(String? link) {
  if (link == null) return null;
  final raw = link.trim();
  if (raw.isEmpty || !raw.startsWith('/') || raw.startsWith('//')) return null;
  final uri = Uri.tryParse(raw);
  if (uri == null) return null;
  var seg = uri.pathSegments.where((s) => s.isNotEmpty).toList();
  if (seg.isNotEmpty && RegExp(r'^[a-z]{3}-(en|ar)$').hasMatch(seg.first)) seg = seg.sublist(1);
  final q = uri.queryParameters;
  if (seg.isEmpty) return const AppLink(Routes.shell);

  final head = seg.first;
  final second = seg.length > 1 ? seg[1] : null;
  final third = seg.length > 2 ? seg[2] : null;

  switch (head) {
    case 'products':
    case 'p':
      return second == null ? AppLink(Routes.products, {...q}) : AppLink(Routes.product, {'slug': second});
    case 'search':
      return AppLink(Routes.products, {...q});
    case 'category':
    case 'c':
      return seg.length > 1 ? AppLink(Routes.products, {'category': seg.last}) : const AppLink(Routes.shell, {'tab': 'categories'});
    case 'brands':
      return second == null ? AppLink(Routes.products, {...q}) : AppLink(Routes.products, {'brand': second, 'title': second});
    case 'deals':
      return const AppLink(Routes.shell, {'tab': 'deals'});
    case 'flash-sale':
      return second == null ? const AppLink(Routes.shell, {'tab': 'deals'}) : AppLink(Routes.flashSale, {'id': second});
    case 'pages':
      // Static pages (terms, privacy …); unknown slugs fall back to page-builder pages.
      return second == null ? null : AppLink(Routes.staticPage, {'slug': second});
    case 'seller':
    case 'store':
      return second == null ? null : AppLink(Routes.seller, {'slug': second});
    case 'compare':
      return const AppLink(Routes.compare);
    case 'legal':
    case 'policies':
      return second == null ? null : AppLink(Routes.staticPage, {'slug': second});
    case 'cart':
      return const AppLink(Routes.cart);
    case 'gift-cards':
      return const AppLink(Routes.giftCards);
    case 'membership':
      return const AppLink(Routes.membership);
    case 'help':
      return second == 'articles' && third != null ? AppLink(Routes.helpArticle, {'slug': third}) : const AppLink(Routes.help);
    case 'live':
      return second == null ? const AppLink(Routes.live) : AppLink(Routes.liveWatch, {'slug': second});
    case 'travel':
      if (second == null || second == 'search') return AppLink(Routes.travel, {...q});
      if (second == 'bookings') return third == null ? const AppLink(Routes.myBookings) : AppLink(Routes.travelBooking, {'number': third, if (q['token'] != null) 'token': q['token']});
      return AppLink(Routes.travelPackage, {'slug': second});
    case 'classified':
    case 'souq':
      if (second == 'find' && third != null) return AppLink(Routes.souqAd, {'number': third});
      if (second == 'post') return const AppLink(Routes.postAd);
      return AppLink(Routes.souq, {...q});
    case 'property':
      return second == null ? AppLink(Routes.property, {...q}) : AppLink(Routes.souqAd, {'number': second});
    case 'account':
      switch (second) {
        case null:
          return const AppLink(Routes.shell, {'tab': 'account'});
        case 'orders':
          return third == null ? const AppLink(Routes.orders) : AppLink(Routes.order, {'number': third});
        case 'returns':
          return third == null ? const AppLink(Routes.returns) : AppLink(Routes.returnDetail, {'number': third});
        case 'credits':
          return const AppLink(Routes.wallet);
        case 'wishlist':
          return const AppLink(Routes.wishlist);
        case 'notifications':
          return const AppLink(Routes.notifications);
        case 'addresses':
          return const AppLink(Routes.addresses);
        case 'bookings':
          return const AppLink(Routes.myBookings);
        case 'inquiries':
          return const AppLink(Routes.viewingRequests);
        case 'my-ads':
          return const AppLink(Routes.myAds);
        case 'security':
          return const AppLink(Routes.security);
        case 'reviews':
          return const AppLink(Routes.myReviews);
        case 'profile':
          return const AppLink(Routes.profile);
        case 'gift-cards':
          return const AppLink(Routes.giftCards);
        case 'membership':
          return const AppLink(Routes.membership);
        case 'support':
          return third == null ? const AppLink(Routes.support) : AppLink(Routes.supportTicket, {'number': third});
        case 'cards':
          return const AppLink(Routes.cards);
        case 'followed-stores':
          return const AppLink(Routes.followedStores);
        default:
          return const AppLink(Routes.shell, {'tab': 'account'});
      }
    default:
      // Catch-all category paths (`/electronics/mobiles`).
      return AppLink(Routes.products, {'category': seg.last});
  }
}
