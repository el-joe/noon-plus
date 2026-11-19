<?php

/*
| Seller Center (partner.) — SRS §4.3. Loaded inside the panel group, so every
| route is signed-in, a partner member, country-bound and store-bound;
| everything but Contracts / Documents also needs the current Seller
| Agreement signed (click-wrap, SRS §13.4).
*/

use App\Domains\Advertising\Http\Controllers\Seller\SellerAdvertisingController;
use App\Domains\Campaigns\Http\Controllers\Seller\SellerCampaignController;
use App\Domains\Catalog\Http\Controllers\Seller\ProductRequestController;
use App\Domains\Classifieds\Http\Controllers\Seller\MarketplaceController;
use App\Domains\Contracts\Http\Controllers\Seller\SellerContractController;
use App\Domains\Contracts\Http\EnsureSellerAgreementSigned;
use App\Domains\Coupons\Http\Controllers\Seller\SellerCouponController;
use App\Domains\Disputes\Http\Controllers\Seller\SellerDisputeController;
use App\Domains\Documents\Http\Controllers\Seller\SellerDocumentController;
use App\Domains\Finance\Http\Controllers\Seller\SellerEarningsController;
use App\Domains\Finance\Http\Controllers\Seller\SellerPayoutController;
use App\Domains\Finance\Http\Controllers\Seller\SellerSubscriptionController;
use App\Domains\FlashSales\Http\Controllers\Seller\SellerFlashSaleController;
use App\Domains\Fulfilment\Http\Controllers\Seller\SellerInventoryController;
use App\Domains\Fulfilment\Http\Controllers\Seller\SellerPackagingController;
use App\Domains\Listings\Http\Controllers\Seller\ListingController;
use App\Domains\Orders\Http\Controllers\Seller\SellerOrderController;
use App\Domains\Panels\Http\Controllers\SellerDashboardController;
use App\Domains\Panels\Http\Controllers\TeamController;
use App\Domains\Panels\Http\Middleware\ResolveVendorWorkspace;
use App\Domains\Returns\Http\Controllers\Seller\SellerReturnController;
use App\Domains\Reviews\Http\Controllers\Seller\SellerReviewController;
use App\Domains\Support\Http\Controllers\Panel\PartnerSupportTicketController;
use App\Domains\Vendors\Http\Controllers\ChangeRequestController;
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

    /*
    | Support tickets (QC-007): the team's tickets with Nawy support.
    */
    Route::middleware('tenant.can:partner,support')->group(function (): void {
        Route::get('support', [PartnerSupportTicketController::class, 'index'])->name('support');
        Route::post('support', [PartnerSupportTicketController::class, 'store'])->middleware('throttle:10,1')->name('support.store');
        Route::get('support/{ticket}', [PartnerSupportTicketController::class, 'show'])->whereNumber('ticket')->name('support.show');
        Route::post('support/{ticket}/reply', [PartnerSupportTicketController::class, 'reply'])->whereNumber('ticket')->middleware('throttle:30,1')->name('support.reply');
        Route::post('support/{ticket}/reopen', [PartnerSupportTicketController::class, 'reopen'])->whereNumber('ticket')->middleware('throttle:10,1')->name('support.reopen');
        Route::post('support/{ticket}/rate', [PartnerSupportTicketController::class, 'rate'])->whereNumber('ticket')->middleware('throttle:10,1')->name('support.rate');
    });
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
    Route::get('campaigns/create', [SellerCampaignController::class, 'create'])->middleware('tenant.can:partner,campaigns,write')->name('campaigns.create');
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

    /*
    | Inventory & warehouses (Fulfilled by Nawy): FBN stock, enrolment,
    | inbound plans and labels, removals, fees.
    */
    Route::middleware('tenant.can:partner,inventory')->prefix('inventory')->name('inventory')->group(function (): void {
        Route::get('/', [SellerInventoryController::class, 'index']);
        Route::post('enrol', [SellerInventoryController::class, 'enrol'])->name('.enrol');
        Route::post('listings/{listing}/leave', [SellerInventoryController::class, 'leave'])->whereNumber('listing')->name('.leave');
        Route::get('inbound', [SellerInventoryController::class, 'inboundIndex'])->name('.inbound');
        Route::get('inbound/create', [SellerInventoryController::class, 'createInbound'])->middleware('tenant.can:partner,inventory,write')->name('.inbound.create');
        Route::post('inbound', [SellerInventoryController::class, 'storeInbound'])->middleware('throttle:30,1')->name('.inbound.store');
        Route::get('inbound/{shipment}', [SellerInventoryController::class, 'showInbound'])->whereNumber('shipment')->name('.inbound.show');
        Route::post('inbound/{shipment}/{action}', [SellerInventoryController::class, 'actInbound'])->whereNumber('shipment')->whereIn('action', ['submit', 'ship', 'cancel'])->name('.inbound.act');
        Route::get('inbound/{shipment}/labels', [SellerInventoryController::class, 'labels'])->whereNumber('shipment')->name('.inbound.labels');
        Route::get('inbound/{shipment}/labels.pdf', [SellerInventoryController::class, 'labels'])->whereNumber('shipment')->middleware('throttle:30,1')->name('.inbound.labels-pdf');
        Route::get('removals', [SellerInventoryController::class, 'removalsIndex'])->name('.removals');
        Route::post('removals', [SellerInventoryController::class, 'storeRemoval'])->middleware('throttle:20,1')->name('.removals.store');
        Route::post('removals/{removal}/cancel', [SellerInventoryController::class, 'cancelRemoval'])->whereNumber('removal')->name('.removals.cancel');
        Route::get('fees', [SellerInventoryController::class, 'fees'])->name('.fees');
    });

    Route::get('products', [ListingController::class, 'index'])->middleware('tenant.can:partner,listings')->name('products');
    Route::get('products/create', [ListingController::class, 'create'])->middleware('tenant.can:partner,listings,write')->name('products.create');
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
    // QC-012: moderated replies to the store's reviews, and reports.
    Route::post('reviews/{review}/reply', [SellerReviewController::class, 'reply'])->whereNumber('review')->middleware(['throttle:30,1', 'tenant.can:partner,reviews'])->name('reviews.reply');
    Route::post('reviews/{review}/report', [SellerReviewController::class, 'report'])->whereNumber('review')->middleware(['throttle:30,1', 'tenant.can:partner,reviews'])->name('reviews.report');

    // QC-062: dashboard "Download report" (CSV of the store's shipments).
    Route::get('dashboard/report', [SellerDashboardController::class, 'report'])->middleware(['throttle:10,1', 'tenant.can:partner,orders'])->name('dashboard.report');
    Route::get('orders', [SellerOrderController::class, 'index'])->middleware('tenant.can:partner,orders')->name('orders');
    Route::get('orders/{subOrder}', [SellerOrderController::class, 'show'])->whereNumber('subOrder')->middleware('tenant.can:partner,orders')->name('orders.show');
    Route::get('orders/{subOrder}/invoice.pdf', [SellerOrderController::class, 'invoicePdf'])->whereNumber('subOrder')->middleware(['throttle:30,1', 'tenant.can:partner,orders'])->name('orders.invoice-pdf');
    Route::get('orders/{subOrder}/packing-slip', [SellerOrderController::class, 'packingSlip'])->whereNumber('subOrder')->middleware('tenant.can:partner,orders')->name('orders.packing-slip');
    Route::get('orders/{subOrder}/label', [SellerOrderController::class, 'parcelLabel'])->whereNumber('subOrder')->middleware('tenant.can:partner,orders')->name('orders.label');
    Route::get('orders/{subOrder}/label.pdf', [SellerOrderController::class, 'parcelLabel'])->whereNumber('subOrder')->middleware('tenant.can:partner,orders')->name('orders.label-pdf');
    Route::post('orders/{subOrder}/advance', [SellerOrderController::class, 'advance'])->whereNumber('subOrder')->middleware('tenant.can:partner,orders')->name('orders.advance');
    Route::post('orders/{subOrder}/cancel', [SellerOrderController::class, 'cancel'])->whereNumber('subOrder')->middleware('tenant.can:partner,orders')->name('orders.cancel');

    Route::get('flash-sales', [SellerFlashSaleController::class, 'index'])->middleware('tenant.can:partner,marketing')->name('flash-sales');
    Route::post('flash-sales/{event}/submissions', [SellerFlashSaleController::class, 'store'])->whereNumber('event')->middleware('tenant.can:partner,marketing')->name('flash-sales.submit');
    Route::post('flash-sales/submissions/{submission}/withdraw', [SellerFlashSaleController::class, 'withdraw'])->whereNumber('submission')->middleware('tenant.can:partner,marketing')->name('flash-sales.withdraw');

    /*
    | Seller coupons (SRS §10, matrix §5.2 Marketing): the store's own vendor-funded coupons.
    */
    Route::middleware('tenant.can:partner,marketing')->group(function (): void {
        Route::get('coupons', [SellerCouponController::class, 'index'])->name('coupons');
        Route::get('coupons/create', [SellerCouponController::class, 'create'])->middleware('tenant.can:partner,marketing,write')->name('coupons.create');
        Route::post('coupons', [SellerCouponController::class, 'store'])->middleware('throttle:30,1')->name('coupons.store');
        Route::get('coupons/{coupon}', [SellerCouponController::class, 'show'])->whereNumber('coupon')->name('coupons.show');
        Route::get('coupons/{coupon}/edit', [SellerCouponController::class, 'edit'])->whereNumber('coupon')->middleware('tenant.can:partner,marketing,write')->name('coupons.edit');
        Route::put('coupons/{coupon}', [SellerCouponController::class, 'update'])->whereNumber('coupon')->name('coupons.update');
        Route::post('coupons/{coupon}/toggle', [SellerCouponController::class, 'toggle'])->whereNumber('coupon')->middleware('throttle:30,1')->name('coupons.toggle');
    });

    Route::get('returns', [SellerReturnController::class, 'index'])->middleware('tenant.can:partner,returns')->name('returns');
    Route::get('returns/{return}', [SellerReturnController::class, 'show'])->whereNumber('return')->middleware('tenant.can:partner,returns')->name('returns.show');
    Route::post('returns/{return}/approve', [SellerReturnController::class, 'approve'])->whereNumber('return')->middleware('tenant.can:partner,returns')->name('returns.approve');
    Route::post('returns/{return}/reject', [SellerReturnController::class, 'reject'])->whereNumber('return')->middleware('tenant.can:partner,returns')->name('returns.reject');
    Route::post('returns/{return}/advance', [SellerReturnController::class, 'advance'])->whereNumber('return')->middleware('tenant.can:partner,returns')->name('returns.advance');
    Route::post('returns/{return}/inspect', [SellerReturnController::class, 'inspect'])->whereNumber('return')->middleware('tenant.can:partner,returns')->name('returns.inspect');

    Route::get('disputes', [SellerDisputeController::class, 'index'])->middleware('tenant.can:partner,returns')->name('disputes');
    Route::get('disputes/{dispute}', [SellerDisputeController::class, 'show'])->whereNumber('dispute')->middleware('tenant.can:partner,returns')->name('disputes.show');
    Route::post('disputes/{dispute}/reply', [SellerDisputeController::class, 'reply'])->whereNumber('dispute')->middleware('throttle:30,1')->middleware('tenant.can:partner,returns')->name('disputes.reply');

    Route::get('earnings', SellerEarningsController::class)->middleware('tenant.can:partner,finance')->name('earnings');
    Route::get('payouts', [SellerPayoutController::class, 'index'])->middleware('tenant.can:partner,finance')->name('payouts');
    Route::get('statements', fn () => redirect()->route('partner.payouts'))->middleware('tenant.can:partner,finance')->name('statements');
    Route::get('payouts/{payout}', [SellerPayoutController::class, 'show'])->whereNumber('payout')->middleware('tenant.can:partner,finance')->name('payouts.show');

    // FR-FIN-7: the store's seller plan, invoices (PDF) and payments.
    Route::middleware('tenant.can:partner,finance')->group(function (): void {
        Route::get('subscription', [SellerSubscriptionController::class, 'show'])->name('subscription');
        Route::post('subscription', [SellerSubscriptionController::class, 'subscribe'])->middleware('throttle:10,1')->name('subscription.subscribe');
        Route::post('subscription/plan', [SellerSubscriptionController::class, 'changePlan'])->name('subscription.plan');
        Route::post('subscription/preference', [SellerSubscriptionController::class, 'preference'])->name('subscription.preference');
        Route::post('subscription/auto-renew', [SellerSubscriptionController::class, 'autoRenew'])->middleware('throttle:20,1')->name('subscription.auto-renew');
        Route::post('subscription/cancel', [SellerSubscriptionController::class, 'cancel'])->name('subscription.cancel');
        Route::post('subscription/resume', [SellerSubscriptionController::class, 'resume'])->name('subscription.resume');
        Route::post('subscription/invoices/{invoice}/pay', [SellerSubscriptionController::class, 'pay'])->whereNumber('invoice')->middleware('throttle:10,1')->name('subscription.invoices.pay');
        Route::post('subscription/invoices/{invoice}/verify', [SellerSubscriptionController::class, 'verify'])->whereNumber('invoice')->middleware('throttle:20,1')->name('subscription.invoices.verify');
        Route::get('subscription/invoices/{invoice}/pdf', [SellerSubscriptionController::class, 'pdf'])->whereNumber('invoice')->name('subscription.invoices.pdf');
    });

    /*
    | Marketplace (WP6, classified shops) and Advertising.
    */
    Route::get('marketplace/ads', [MarketplaceController::class, 'index'])->middleware('tenant.can:partner,classifieds')->name('marketplace.ads');
    Route::get('marketplace/ads/create', [MarketplaceController::class, 'create'])->middleware('tenant.can:partner,classifieds,write')->name('marketplace.ads.create');
    Route::post('marketplace/ads', [MarketplaceController::class, 'store'])->middleware('tenant.can:partner,classifieds')->name('marketplace.ads.store');
    Route::post('marketplace/ads/{ad}/{action}', [MarketplaceController::class, 'act'])->whereNumber('ad')->whereIn('action', ['submit', 'sign', 'pause', 'resume', 'renew', 'archive'])->middleware('tenant.can:partner,classifieds')->name('marketplace.ads.act');
    Route::get('marketplace/ads/{ad}/sold', [MarketplaceController::class, 'soldForm'])->whereNumber('ad')->middleware('tenant.can:partner,classifieds')->name('marketplace.ads.sold');
    Route::post('marketplace/ads/{ad}/sold', [MarketplaceController::class, 'markSold'])->whereNumber('ad')->middleware('tenant.can:partner,classifieds')->name('marketplace.ads.sold.store');
    Route::get('marketplace/requests', [MarketplaceController::class, 'requests'])->middleware('tenant.can:partner,classifieds')->name('marketplace.requests');
    Route::get('marketplace/inquiries', [MarketplaceController::class, 'inquiries'])->middleware('tenant.can:partner,classifieds')->name('marketplace.inquiries');
    Route::post('marketplace/inquiries/{thread}/reply', [MarketplaceController::class, 'reply'])->whereNumber('thread')->middleware('tenant.can:partner,classifieds')->name('marketplace.inquiries.reply');

    Route::get('advertising', [SellerAdvertisingController::class, 'index'])->middleware('tenant.can:partner,marketing')->name('advertising');
    Route::post('advertising/campaigns', [SellerAdvertisingController::class, 'storeCampaign'])->middleware('tenant.can:partner,marketing')->name('advertising.campaigns.store');
    Route::post('advertising/campaigns/{campaign}/status', [SellerAdvertisingController::class, 'moveCampaign'])->whereNumber('campaign')->middleware('tenant.can:partner,marketing')->name('advertising.campaigns.move');
    Route::post('advertising/bookings', [SellerAdvertisingController::class, 'storeBooking'])->middleware('tenant.can:partner,marketing')->name('advertising.bookings.store');
    Route::post('advertising/coupons/{invitation}/join', [SellerAdvertisingController::class, 'joinCoupon'])->whereNumber('invitation')->middleware('tenant.can:partner,marketing')->name('advertising.coupons.join');
});

