<?php

/*
| Seller Center (partner.) — SRS §4.3. Loaded inside the panel group, so every
| route is signed-in, a partner member, country-bound and store-bound;
| everything but Contracts / Documents also needs the current Seller
| Agreement signed (click-wrap, SRS §13.4).
*/

use App\Domains\Advertising\Http\Controllers\Seller\SellerAdvertisingController;
use App\Domains\Campaigns\Http\Controllers\Seller\SellerCampaignController;
use App\Domains\Classifieds\Http\Controllers\Seller\MarketplaceController;
use App\Domains\Contracts\Http\Controllers\Seller\SellerContractController;
use App\Domains\Contracts\Http\EnsureSellerAgreementSigned;
use App\Domains\Disputes\Http\Controllers\Seller\SellerDisputeController;
use App\Domains\Documents\Http\Controllers\Seller\SellerDocumentController;
use App\Domains\Finance\Http\Controllers\Seller\SellerPayoutController;
use App\Domains\FlashSales\Http\Controllers\Seller\SellerFlashSaleController;
use App\Domains\Listings\Http\Controllers\Seller\ListingController;
use App\Domains\Orders\Http\Controllers\Seller\SellerOrderController;
use App\Domains\Panels\Http\Controllers\TeamController;
use App\Domains\Panels\Http\Middleware\ResolveVendorWorkspace;
use App\Domains\Returns\Http\Controllers\Seller\SellerReturnController;
use App\Domains\Reviews\Http\Controllers\Seller\SellerReviewController;
use App\Domains\Vendors\Http\Controllers\StoreSettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware(ResolveVendorWorkspace::class.':required')->group(function (): void {
    /*
    | Contracts and documents (WP5) stay reachable while the Seller Agreement
    | click-wrap blocks everything else.
    */
    Route::get('contracts', [SellerContractController::class, 'index'])->middleware('tenant.can:partner,legal')->name('contracts');
    Route::get('contracts/{version}', [SellerContractController::class, 'show'])->whereNumber('version')->middleware('tenant.can:partner,legal')->name('contracts.show');
    Route::post('contracts/{version}/sign', [SellerContractController::class, 'sign'])->whereNumber('version')->middleware('tenant.can:partner,legal')->name('contracts.sign');
    Route::get('contracts/signatures/{signature}/download', [SellerContractController::class, 'download'])->whereNumber('signature')->middleware('tenant.can:partner,legal')->name('contracts.download');
    Route::get('documents', [SellerDocumentController::class, 'index'])->middleware('tenant.can:partner,documents')->name('documents');
    Route::post('documents', [SellerDocumentController::class, 'store'])->middleware('throttle:20,1')->middleware('tenant.can:partner,documents')->name('documents.store');
    Route::get('documents/{document}/download', [SellerDocumentController::class, 'download'])->whereNumber('document')->middleware('tenant.can:partner,documents')->name('documents.download');
    Route::get('store', [StoreSettingsController::class, 'show'])->middleware('tenant.can:partner,profile')->name('store');
    Route::put('store', [StoreSettingsController::class, 'update'])->middleware('tenant.can:partner,profile')->name('store.update');

    /*
    | Team & permissions (QC-020): owner only.
    */
    Route::middleware('tenant.can:partner,team')->group(function (): void {
        Route::get('team', [TeamController::class, 'index'])->defaults('panel', 'partner')->name('team');
        Route::post('team/invitations', [TeamController::class, 'invite'])->defaults('panel', 'partner')->middleware('throttle:20,1')->name('team.invite');
        Route::post('team/invitations/{invitation}/revoke', [TeamController::class, 'revoke'])->whereNumber('invitation')->defaults('panel', 'partner')->name('team.invitations.revoke');
        Route::put('team/{membership}', [TeamController::class, 'updateRole'])->whereNumber('membership')->defaults('panel', 'partner')->name('team.role');
        Route::delete('team/{membership}', [TeamController::class, 'destroy'])->whereNumber('membership')->defaults('panel', 'partner')->name('team.destroy');
    });
});

