/// Named routes and the routing guards (pure, unit tested).
class Routes {
  const Routes._();

  static const splash = '/';
  static const market = '/market';
  static const signIn = '/sign-in';
  static const shell = '/app';
  static const search = '/search';
  static const products = '/products';
  static const product = '/product';
  static const reviews = '/product/reviews';
  static const questions = '/product/questions';
  static const writeReview = '/reviews/new';
  static const myReviews = '/reviews/mine';
  static const flashSale = '/flash-sale';
  static const page = '/page';
  static const cart = '/cart';
  static const checkout = '/checkout';
  static const paymentWait = '/checkout/payment';
  static const orderSuccess = '/checkout/success';
  static const orders = '/orders';
  static const order = '/order';
  static const returns = '/returns';
  static const returnNew = '/returns/new';
  static const returnDetail = '/return';
  static const wallet = '/wallet';
  static const addresses = '/addresses';
  static const addressForm = '/addresses/edit';
  static const wishlist = '/wishlist';
  static const notifications = '/notifications';
  static const profile = '/profile';
  static const security = '/security';
  static const giftCards = '/gift-cards';
  static const membership = '/membership';
  static const help = '/help';
  static const helpArticle = '/help/article';
  static const travel = '/travel';
  static const travelPackage = '/travel/package';
  static const travelRequest = '/travel/request';
  static const travelSent = '/travel/sent';
  static const travelBooking = '/travel/booking';
  static const myBookings = '/travel/bookings';
  static const souq = '/souq';
  static const souqAd = '/souq/ad';
  static const souqRequest = '/souq/request';
  static const souqSent = '/souq/sent';
  static const postAd = '/souq/post';
  static const myAds = '/souq/mine';
  static const adPhotos = '/souq/photos';
  static const viewingRequests = '/souq/requests';
  static const property = '/property';
  static const live = '/live';
  static const liveWatch = '/live/watch';
  // Wave 5b.
  static const support = '/support';
  static const supportNew = '/support/new';
  static const supportTicket = '/support/ticket';
  static const cards = '/cards';
  static const seller = '/seller';
  static const followedStores = '/followed-stores';
  static const compare = '/compare';
  static const staticPage = '/legal';

  /// Screens that need a signed-in customer (API `auth:sanctum`).
  static const authRequired = <String>{
    checkout,
    paymentWait,
    orders,
    order,
    returns,
    returnNew,
    returnDetail,
    wallet,
    addresses,
    addressForm,
    wishlist,
    notifications,
    profile,
    security,
    giftCards,
    writeReview,
    myReviews,
    postAd,
    myAds,
    adPhotos,
    viewingRequests,
    support,
    supportNew,
    supportTicket,
    cards,
    followedStores,
  };

  /// Reachable before a market is chosen.
  static const marketless = <String>{splash, market};
}

/// Where to send the user instead of [route], or null to allow it.
///
/// - No market yet → the country & language picker.
/// - A signed-in-only screen while signed out → sign-in (which returns to the
///   original route afterwards).
/// - Sign-in while already signed in → the app shell.
/// Travel booking requests and Souq viewing/reserve requests stay open to
/// guests (MASTER_PLAN D8); so do cart, browsing and help.
String? guardRedirect(String route, {required bool hasMarket, required bool signedIn}) {
  if (!hasMarket && !Routes.marketless.contains(route)) return Routes.market;
  if (!signedIn && Routes.authRequired.contains(route)) return Routes.signIn;
  if (signedIn && route == Routes.signIn) return Routes.shell;
  return null;
}
