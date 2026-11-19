<?php

/*
| Creator Studio (marketer.) — SRS §4.4, role-permission matrix §7. Loaded
| inside the panel group: signed-in marketer member, country selected,
| workspace marketer bound (pages refuse without a profile).
*/

use App\Domains\Advertising\Http\Controllers\Studio\MarketerAdvertisingController;
use App\Domains\Catalog\Http\Controllers\Seller\ProductRequestController;
use App\Domains\FlashSales\Http\Controllers\Studio\MarketerFlashSaleController;
use App\Domains\Marketers\Http\Controllers\Studio\CelebrityReportController;
use App\Domains\Marketers\Http\Controllers\Studio\CreatorStudioController;
use App\Domains\Marketers\Http\Controllers\Studio\MarketerPicksController;
use App\Domains\Marketers\Http\Controllers\Studio\MarketerProfileController;
use App\Domains\RealEstate\Http\Controllers\Studio\BrokerStudioController;
use App\Domains\Support\Http\Controllers\Panel\PartnerSupportTicketController;
use Illuminate\Support\Facades\Route;

Route::get('invitations', [CreatorStudioController::class, 'invitations'])->middleware('tenant.can:marketer,campaigns')->name('invitations');
Route::post('invitations/{invitation}/accept', [CreatorStudioController::class, 'accept'])->whereNumber('invitation')->middleware('tenant.can:marketer,campaigns')->name('invitations.accept');
Route::post('invitations/{invitation}/decline', [CreatorStudioController::class, 'decline'])->whereNumber('invitation')->middleware('tenant.can:marketer,campaigns')->name('invitations.decline');

Route::get('campaigns', [CreatorStudioController::class, 'campaigns'])->middleware('tenant.can:marketer,campaigns')->name('campaigns');
Route::post('campaigns/request', [CreatorStudioController::class, 'requestCampaign'])->middleware('tenant.can:marketer,campaigns')->name('campaigns.request');
Route::get('campaigns/{invitation}', [CreatorStudioController::class, 'campaign'])->whereNumber('invitation')->middleware('tenant.can:marketer,campaigns')->name('campaigns.show');
Route::post('campaigns/{invitation}/content', [CreatorStudioController::class, 'submitContent'])->whereNumber('invitation')->middleware('tenant.can:marketer,campaigns')->name('campaigns.content');
Route::post('samples/{sample}/request', [CreatorStudioController::class, 'requestSample'])->whereNumber('sample')->middleware('tenant.can:marketer,campaigns')->name('samples.request');
Route::post('samples/{sample}/received', [CreatorStudioController::class, 'confirmSample'])->whereNumber('sample')->middleware('tenant.can:marketer,campaigns')->name('samples.received');

Route::get('marketplace', [CreatorStudioController::class, 'marketplace'])->middleware('tenant.can:marketer,campaigns')->name('marketplace');
Route::post('marketplace/{campaign}/join', [CreatorStudioController::class, 'join'])->whereNumber('campaign')->middleware('tenant.can:marketer,campaigns')->name('marketplace.join');

Route::get('links', [CreatorStudioController::class, 'links'])->middleware('tenant.can:marketer,campaigns')->name('links');
Route::get('links/{invitation}/qr', [CreatorStudioController::class, 'linkQr'])->whereNumber('invitation')->middleware(['throttle:60,1', 'tenant.can:marketer,campaigns'])->name('links.qr');
Route::get('wallet', [CreatorStudioController::class, 'wallet'])->middleware('tenant.can:marketer,wallet')->name('wallet');
Route::post('wallet/withdrawals', [CreatorStudioController::class, 'withdraw'])->middleware('throttle:10,1')->middleware('tenant.can:marketer,wallet')->name('wallet.withdraw');

Route::get('cross-promotion', [CreatorStudioController::class, 'crossPromotion'])->middleware('tenant.can:marketer,campaigns')->name('cross-promotion');
Route::post('cross-promotion/peer', [CreatorStudioController::class, 'peer'])->middleware('tenant.can:marketer,campaigns')->name('cross-promotion.peer');

Route::get('contract', [CreatorStudioController::class, 'contract'])->middleware('tenant.can:marketer,contract')->name('contract');
Route::post('contract', [CreatorStudioController::class, 'signContract'])->middleware('tenant.can:marketer,contract')->name('contract.sign');
Route::post('agency/switch', [CreatorStudioController::class, 'switchMarketer'])->name('agency.switch');

/*
| Broker pages (WP6, spec §5.7).
*/
Route::get('broker/leads', [BrokerStudioController::class, 'leads'])->middleware('tenant.can:marketer,broker')->name('broker.leads');
Route::post('broker/leads', [BrokerStudioController::class, 'storeLead'])->middleware('tenant.can:marketer,broker')->name('broker.leads.store');
Route::post('broker/leads/{lead}/status', [BrokerStudioController::class, 'moveLead'])->whereNumber('lead')->middleware('tenant.can:marketer,broker')->name('broker.leads.move');
Route::post('broker/leads/{lead}/follow-ups', [BrokerStudioController::class, 'followUp'])->whereNumber('lead')->middleware('tenant.can:marketer,broker')->name('broker.leads.follow-up');
Route::post('broker/follow-ups/{followUp}/done', [BrokerStudioController::class, 'completeFollowUp'])->whereNumber('followUp')->middleware('tenant.can:marketer,broker')->name('broker.follow-ups.done');
Route::get('broker/requests', [BrokerStudioController::class, 'requests'])->middleware('tenant.can:marketer,broker')->name('broker.requests');
Route::get('broker/special-requests', [BrokerStudioController::class, 'specialRequests'])->middleware('tenant.can:marketer,broker')->name('broker.special-requests');
Route::post('broker/special-requests/{special}/claim', [BrokerStudioController::class, 'claim'])->whereNumber('special')->middleware('tenant.can:marketer,broker')->name('broker.special-requests.claim');
Route::post('broker/special-requests/{special}/finish', [BrokerStudioController::class, 'finish'])->whereNumber('special')->middleware('tenant.can:marketer,broker')->name('broker.special-requests.finish');
Route::get('broker/properties', [BrokerStudioController::class, 'properties'])->middleware('tenant.can:marketer,broker')->name('broker.properties');