Route::middleware([ResolveVendorWorkspace::class.':required', EnsureSellerAgreementSigned::class])->group(function (): void {
    Route::get('campaigns', [SellerCampaignController::class, 'index'])->middleware('tenant.can:partner,campaigns')->name('campaigns');
    Route::get('campaigns/create', [SellerCampaignController::class, 'create'])->middleware('tenant.can:partner,campaigns')->name('campaigns.create');
    Route::post('campaigns/quote', [SellerCampaignController::class, 'quote'])->middleware('tenant.can:partner,campaigns')->name('campaigns.quote');
    Route::post('campaigns', [SellerCampaignController::class, 'store'])->middleware('tenant.can:partner,campaigns')->name('campaigns.store');
    Route::get('campaigns/{campaign}', [SellerCampaignController::class, 'show'])->whereNumber('campaign')->middleware('tenant.can:partner,campaigns')->name('campaigns.show');
    Route::post('campaigns/{campaign}/cancel', [SellerCampaignController::class, 'cancel'])->whereNumber('campaign')->middleware('tenant.can:partner,campaigns')->name('campaigns.cancel');
    Route::post('campaigns/{campaign}/pause', [SellerCampaignController::class, 'pause'])->whereNumber('campaign')->middleware('tenant.can:partner,campaigns')->name('campaigns.pause');
    Route::post('campaigns/{campaign}/resume', [SellerCampaignController::class, 'resume'])->whereNumber('campaign')->middleware('tenant.can:partner,campaigns')->name('campaigns.resume');
    Route::post('campaigns/{campaign}/invite', [SellerCampaignController::class, 'invite'])->whereNumber('campaign')->middleware('tenant.can:partner,campaigns')->name('campaigns.invite');
    Route::post('campaigns/{campaign}/approve-request', [SellerCampaignController::class, 'approveRequest'])->whereNumber('campaign')->middleware('tenant.can:partner,campaigns')->name('campaigns.approve-request');
    Route::post('campaigns/{campaign}/reject-request', [SellerCampaignController::class, 'rejectRequest'])->whereNumber('campaign')->middleware('tenant.can:partner,campaigns')->name('campaigns.reject-request');
    Route::post('campaigns/samples/{sample}/ship', [SellerCampaignController::class, 'shipSample'])->whereNumber('sample')->middleware('tenant.can:partner,campaigns')->name('campaigns.samples.ship');
    Route::post('campaigns/content/{submission}/review', [SellerCampaignController::class, 'reviewContent'])->whereNumber('submission')->middleware('tenant.can:partner,campaigns')->name('campaigns.content.review');

    Route::get('products', [ListingController::class, 'index'])->middleware('tenant.can:partner,listings')->name('products');
    Route::get('products/create', [ListingController::class, 'create'])->middleware('tenant.can:partner,listings')->name('products.create');
    Route::post('products', [ListingController::class, 'store'])->middleware('tenant.can:partner,listings')->name('products.store');
    Route::get('products/{listing}/edit', [ListingController::class, 'edit'])->whereNumber('listing')->middleware('tenant.can:partner,listings')->name('products.edit');
    Route::put('products/{listing}', [ListingController::class, 'update'])->whereNumber('listing')->middleware('tenant.can:partner,listings')->name('products.update');
    Route::post('products/{listing}/images', [ListingController::class, 'addImages'])->whereNumber('listing')->middleware('tenant.can:partner,listings')->name('products.images.store');
    Route::put('products/{listing}/images/{media}', [ListingController::class, 'updateImage'])->whereNumber(['listing', 'media'])->middleware('tenant.can:partner,listings')->name('products.images.update');
    Route::delete('products/{listing}/images/{media}', [ListingController::class, 'removeImage'])->whereNumber(['listing', 'media'])->middleware('tenant.can:partner,listings')->name('products.images.destroy');
    Route::post('products/{listing}/stock', [ListingController::class, 'adjustStock'])->whereNumber('listing')->middleware('tenant.can:partner,listings')->name('products.stock');
    Route::post('products/{listing}/status', [ListingController::class, 'changeStatus'])->whereNumber('listing')->middleware('tenant.can:partner,listings')->name('products.status');

    Route::get('reviews', [SellerReviewController::class, 'index'])->middleware('tenant.can:partner,reviews')->name('reviews');
    Route::post('reviews/questions/{question}/answer', [SellerReviewController::class, 'answer'])->whereNumber('question')->middleware('throttle:30,1')->middleware('tenant.can:partner,reviews')->name('reviews.questions.answer');

    Route::get('orders', [SellerOrderController::class, 'index'])->middleware('tenant.can:partner,orders')->name('orders');
    Route::get('orders/{subOrder}', [SellerOrderController::class, 'show'])->whereNumber('subOrder')->middleware('tenant.can:partner,orders')->name('orders.show');
    Route::get('orders/{subOrder}/packing-slip', [SellerOrderController::class, 'packingSlip'])->whereNumber('subOrder')->middleware('tenant.can:partner,orders')->name('orders.packing-slip');
    Route::post('orders/{subOrder}/advance', [SellerOrderController::class, 'advance'])->whereNumber('subOrder')->middleware('tenant.can:partner,orders')->name('orders.advance');
    Route::post('orders/{subOrder}/cancel', [SellerOrderController::class, 'cancel'])->whereNumber('subOrder')->middleware('tenant.can:partner,orders')->name('orders.cancel');

    Route::get('flash-sales', [SellerFlashSaleController::class, 'index'])->middleware('tenant.can:partner,marketing')->name('flash-sales');
    Route::post('flash-sales/{event}/submissions', [SellerFlashSaleController::class, 'store'])->whereNumber('event')->middleware('tenant.can:partner,marketing')->name('flash-sales.submit');
    Route::post('flash-sales/submissions/{submission}/withdraw', [SellerFlashSaleController::class, 'withdraw'])->whereNumber('submission')->middleware('tenant.can:partner,marketing')->name('flash-sales.withdraw');

    Route::get('returns', [SellerReturnController::class, 'index'])->middleware('tenant.can:partner,returns')->name('returns');
    Route::get('returns/{return}', [SellerReturnController::class, 'show'])->whereNumber('return')->middleware('tenant.can:partner,returns')->name('returns.show');
    Route::post('returns/{return}/approve', [SellerReturnController::class, 'approve'])->whereNumber('return')->middleware('tenant.can:partner,returns')->name('returns.approve');
    Route::post('returns/{return}/reject', [SellerReturnController::class, 'reject'])->whereNumber('return')->middleware('tenant.can:partner,returns')->name('returns.reject');
    Route::post('returns/{return}/advance', [SellerReturnController::class, 'advance'])->whereNumber('return')->middleware('tenant.can:partner,returns')->name('returns.advance');
    Route::post('returns/{return}/inspect', [SellerReturnController::class, 'inspect'])->whereNumber('return')->middleware('tenant.can:partner,returns')->name('returns.inspect');

    Route::get('disputes', [SellerDisputeController::class, 'index'])->middleware('tenant.can:partner,returns')->name('disputes');
    Route::get('disputes/{dispute}', [SellerDisputeController::class, 'show'])->whereNumber('dispute')->middleware('tenant.can:partner,returns')->name('disputes.show');
    Route::post('disputes/{dispute}/reply', [SellerDisputeController::class, 'reply'])->whereNumber('dispute')->middleware('throttle:30,1')->middleware('tenant.can:partner,returns')->name('disputes.reply');

    Route::get('payouts', [SellerPayoutController::class, 'index'])->middleware('tenant.can:partner,finance')->name('payouts');
    Route::get('statements', fn () => redirect()->route('partner.payouts'))->middleware('tenant.can:partner,finance')->name('statements');
    Route::get('payouts/{payout}', [SellerPayoutController::class, 'show'])->whereNumber('payout')->middleware('tenant.can:partner,finance')->name('payouts.show');

    /*
    | Marketplace (WP6, classified shops) and Advertising.
    */
    Route::get('marketplace/ads', [MarketplaceController::class, 'index'])->middleware('tenant.can:partner,classifieds')->name('marketplace.ads');
    Route::get('marketplace/ads/create', [MarketplaceController::class, 'create'])->middleware('tenant.can:partner,classifieds')->name('marketplace.ads.create');
    Route::post('marketplace/ads', [MarketplaceController::class, 'store'])->middleware('tenant.can:partner,classifieds')->name('marketplace.ads.store');
    Route::post('marketplace/ads/{ad}/{action}', [MarketplaceController::class, 'act'])->whereNumber('ad')->whereIn('action', ['submit', 'sign', 'pause', 'resume', 'renew', 'archive'])->middleware('tenant.can:partner,classifieds')->name('marketplace.ads.act');
    Route::get('marketplace/ads/{ad}/sold', [MarketplaceController::class, 'soldForm'])->whereNumber('ad')->middleware('tenant.can:partner,classifieds')->name('marketplace.ads.sold');
    Route::post('marketplace/ads/{ad}/sold', [MarketplaceController::class, 'markSold'])->whereNumber('ad')->middleware('tenant.can:partner,classifieds')->name('marketplace.ads.sold.store');
    Route::get('marketplace/inquiries', [MarketplaceController::class, 'inquiries'])->middleware('tenant.can:partner,classifieds')->name('marketplace.inquiries');
    Route::post('marketplace/inquiries/{thread}/reply', [MarketplaceController::class, 'reply'])->whereNumber('thread')->middleware('tenant.can:partner,classifieds')->name('marketplace.inquiries.reply');

    Route::get('advertising', [SellerAdvertisingController::class, 'index'])->middleware('tenant.can:partner,marketing')->name('advertising');
    Route::post('advertising/campaigns', [SellerAdvertisingController::class, 'storeCampaign'])->middleware('tenant.can:partner,marketing')->name('advertising.campaigns.store');
    Route::post('advertising/campaigns/{campaign}/status', [SellerAdvertisingController::class, 'moveCampaign'])->whereNumber('campaign')->middleware('tenant.can:partner,marketing')->name('advertising.campaigns.move');
    Route::post('advertising/bookings', [SellerAdvertisingController::class, 'storeBooking'])->middleware('tenant.can:partner,marketing')->name('advertising.bookings.store');
    Route::post('advertising/coupons/{invitation}/join', [SellerAdvertisingController::class, 'joinCoupon'])->whereNumber('invitation')->middleware('tenant.can:partner,marketing')->name('advertising.coupons.join');
});
