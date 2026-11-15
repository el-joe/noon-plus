<?php

use App\Domains\Advertising\Http\Controllers\Api\AdController;
use App\Domains\Campaigns\Http\Controllers\Api\ReferralController;
use App\Domains\Cart\Http\Controllers\CartController;
use App\Domains\Catalog\Http\Controllers\CatalogController;
use App\Domains\Classifieds\Http\Controllers\Api\BuyerController;
use App\Domains\Classifieds\Http\Controllers\Api\ClassifiedController;
use App\Domains\Classifieds\Http\Controllers\Api\MyClassifiedController;
use App\Domains\Content\Http\Controllers\Api\ContentApiController;
use App\Domains\Contracts\Http\Controllers\Api\ContractApiController;
use App\Domains\Contracts\Http\Controllers\Api\VendorAgreementApiController;
use App\Domains\Contracts\Http\EnsureSellerAgreementSigned;
use App\Domains\Countries\Http\Controllers\CountryController;
use App\Domains\Countries\Http\Middleware\ResolveMarket;
use App\Domains\Customers\Http\Controllers\AccountController;
use App\Domains\Customers\Http\Controllers\AddressController;
use App\Domains\Customers\Http\Controllers\NotificationController;
use App\Domains\Customers\Http\Controllers\WishlistController;
use App\Domains\Delivery\Http\Controllers\Api\CarrierOpsController;
use App\Domains\Delivery\Http\Controllers\Api\DeliveryAgentApiController;
use App\Domains\Delivery\Http\Middleware\EnsureDeliveryAgent;
use App\Domains\Disputes\Http\Controllers\DisputeController;
use App\Domains\Finance\Http\Controllers\Api\WalletController;
use App\Domains\FlashSales\Http\Controllers\FlashSaleController;
use App\Domains\GiftCards\Http\Controllers\GiftCardController;
use App\Domains\Identity\Http\Controllers\EmailCodeAuthController;
use App\Domains\Identity\Http\Controllers\SecurityController;
use App\Domains\LiveMedia\Http\Controllers\Api\LiveStreamApiController;
use App\Domains\Marketers\Http\Controllers\Api\CreatorDirectoryController;
use App\Domains\Marketers\Http\Controllers\Api\MarketerOpsController;
use App\Domains\Membership\Http\Controllers\MembershipController;
use App\Domains\Newsletter\Http\Controllers\NewsletterController;
use App\Domains\Onboarding\Http\Controllers\Api\PartnerApplicationController;
use App\Domains\Onboarding\Http\Controllers\Api\PortalController;
use App\Domains\Orders\Http\Controllers\OrderController;
use App\Domains\Panels\Http\Controllers\Api\OpsAuthController;
use App\Domains\Panels\Http\Middleware\EnsureOpsWorkspace;
use App\Domains\Payments\Http\Controllers\PaymentController;
use App\Domains\Payments\Http\Controllers\PaymentMethodController;
use App\Domains\Payments\Http\Controllers\PaymentWebhookController;
use App\Domains\RealEstate\Http\Controllers\Api\RealEstateController;
use App\Domains\Returns\Http\Controllers\ReturnController;
use App\Domains\Reviews\Http\Controllers\QuestionController;
use App\Domains\Reviews\Http\Controllers\ReviewController;
use App\Domains\Travel\Http\Controllers\Api\TravelController;
use App\Domains\Travel\Http\Controllers\Api\TravelOpsController;
use App\Domains\Vendors\Http\Controllers\Api\VendorOpsController;
use App\Domains\Warranty\Http\Controllers\WarrantyClaimController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('countries', [CountryController::class, 'index'])->name('countries.index');

    Route::prefix('{market}')->middleware(ResolveMarket::class)->group(function (): void {
        Route::get('context', [CountryController::class, 'context'])->name('market.context');

        Route::get('categories', [CatalogController::class, 'categories'])->name('catalog.categories');
        Route::get('categories/tree', [CatalogController::class, 'categoryTree'])->name('catalog.categories.tree');
        Route::get('categories/{slug}', [CatalogController::class, 'category'])->name('catalog.categories.show');
        Route::get('brands', [CatalogController::class, 'brands'])->name('catalog.brands');
        Route::get('brands/{slug}', [CatalogController::class, 'brand'])->name('catalog.brands.show');
        Route::get('products', [CatalogController::class, 'products'])->name('catalog.products');
        Route::get('products/facets', [CatalogController::class, 'facets'])->name('catalog.products.facets');
        Route::get('products/{slug}', [CatalogController::class, 'product'])->name('catalog.product');

        /*
        | Storefront gaps (WP9 G-W3…G-W12): reviews & ratings, Q&A, address
        | book, wishlist, newsletter and sign-out.
        */
        Route::get('products/{slug}/reviews', [ReviewController::class, 'forProduct'])->name('reviews.product');
        Route::get('sellers/{slug}/reviews', [ReviewController::class, 'forSeller'])->name('reviews.seller');
        Route::get('products/{slug}/questions', [QuestionController::class, 'index'])->name('questions.index');
        Route::get('delivery-areas', [AddressController::class, 'deliveryAreas'])->name('delivery-areas');

        Route::prefix('newsletter')->name('newsletter.')->middleware('throttle:10,1')->group(function (): void {
            Route::post('subscribe', [NewsletterController::class, 'subscribe'])->name('subscribe');
            Route::post('confirm', [NewsletterController::class, 'confirm'])->name('confirm');
            Route::post('unsubscribe', [NewsletterController::class, 'unsubscribe'])->name('unsubscribe');
        });

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::post('auth/logout', [SecurityController::class, 'logout'])->name('auth.logout');

            Route::post('reviews', [ReviewController::class, 'store'])->middleware('throttle:10,1')->name('reviews.store');
            Route::post('reviews/{review}/helpful', [ReviewController::class, 'helpful'])->whereNumber('review')->middleware('throttle:60,1')->name('reviews.helpful');
            Route::delete('reviews/{review}/helpful', [ReviewController::class, 'unhelpful'])->whereNumber('review')->middleware('throttle:60,1')->name('reviews.unhelpful');
            Route::get('me/reviews', [ReviewController::class, 'mine'])->name('reviews.mine');
            Route::get('me/reviewable-items', [ReviewController::class, 'reviewable'])->name('reviews.reviewable');
            Route::post('products/{slug}/questions', [QuestionController::class, 'store'])->middleware('throttle:10,1')->name('questions.store');

            Route::get('addresses', [AddressController::class, 'index'])->name('addresses.index');
            Route::post('addresses', [AddressController::class, 'store'])->middleware('throttle:30,1')->name('addresses.store');
            Route::get('addresses/{address}', [AddressController::class, 'show'])->whereNumber('address')->name('addresses.show');
            Route::match(['put', 'patch'], 'addresses/{address}', [AddressController::class, 'update'])->whereNumber('address')->name('addresses.update');
            Route::post('addresses/{address}/default', [AddressController::class, 'makeDefault'])->whereNumber('address')->name('addresses.default');
            Route::delete('addresses/{address}', [AddressController::class, 'destroy'])->whereNumber('address')->name('addresses.destroy');

            Route::get('wishlist', [WishlistController::class, 'index'])->name('wishlist.index');
            Route::post('wishlist', [WishlistController::class, 'store'])->middleware('throttle:60,1')->name('wishlist.store');
            Route::delete('wishlist/{slug}', [WishlistController::class, 'destroy'])->name('wishlist.destroy');
        });
        Route::get('products/{slug}/size-guide', [CatalogController::class, 'sizeGuide'])->name('catalog.size-guide');

        Route::get('flash-sales', [FlashSaleController::class, 'index'])->name('flash-sales.index');
        Route::get('flash-sales/{id}', [FlashSaleController::class, 'show'])->where('id', '[a-z0-9-]+')->name('flash-sales.show');

        Route::prefix('cart')->name('cart.')->group(function (): void {
            Route::get('/', [CartController::class, 'show'])->name('show');
            Route::put('items', [CartController::class, 'setItem'])->middleware('throttle:cart-writes')->name('items.set');
            Route::delete('items/{listingId}', [CartController::class, 'removeItem'])->whereNumber('listingId')->name('items.remove');
            Route::put('items/{listingId}/warranty', [CartController::class, 'setWarranty'])->whereNumber('listingId')->name('items.warranty');
            Route::put('items/{listingId}/measurements', [CartController::class, 'setMeasurements'])->whereNumber('listingId')->name('items.measurements');
            Route::put('shipping', [CartController::class, 'setShipping'])->name('shipping');
            Route::post('coupons', [CartController::class, 'applyCoupon'])->middleware('throttle:coupon-apply')->name('coupons.apply');
            Route::delete('coupons/{code}', [CartController::class, 'removeCoupon'])->name('coupons.remove');
            Route::post('merge', [CartController::class, 'merge'])->middleware('auth:sanctum')->name('merge');
        });

        Route::middleware('auth:sanctum')->prefix('orders')->name('orders.')->group(function (): void {
            Route::post('/', [OrderController::class, 'store'])->middleware('throttle:10,1')->name('store');
            Route::get('/', [OrderController::class, 'index'])->name('index');
            Route::get('{number}', [OrderController::class, 'show'])->name('show');
            Route::post('{number}/shipments/{shipmentNumber}/cancel', [OrderController::class, 'cancelShipment'])->name('shipments.cancel');
            Route::post('{number}/payments', [PaymentController::class, 'retry'])->middleware('throttle:10,1')->name('payments.retry');
            Route::post('{number}/payments/verify', [PaymentController::class, 'verify'])->middleware('throttle:30,1')->name('payments.verify');
        });

        /*
        | Marketers & contracts (WP5): creator directory and boutique, referral
        | landing `/r/{code}` (records the click), published contract texts.
        */
        Route::get('creators', [CreatorDirectoryController::class, 'index'])->name('creators.index');
        Route::get('creators/{slug}', [CreatorDirectoryController::class, 'show'])->name('creators.show');
        Route::get('referrals/{code}', [ReferralController::class, 'show'])->where('code', '[A-Za-z0-9]{6,16}')->middleware('throttle:60,1')->name('referrals.show');
        Route::get('contracts/{version}', [ContractApiController::class, 'show'])->whereNumber('version')->name('contracts.show');

        /*
        | Verticals (WP6): classifieds, real estate, travel and ad delivery.
        | Public reads; guest inquiries throttled per
        | `classified.inquiry_throttle_per_minute` (5/min); everything else
        | needs the customer token.
        */
        Route::prefix('classifieds')->name('classifieds.')->group(function (): void {
            Route::get('categories', [ClassifiedController::class, 'categories'])->name('categories');
            Route::get('categories/{slug}/attributes', [ClassifiedController::class, 'attributes'])->name('categories.attributes');
            Route::get('ads', [ClassifiedController::class, 'index'])->name('ads.index');
            Route::get('map', [ClassifiedController::class, 'map'])->name('map');
            Route::get('compare', [ClassifiedController::class, 'compare'])->name('compare');
            Route::get('packages', [ClassifiedController::class, 'packages'])->name('packages');
            Route::get('shops/{slug}', [ClassifiedController::class, 'shop'])->name('shops.show');
            Route::get('ads/{number}', [ClassifiedController::class, 'show'])->name('ads.show');
            Route::post('ads/{number}/inquiries', [BuyerController::class, 'inquire'])->middleware('throttle:5,1')->name('ads.inquire');
            Route::post('ads/{number}/reports', [ClassifiedController::class, 'report'])->middleware('throttle:5,1')->name('ads.report');
            Route::post('ads/{number}/viewings', [RealEstateController::class, 'requestViewing'])->middleware('throttle:5,1')->name('ads.viewings');

            Route::middleware('auth:sanctum')->group(function (): void {
                Route::get('mine', [MyClassifiedController::class, 'index'])->name('mine');
                Route::post('ads', [MyClassifiedController::class, 'store'])->middleware('throttle:20,1')->name('ads.store');
                Route::put('ads/{number}', [MyClassifiedController::class, 'update'])->name('ads.update');
                Route::post('ads/{number}/photos', [MyClassifiedController::class, 'photo'])->middleware('throttle:60,1')->name('ads.photos');
                Route::post('ads/{number}/video', [MyClassifiedController::class, 'video'])->middleware('throttle:10,1')->name('ads.video');
                Route::delete('ads/{number}/media/{media}', [MyClassifiedController::class, 'removeMedia'])->whereNumber('media')->name('ads.media.destroy');
                Route::post('ads/{number}/submit', [MyClassifiedController::class, 'submit'])->name('ads.submit');
                Route::post('ads/{number}/contract', [MyClassifiedController::class, 'signContract'])->middleware('throttle:10,1')->name('ads.contract');
                Route::post('ads/{number}/sold', [MyClassifiedController::class, 'sold'])->name('ads.sold');
                Route::get('ads/{number}/contract', [MyClassifiedController::class, 'contract'])->name('ads.contract.show');
                Route::post('ads/{number}/duplicate', [MyClassifiedController::class, 'duplicate'])->middleware('throttle:20,1')->name('ads.duplicate');
                Route::get('ads/{number}/referrers', [MyClassifiedController::class, 'referrers'])->name('ads.referrers');
                Route::post('ads/{number}/packages', [MyClassifiedController::class, 'buyPackage'])->middleware('throttle:10,1')->name('ads.packages');
                Route::post('ads/{number}/{action}', [MyClassifiedController::class, 'action'])->whereIn('action', ['pause', 'resume', 'archive', 'renew', 'unsold'])->name('ads.action');
                Route::post('ads/{number}/phone', [BuyerController::class, 'revealPhone'])->middleware('throttle:20,1')->name('ads.phone');

                Route::get('threads', [BuyerController::class, 'threads'])->name('threads.index');
                Route::get('threads/{thread}', [BuyerController::class, 'thread'])->whereNumber('thread')->name('threads.show');
                Route::post('threads/{thread}/messages', [BuyerController::class, 'message'])->whereNumber('thread')->middleware('throttle:30,1')->name('threads.messages');
                Route::post('threads/{thread}/close', [BuyerController::class, 'close'])->whereNumber('thread')->name('threads.close');

                Route::get('favourites', [BuyerController::class, 'favourites'])->name('favourites.index');
                Route::post('favourites/{number}', [BuyerController::class, 'favourite'])->name('favourites.store');
                Route::delete('favourites/{number}', [BuyerController::class, 'unfavourite'])->name('favourites.destroy');

                Route::get('saved-searches', [BuyerController::class, 'savedSearches'])->name('saved-searches.index');
                Route::post('saved-searches', [BuyerController::class, 'saveSearch'])->name('saved-searches.store');
                Route::patch('saved-searches/{search}', [BuyerController::class, 'updateSavedSearch'])->whereNumber('search')->name('saved-searches.update');
                Route::delete('saved-searches/{search}', [BuyerController::class, 'deleteSavedSearch'])->whereNumber('search')->name('saved-searches.destroy');
            });
        });

        Route::match(['get', 'post', 'delete'], 'follows', [BuyerController::class, 'follow'])->middleware('auth:sanctum')->name('follows');
        Route::get('brokers/{slug}', [RealEstateController::class, 'broker'])->name('brokers.show');
        Route::middleware('auth:sanctum')->group(function (): void {
            Route::get('special-requests', [RealEstateController::class, 'specialRequests'])->name('special-requests.index');
            Route::get('me/viewing-requests', [RealEstateController::class, 'myViewings'])->name('viewing-requests.mine');
            Route::post('special-requests', [RealEstateController::class, 'storeSpecialRequest'])->middleware('throttle:10,1')->name('special-requests.store');
            Route::post('special-requests/{number}/cancel', [RealEstateController::class, 'cancelSpecialRequest'])->name('special-requests.cancel');
        });

        Route::prefix('travel')->name('travel.')->group(function (): void {
            Route::get('packages', [TravelController::class, 'index'])->name('packages.index');
            Route::get('packages/{slug}', [TravelController::class, 'show'])->name('packages.show');
            Route::post('packages/{slug}/inquiries', [TravelController::class, 'inquire'])->middleware('throttle:5,1')->name('packages.inquire');

            /*
            | Booking requests (owner decision WP6: no payment on Nawy) —
            | guests too; a guest reaches their request with X-Booking-Token.
            */
            Route::post('departures/{departure}/bookings', [TravelController::class, 'store'])->whereNumber('departure')->middleware('throttle:travel-bookings')->name('bookings.store');
            Route::get('bookings', [TravelController::class, 'bookings'])->middleware('auth:sanctum')->name('bookings.index');
            Route::post('bookings/resend-link', [TravelController::class, 'resendLink'])->middleware('throttle:3,1')->name('bookings.resend-link');
            Route::get('bookings/{number}', [TravelController::class, 'showBooking'])->name('bookings.show');
            Route::post('bookings/{number}/submit', [TravelController::class, 'submit'])->middleware('throttle:10,1')->name('bookings.submit');
            Route::post('bookings/{number}/cancel', [TravelController::class, 'cancel'])->middleware('throttle:10,1')->name('bookings.cancel');
            Route::post('bookings/{number}/modifications', [TravelController::class, 'modify'])->middleware('throttle:10,1')->name('bookings.modifications');
        });

        Route::post('ads/click', [AdController::class, 'click'])->middleware('throttle:120,1')->name('ads.click');
        Route::get('ads/placements/{placement}', [AdController::class, 'placements'])->name('ads.placements');

        Route::get('membership/plans', [MembershipController::class, 'plans'])->name('membership.plans');
        Route::get('gift-cards/designs', [GiftCardController::class, 'designs'])->name('gift-cards.designs');

        Route::get('checkout/payment-methods', [PaymentMethodController::class, 'index'])->name('checkout.payment-methods');

        /*
        | Payments (ADR 6): gateway return/cancel URLs (browser redirects; some
        | gateways POST back) and signed gateway webhooks — no customer token.
        */
        Route::prefix('payments')->name('payments.')->group(function (): void {
            Route::match(['get', 'post'], '{reference}/return', [PaymentController::class, 'return'])->whereUuid('reference')->name('return');
            Route::match(['get', 'post'], '{reference}/cancel', [PaymentController::class, 'cancel'])->whereUuid('reference')->name('cancel');
            Route::get('{reference}', [PaymentController::class, 'show'])->whereUuid('reference')->middleware('auth:sanctum')->name('show');
            Route::post('{reference}/verify', [PaymentController::class, 'verifyPayment'])->whereUuid('reference')->middleware(['auth:sanctum', 'throttle:30,1'])->name('verify');
            Route::post('webhooks/{gateway}', PaymentWebhookController::class)->whereAlpha('gateway')->middleware('throttle:120,1')->name('webhook');
        });

        // QC-049: return evidence photos, private disk, signed expiring links only.
        Route::get('return-photos/{return}/{index}', [ReturnController::class, 'photo'])->whereNumber(['return', 'index'])->middleware('signed')->name('returns.photo');

        Route::prefix('auth/email-code')->name('auth.email-code.')->middleware('throttle:email-code')->group(function (): void {
            Route::post('send', [EmailCodeAuthController::class, 'send'])->name('send');
            Route::post('verify', [EmailCodeAuthController::class, 'verify'])->name('verify');
        });

        /*
        | Money (WP4): returns, Nawy Credits, gift cards & vouchers, Nawy One,
        | disputes and warranty claims — the signed-in customer's own only.
        */
        Route::middleware('auth:sanctum')->group(function (): void {
            Route::get('returns', [ReturnController::class, 'index'])->name('returns.index');
            Route::post('returns', [ReturnController::class, 'store'])->middleware('throttle:10,1')->name('returns.store');
            Route::get('returns/{number}', [ReturnController::class, 'show'])->name('returns.show');
            Route::post('returns/{number}/cancel', [ReturnController::class, 'cancel'])->name('returns.cancel');

            Route::get('wallet', [WalletController::class, 'show'])->name('wallet.show');
            Route::get('wallet/transactions', [WalletController::class, 'transactions'])->name('wallet.transactions');

            Route::get('gift-cards', [GiftCardController::class, 'index'])->name('gift-cards.index');
            Route::post('gift-cards', [GiftCardController::class, 'store'])->middleware('throttle:10,1')->name('gift-cards.store');
            Route::post('gift-cards/redeem', [GiftCardController::class, 'redeem'])->middleware('throttle:10,1')->name('gift-cards.redeem');
            Route::post('vouchers/redeem', [GiftCardController::class, 'redeemVoucher'])->middleware('throttle:10,1')->name('vouchers.redeem');

            Route::get('membership', [MembershipController::class, 'show'])->name('membership.show');
            Route::post('membership', [MembershipController::class, 'subscribe'])->middleware('throttle:10,1')->name('membership.subscribe');
            Route::post('membership/cancel', [MembershipController::class, 'cancel'])->name('membership.cancel');

            Route::get('disputes', [DisputeController::class, 'index'])->name('disputes.index');
            Route::post('disputes', [DisputeController::class, 'store'])->middleware('throttle:10,1')->name('disputes.store');
            Route::get('disputes/{number}', [DisputeController::class, 'show'])->name('disputes.show');
            Route::post('disputes/{number}/messages', [DisputeController::class, 'message'])->middleware('throttle:30,1')->name('disputes.messages');

            Route::post('contracts/{version}/accept', [ContractApiController::class, 'accept'])->whereNumber('version')->middleware('throttle:10,1')->name('contracts.accept');
            Route::get('me/contracts', [ContractApiController::class, 'mine'])->name('contracts.mine');

            Route::get('warranty-claims', [WarrantyClaimController::class, 'index'])->name('warranty-claims.index');
            Route::post('warranty-claims', [WarrantyClaimController::class, 'store'])->middleware('throttle:10,1')->name('warranty-claims.store');
            Route::get('warranty-claims/{number}', [WarrantyClaimController::class, 'show'])->name('warranty-claims.show');
            Route::post('warranty-claims/{number}/messages', [WarrantyClaimController::class, 'message'])->middleware('throttle:30,1')->name('warranty-claims.messages');
        });

        /*
        | Content & live (WP7, SRS §14–15, ADR 16): published pages with
        | resolved blocks, app contexts, blog, help centre / knowledge hub,
        | static pages, footer, and admin-made live streams (public reads).
        */
        Route::get('pages/{slug}', [ContentApiController::class, 'page'])->where('slug', '[a-z0-9-]+')->name('content.page');
        Route::get('app/contexts', [ContentApiController::class, 'appContexts'])->name('content.app-contexts');
        Route::get('blog', [ContentApiController::class, 'blog'])->name('content.blog');
        Route::get('blog/categories', [ContentApiController::class, 'blogCategories'])->name('content.blog.categories');
        Route::get('blog/{slug}', [ContentApiController::class, 'blogPost'])->name('content.blog.show');
        Route::prefix('help/{hub}')->whereIn('hub', ['help_center', 'knowledge_hub'])->name('content.help.')->group(function (): void {
            Route::get('collections', [ContentApiController::class, 'helpCollections'])->name('collections');
            Route::get('search', [ContentApiController::class, 'helpSearch'])->middleware('throttle:60,1')->name('search');
            Route::get('articles/{slug}', [ContentApiController::class, 'helpArticle'])->name('articles.show');
            Route::post('articles/{slug}/votes', [ContentApiController::class, 'voteHelpArticle'])->middleware('throttle:20,1')->name('articles.vote');
        });
        Route::get('static-pages/{slug}', [ContentApiController::class, 'staticPage'])->name('content.static-page');
        Route::get('footer', [ContentApiController::class, 'footer'])->name('content.footer');
        Route::get('live-streams', [LiveStreamApiController::class, 'index'])->name('live-streams.index');
        Route::get('live-streams/{slug}', [LiveStreamApiController::class, 'show'])->name('live-streams.show');
        Route::post('live-streams/{slug}/likes', [LiveStreamApiController::class, 'like'])->middleware('throttle:30,1')->name('live-streams.like');

        /*
        | Delivery app (WP7, SRS §4.6, ADR 15): agent sign-in, then the
        | agent's own profile and assignments (Sanctum + active agent).
        */
        Route::prefix('delivery')->name('delivery.')->group(function (): void {
            Route::post('auth/login', [DeliveryAgentApiController::class, 'login'])->middleware('throttle:delivery-login')->name('login');

            Route::middleware(['auth:sanctum', EnsureDeliveryAgent::class])->group(function (): void {
                Route::get('me', [DeliveryAgentApiController::class, 'me'])->name('me');
                Route::patch('me', [DeliveryAgentApiController::class, 'update'])->middleware('throttle:30,1')->name('me.update');
                Route::get('me/earnings', [DeliveryAgentApiController::class, 'earnings'])->name('earnings');
                Route::get('me/cod', [DeliveryAgentApiController::class, 'cod'])->name('cod');
                Route::get('me/documents', [DeliveryAgentApiController::class, 'documents'])->name('documents');
                Route::post('me/availability', [DeliveryAgentApiController::class, 'availability'])->name('availability');
                Route::post('me/location', [DeliveryAgentApiController::class, 'location'])->middleware('throttle:120,1')->name('location');
                Route::get('assignments', [DeliveryAgentApiController::class, 'index'])->name('assignments.index');
                Route::get('assignments/{number}', [DeliveryAgentApiController::class, 'show'])->name('assignments.show');
                Route::middleware('throttle:60,1')->group(function (): void {
                    Route::post('assignments/{number}/accept', [DeliveryAgentApiController::class, 'accept'])->name('assignments.accept');
                    Route::post('assignments/{number}/decline', [DeliveryAgentApiController::class, 'decline'])->name('assignments.decline');
                    Route::post('assignments/{number}/start', [DeliveryAgentApiController::class, 'start'])->name('assignments.start');
                    Route::post('assignments/{number}/arrive', [DeliveryAgentApiController::class, 'arrive'])->name('assignments.arrive');
                    Route::post('assignments/{number}/proof', [DeliveryAgentApiController::class, 'proof'])->name('assignments.proof');
                    Route::post('assignments/{number}/confirm', [DeliveryAgentApiController::class, 'confirm'])->name('assignments.confirm');
                    Route::post('assignments/{number}/fail', [DeliveryAgentApiController::class, 'fail'])->name('assignments.fail');
                });
            });
        });

        /*
        | Ops apps (vendor, marketer, supervisor, travel agency; Flutter):
        | one sign-in per role, then each role's group behind Sanctum + the
        | token ability `ops:{role}` + the role's workspace in this country.
        */
        Route::prefix('ops')->name('ops.')->group(function (): void {
            Route::post('auth/login', [OpsAuthController::class, 'login'])->middleware('throttle:ops-login')->name('login');
            Route::post('auth/logout', [SecurityController::class, 'logout'])->middleware('auth:sanctum')->name('logout');

            Route::prefix('vendor')->name('vendor.')->middleware(['auth:sanctum', EnsureOpsWorkspace::class.':vendor'])->group(function (): void {
                Route::get('agreement', [VendorAgreementApiController::class, 'show'])->name('agreement');
                Route::post('agreement/sign', [VendorAgreementApiController::class, 'sign'])->middleware('throttle:10,1')->name('agreement.sign');
            });

            /*
            | QC-017: every other vendor-app route needs the current Seller
            | Agreement signed, as in Seller Center (click-wrap gate).
            */
            Route::prefix('vendor')->name('vendor.')->middleware(['auth:sanctum', EnsureOpsWorkspace::class.':vendor', EnsureSellerAgreementSigned::class])->group(function (): void {
                Route::get('dashboard', [VendorOpsController::class, 'dashboard'])->middleware('tenant.can:partner,dashboard')->name('dashboard');
                Route::get('orders', [VendorOpsController::class, 'orders'])->middleware('tenant.can:partner,orders')->name('orders.index');
                Route::get('orders/{subOrder}', [VendorOpsController::class, 'order'])->whereNumber('subOrder')->middleware('tenant.can:partner,orders')->name('orders.show');
                Route::post('orders/{subOrder}/advance', [VendorOpsController::class, 'advance'])->whereNumber('subOrder')->middleware('throttle:60,1')->middleware('tenant.can:partner,orders')->name('orders.advance');
                Route::post('orders/{subOrder}/cancel', [VendorOpsController::class, 'cancel'])->whereNumber('subOrder')->middleware('throttle:30,1')->middleware('tenant.can:partner,orders')->name('orders.cancel');
                Route::get('stock', [VendorOpsController::class, 'stock'])->middleware('tenant.can:partner,listings')->name('stock.index');
                Route::post('stock/{listing}', [VendorOpsController::class, 'adjustStock'])->whereNumber('listing')->middleware('throttle:60,1')->middleware('tenant.can:partner,listings')->name('stock.adjust');
            });

            Route::prefix('marketer')->name('marketer.')->middleware(['auth:sanctum', EnsureOpsWorkspace::class.':marketer'])->group(function (): void {
                Route::get('dashboard', [MarketerOpsController::class, 'dashboard'])->name('dashboard');
                Route::get('campaigns', [MarketerOpsController::class, 'campaigns'])->name('campaigns');
                Route::get('links', [MarketerOpsController::class, 'links'])->name('links');
                Route::get('conversions', [MarketerOpsController::class, 'conversions'])->name('conversions');
            });

            Route::prefix('supervisor')->name('supervisor.')->middleware(['auth:sanctum', EnsureOpsWorkspace::class.':supervisor'])->group(function (): void {
                Route::get('dashboard', [CarrierOpsController::class, 'dashboard'])->middleware('tenant.can:carrier,dashboard')->name('dashboard');
                Route::get('dispatch', [CarrierOpsController::class, 'dispatchBoard'])->middleware('tenant.can:carrier,dispatch')->name('dispatch');
                Route::post('dispatch/{shipment}/assign', [CarrierOpsController::class, 'assign'])->whereNumber('shipment')->middleware('throttle:60,1')->middleware('tenant.can:carrier,dispatch')->name('dispatch.assign');
                Route::get('failed', [CarrierOpsController::class, 'failed'])->middleware('tenant.can:carrier,dispatch')->name('failed');
                Route::post('failed/{shipment}/{action}', [CarrierOpsController::class, 'failedAction'])->whereNumber('shipment')
                    ->whereIn('action', ['reschedule', 'return', 'returned'])->middleware('throttle:60,1')->middleware('tenant.can:carrier,dispatch')->name('failed.act');
                Route::get('drivers', [CarrierOpsController::class, 'drivers'])->middleware('tenant.can:carrier,agents')->name('drivers');
                Route::post('drivers/{agent}/status', [CarrierOpsController::class, 'driverStatus'])->whereNumber('agent')->middleware('throttle:30,1')->middleware('tenant.can:carrier,agents')->name('drivers.status');
            });

            Route::prefix('travel')->name('travel.')->middleware(['auth:sanctum', EnsureOpsWorkspace::class.':travel_agency'])->group(function (): void {
                Route::get('dashboard', [TravelOpsController::class, 'dashboard'])->middleware('tenant.can:travel_agency,dashboard')->name('dashboard');
                Route::get('bookings', [TravelOpsController::class, 'bookings'])->middleware('tenant.can:travel_agency,bookings')->name('bookings');
                Route::post('bookings/{booking}/confirm', [TravelOpsController::class, 'confirm'])->whereNumber('booking')->middleware('throttle:30,1')->middleware('tenant.can:travel_agency,bookings')->name('bookings.confirm');
                Route::post('bookings/{booking}/reject', [TravelOpsController::class, 'reject'])->whereNumber('booking')->middleware('throttle:30,1')->middleware('tenant.can:travel_agency,bookings')->name('bookings.reject');
                Route::get('departures', [TravelOpsController::class, 'departureList'])->middleware('tenant.can:travel_agency,packages')->name('departures');
                Route::post('departures/{departure}/capacity', [TravelOpsController::class, 'capacity'])->whereNumber('departure')->middleware('throttle:30,1')->middleware('tenant.can:travel_agency,packages')->name('departures.capacity');
            });
        });

        /*
        | Portal (WP8, MASTER_PLAN §5a): join options, partner registration
        | wizards (guest access with X-Application-Token) and advertise requests.
        */
        Route::get('join-options', [PortalController::class, 'joinOptions'])->name('portal.join-options');
        Route::get('advertise-requests/options', [PortalController::class, 'advertiseOptions'])->name('portal.advertise-requests.options');
        Route::post('advertise-requests', [PortalController::class, 'advertiseRequest'])->middleware('throttle:5,1')->name('portal.advertise-requests.store');
        Route::prefix('partner-applications')->name('applications.')->group(function (): void {
            Route::post('/', [PartnerApplicationController::class, 'store'])->middleware('throttle:10,1')->name('store');
            Route::get('check-slug', [PartnerApplicationController::class, 'checkSlug'])->middleware('throttle:60,1')->name('check-slug');
            Route::post('resume-link', [PartnerApplicationController::class, 'resumeLink'])->middleware('throttle:3,1')->name('resume-link');
            Route::post('{number}/email/verify', [PartnerApplicationController::class, 'verifyEmail'])->middleware('throttle:10,1')->name('email.verify');
            Route::post('{number}/email/resend', [PartnerApplicationController::class, 'resendEmailCode'])->middleware('throttle:3,1')->name('email.resend');
            Route::middleware('throttle:60,1')->group(function (): void {
                Route::get('{number}', [PartnerApplicationController::class, 'show'])->name('show');
                Route::post('{number}/steps/{step}', [PartnerApplicationController::class, 'saveStep'])->whereIn('step', ['account', 'store', 'address', 'agency', 'profile', 'company', 'documents'])->name('steps.save');
                Route::post('{number}/documents', [PartnerApplicationController::class, 'uploadDocument'])->name('documents.store');
                Route::delete('{number}/documents/{document}', [PartnerApplicationController::class, 'removeDocument'])->whereNumber('document')->name('documents.destroy');
                Route::get('{number}/agreement', [PartnerApplicationController::class, 'agreement'])->name('agreement');
                Route::post('{number}/submit', [PartnerApplicationController::class, 'submit'])->name('submit');
                Route::post('{number}/withdraw', [PartnerApplicationController::class, 'withdraw'])->name('withdraw');
            });
        });

        Route::middleware('auth:sanctum')->prefix('me')->name('me.')->group(function (): void {
            /*
            | Account round 2 (G-W29): profile, avatar, email change by code,
            | notification centre and signed-contract history.
            */
            Route::get('/', [AccountController::class, 'show'])->name('show');
            Route::match(['put', 'patch'], '/', [AccountController::class, 'update'])->middleware('throttle:30,1')->name('update');
            Route::post('avatar', [AccountController::class, 'avatar'])->middleware('throttle:10,1')->name('avatar.store');
            Route::delete('avatar', [AccountController::class, 'removeAvatar'])->name('avatar.destroy');
            Route::post('email', [AccountController::class, 'requestEmailChange'])->middleware('throttle:email-code')->name('email.request');
            Route::post('email/verify', [AccountController::class, 'confirmEmailChange'])->middleware('throttle:10,1')->name('email.verify');
            Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
            Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('notifications.unread');
            Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
            Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])->whereNumber('notification')->name('notifications.read');
            Route::get('contracts/signed', [ContractApiController::class, 'signed'])->name('contracts.signed');
            Route::get('devices', [SecurityController::class, 'devices'])->name('devices.index');
            Route::delete('devices/{tokenId}', [SecurityController::class, 'revokeDevice'])->whereNumber('tokenId')->name('devices.destroy');
            Route::get('login-history', [SecurityController::class, 'loginHistory'])->name('login-history');
        });
    });
});