// My products: curated picks for the creator page and referral landing page.
Route::middleware('tenant.can:marketer,studio')->group(function (): void {
    Route::get('products', [MarketerPicksController::class, 'index'])->name('products');
    Route::post('products', [MarketerPicksController::class, 'store'])->middleware('throttle:60,1')->name('products.store');
    Route::post('products/{pick}/move', [MarketerPicksController::class, 'move'])->whereNumber('pick')->middleware('throttle:120,1')->name('products.move');
    Route::delete('products/{pick}', [MarketerPicksController::class, 'destroy'])->whereNumber('pick')->name('products.destroy');
});

Route::get('profile', [MarketerProfileController::class, 'show'])->middleware('tenant.can:marketer,profile')->name('profile');
Route::put('profile', [MarketerProfileController::class, 'update'])->middleware('tenant.can:marketer,profile')->name('profile.update');

// QC-010: the marketer's advertising channel (ad packages, slot bookings, coupon participation), paid from the wallet.
Route::get('advertising', [MarketerAdvertisingController::class, 'index'])->middleware('tenant.can:marketer,campaigns')->name('advertising');
Route::post('advertising/packages/{package}', [MarketerAdvertisingController::class, 'subscribe'])->whereNumber('package')->middleware(['throttle:10,1', 'tenant.can:marketer,campaigns'])->name('advertising.packages.subscribe');
Route::post('advertising/bookings', [MarketerAdvertisingController::class, 'book'])->middleware(['throttle:10,1', 'tenant.can:marketer,campaigns'])->name('advertising.bookings.store');
Route::post('advertising/coupons/{invitation}/join', [MarketerAdvertisingController::class, 'joinCoupon'])->whereNumber('invitation')->middleware(['throttle:10,1', 'tenant.can:marketer,campaigns'])->name('advertising.coupons.join');

/*
| Support tickets (QC-007): the team's tickets with Nawy support.
*/
Route::middleware('tenant.can:marketer,support')->group(function (): void {
    Route::get('support', [PartnerSupportTicketController::class, 'index'])->name('support');
    Route::post('support', [PartnerSupportTicketController::class, 'store'])->middleware('throttle:10,1')->name('support.store');
    Route::get('support/{ticket}', [PartnerSupportTicketController::class, 'show'])->whereNumber('ticket')->name('support.show');
    Route::post('support/{ticket}/reply', [PartnerSupportTicketController::class, 'reply'])->whereNumber('ticket')->middleware('throttle:30,1')->name('support.reply');
    Route::post('support/{ticket}/reopen', [PartnerSupportTicketController::class, 'reopen'])->whereNumber('ticket')->middleware('throttle:10,1')->name('support.reopen');
    Route::post('support/{ticket}/rate', [PartnerSupportTicketController::class, 'rate'])->whereNumber('ticket')->middleware('throttle:10,1')->name('support.rate');
});

/*
| FR-ANL-2: celebrity monthly reports of the workspace marketer (view + PDF).
*/
Route::middleware('tenant.can:marketer,wallet')->group(function (): void {
    Route::get('reports', [CelebrityReportController::class, 'index'])->name('reports');
    Route::get('reports/{report}', [CelebrityReportController::class, 'show'])->whereNumber('report')->name('reports.show');
    Route::get('reports/{report}/pdf', [CelebrityReportController::class, 'pdf'])->whereNumber('report')->name('reports.pdf');
});

// QC-016: product requests (§1.3) and flash-sale invitations (§4.10 marketer invite sub-machine).
Route::middleware('tenant.can:marketer,studio')->group(function (): void {
    Route::get('product-requests', [ProductRequestController::class, 'index'])->defaults('panel', 'marketer')->name('product-requests');
    Route::post('product-requests', [ProductRequestController::class, 'store'])->defaults('panel', 'marketer')->middleware('throttle:20,1')->name('product-requests.store');
    Route::post('product-requests/{productRequest}/cancel', [ProductRequestController::class, 'cancel'])->whereNumber('productRequest')->defaults('panel', 'marketer')->name('product-requests.cancel');
});
Route::middleware('tenant.can:marketer,campaigns')->group(function (): void {
    Route::get('flash-sales', [MarketerFlashSaleController::class, 'index'])->name('flash-sales');
    Route::post('flash-sales/{invite}/{answer}', [MarketerFlashSaleController::class, 'respond'])->whereNumber('invite')->whereIn('answer', ['accept', 'decline'])->middleware('throttle:30,1')->name('flash-sales.respond');
});