/*
| QC-016 lifecycles: profile change requests for locked sections (§7.3,
| reachable before the Seller Agreement like the store profile), product
| requests (§1.3) and packaging supplies (§6.3).
*/
Route::middleware([ResolveVendorWorkspace::class.':required', 'tenant.can:partner,profile'])->group(function (): void {
    Route::get('change-requests', [ChangeRequestController::class, 'index'])->defaults('panel', 'partner')->name('change-requests');
    Route::post('change-requests', [ChangeRequestController::class, 'store'])->defaults('panel', 'partner')->middleware('throttle:10,1')->name('change-requests.store');
    Route::post('change-requests/{changeRequest}/cancel', [ChangeRequestController::class, 'cancel'])->whereNumber('changeRequest')->defaults('panel', 'partner')->name('change-requests.cancel');
});

Route::middleware([ResolveVendorWorkspace::class.':required', EnsureSellerAgreementSigned::class])->group(function (): void {
    Route::middleware('tenant.can:partner,listings')->group(function (): void {
        Route::get('product-requests', [ProductRequestController::class, 'index'])->defaults('panel', 'partner')->name('product-requests');
        Route::post('product-requests', [ProductRequestController::class, 'store'])->defaults('panel', 'partner')->middleware('throttle:20,1')->name('product-requests.store');
        Route::post('product-requests/{productRequest}/cancel', [ProductRequestController::class, 'cancel'])->whereNumber('productRequest')->defaults('panel', 'partner')->name('product-requests.cancel');
    });
    Route::middleware('tenant.can:partner,inventory')->group(function (): void {
        Route::get('packaging', [SellerPackagingController::class, 'index'])->name('packaging');
        Route::post('packaging', [SellerPackagingController::class, 'store'])->middleware('throttle:10,1')->name('packaging.store');
        Route::get('packaging/{order}', [SellerPackagingController::class, 'show'])->whereNumber('order')->name('packaging.show');
    });
});
