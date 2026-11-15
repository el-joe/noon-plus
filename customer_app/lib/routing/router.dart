import 'package:flutter/material.dart';

import '../core/json.dart';
import '../screens/account/account_screens.dart';
import '../screens/account/addresses_screens.dart';
import '../screens/account/help_screens.dart';
import '../screens/account/money_screens.dart';
import '../screens/account/orders_screens.dart';
import '../screens/account/returns_screens.dart';
import '../screens/account/wishlist_screen.dart';
import '../screens/auth/sign_in_screen.dart';
import '../screens/cart/cart_tab.dart';
import '../screens/catalog/product_list_screen.dart';
import '../screens/catalog/product_screen.dart';
import '../screens/catalog/reviews_screens.dart';
import '../screens/catalog/search_screen.dart';
import '../screens/checkout/checkout_screen.dart';
import '../screens/checkout/payment_flow.dart';
import '../screens/deals/deals_tab.dart';
import '../screens/home/home_tab.dart';
import '../screens/live/live_screens.dart';
import '../screens/shell/shell_screen.dart';
import '../screens/souq/souq_screens.dart';
import '../screens/startup/startup_screens.dart';
import '../screens/travel/travel_screens.dart';
import '../state/app_state.dart';
import 'guards.dart';

/// Named routes with guards (`guardRedirect`). Arguments are maps.
class AppRouter {
  const AppRouter._();

  static Route<dynamic> generate(RouteSettings settings, AppState app) {
    final name = settings.name ?? Routes.splash;
    final args = settings.arguments is Map ? asJson(settings.arguments) : <String, dynamic>{};

    // The splash decides where to go once the store is loaded.
    final redirect = name == Routes.splash || !app.ready ? null : guardRedirect(name, hasMarket: app.hasMarket, signedIn: app.signedIn);
    if (redirect == Routes.signIn) {
      return _page(RouteSettings(name: Routes.signIn, arguments: settings.arguments), SignInScreen(next: name, nextArgs: settings.arguments));
    }
    if (redirect != null) return _page(RouteSettings(name: redirect), build(redirect, const {}));
    return _page(settings, build(name, args));
  }

  static Widget build(String name, Json args) {
    String s(String key) => args.str(key);
    return switch (name) {
      Routes.splash => const SplashScreen(),
      Routes.market => MarketScreen(fromSettings: args.b('fromSettings')),
      Routes.signIn => SignInScreen(pop: args.b('pop'), next: args.s('next')),
      Routes.shell => ShellScreen(initialTab: args.s('tab')),
      Routes.search => SearchScreen(initial: args.s('q')),
      Routes.products => ProductListScreen(args: args),
      Routes.product => ProductScreen(slug: s('slug')),
      Routes.reviews => ReviewsScreen(slug: s('slug'), title: args.s('title')),
      Routes.questions => QuestionsScreen(slug: s('slug')),
      Routes.writeReview => WriteReviewScreen(orderItemId: args.i('order_item_id')),
      Routes.flashSale => FlashSaleScreen(id: s('id')),
      Routes.page => PageScreen(slug: s('slug'), title: args.s('title')),
      Routes.cart => const CartTab(),
      Routes.checkout => const CheckoutScreen(),
      Routes.paymentWait => PaymentWaitScreen(order: args.s('order'), reference: args.s('reference'), kind: args.str('kind', 'order'), url: args.s('url')),
      Routes.orderSuccess => OrderSuccessScreen(number: s('number')),
      Routes.orders => const OrdersScreen(),
      Routes.order => OrderDetailScreen(number: s('number')),
      Routes.returns => const ReturnsScreen(),
      Routes.returnNew => ReturnNewScreen(order: args.m('order') ?? const {}, shipment: s('shipment')),
      Routes.returnDetail => ReturnDetailScreen(number: s('number')),
      Routes.wallet => const WalletScreen(),
      Routes.addresses => AddressesScreen(pick: args.b('pick')),
      Routes.addressForm => AddressFormScreen(address: args.m('address')),
      Routes.wishlist => const WishlistScreen(),
      Routes.notifications => const NotificationsScreen(),
      Routes.profile => const ProfileScreen(),
      Routes.security => const SecurityScreen(),
      Routes.giftCards => const GiftCardsScreen(),
      Routes.membership => const MembershipScreen(),
      Routes.help => const HelpScreen(),
      Routes.helpArticle => HelpArticleScreen(slug: s('slug')),
      Routes.travel => TravelHome(args: args),
      Routes.travelPackage => TravelPackageScreen(slug: s('slug')),
      Routes.travelRequest => TravelRequestScreen(package: args.m('package') ?? const {}, departureId: args.i('departure') ?? 0),
      Routes.travelSent => TravelSentScreen(booking: args.m('booking') ?? const {}),
      Routes.travelBooking => TravelBookingScreen(number: s('number'), token: args.s('token')),
      Routes.myBookings => const MyBookingsScreen(),
      Routes.souq => SouqHome(args: args),
      Routes.property => SouqHome(args: args, vertical: 'property'),
      Routes.souqAd => AdDetailScreen(number: s('number')),
      Routes.souqRequest => SouqRequestScreen(ad: args.m('ad') ?? const {}, type: args.str('type', 'viewing')),
      Routes.souqSent => SouqSentScreen(request: args.m('request') ?? const {}, ad: args.m('ad') ?? const {}),
      Routes.postAd => const PostAdScreen(),
      Routes.myAds => const MyAdsScreen(),
      Routes.viewingRequests => const ViewingRequestsScreen(),
      Routes.live => const LiveListScreen(),
      Routes.liveWatch => LiveWatchScreen(slug: s('slug')),
      _ => const _NotFound(),
    };
  }

  static Route<dynamic> _page(RouteSettings settings, Widget child) => MaterialPageRoute<dynamic>(settings: settings, builder: (_) => child);
}

class _NotFound extends StatelessWidget {
  const _NotFound();

  @override
  Widget build(BuildContext context) => Scaffold(appBar: AppBar(), body: const Center(child: Icon(Icons.search_off_rounded, size: 48)));
}
