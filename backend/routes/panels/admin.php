<?php

/*
| Admin commerce screens (admin.) — SRS §4.1. Loaded inside the panel group
| (signed-in admin, country selected); each route checks its permission.
*/

use App\Domains\Advertising\Http\Controllers\Admin\AdsReviewController;
use App\Domains\Advertising\Http\Controllers\Admin\TravelPromotionAdminController;
use App\Domains\Campaigns\Http\Controllers\Admin\CampaignAdminController;
use App\Domains\Catalog\Http\Controllers\Admin\BrandAdminController;
use App\Domains\Catalog\Http\Controllers\Admin\CategoryAdminController;
use App\Domains\Catalog\Http\Controllers\Admin\ProductAdminController;
use App\Domains\Catalog\Http\Controllers\Admin\ProductRequestAdminController;
use App\Domains\Classifieds\Http\Controllers\Admin\ClassifiedCategoryController;
use App\Domains\Classifieds\Http\Controllers\Admin\ClassifiedReviewController;
use App\Domains\Content\Http\Controllers\Admin\AppContextController;
use App\Domains\Content\Http\Controllers\Admin\CmsController;
use App\Domains\Content\Http\Controllers\Admin\NotificationTemplateController;
use App\Domains\Content\Http\Controllers\Admin\PageBuilderController;
use App\Domains\Content\Http\Controllers\Admin\PageBuilderPickerController;
use App\Domains\Contracts\Http\Controllers\Admin\ContractTemplateController;
use App\Domains\Countries\Http\Controllers\Admin\DomainAdminController;
use App\Domains\Countries\Http\Controllers\Admin\HolidayController;
use App\Domains\Coupons\Http\Controllers\Admin\CouponAdminController;
use App\Domains\Customers\Http\Controllers\Admin\CustomerAdminController;
use App\Domains\Delivery\Http\Controllers\Admin\AgentWithdrawalController;
use App\Domains\Delivery\Http\Controllers\Admin\CarrierClaimController;
use App\Domains\Delivery\Http\Controllers\Admin\DeliveryDispatchController;
use App\Domains\Delivery\Http\Controllers\Admin\DeliveryNetworkController;
use App\Domains\Disputes\Http\Controllers\Admin\AdminDisputeController;
use App\Domains\Documents\Http\Controllers\Admin\DocumentReviewController;
use App\Domains\Finance\Http\Controllers\Admin\AcquisitionCommissionController;
use App\Domains\Finance\Http\Controllers\Admin\CodSettlementController;
use App\Domains\Finance\Http\Controllers\Admin\ExchangeRateController;
use App\Domains\Finance\Http\Controllers\Admin\LedgerController;
use App\Domains\Finance\Http\Controllers\Admin\PayoutController;
use App\Domains\Finance\Http\Controllers\Admin\VendorSubscriptionAdminController;
use App\Domains\FlashSales\Http\Controllers\Admin\FlashSaleEventController;
use App\Domains\Fulfilment\Http\Controllers\Admin\FbnAdminController;
use App\Domains\Fulfilment\Http\Controllers\Admin\FbnOperationsController;
use App\Domains\Fulfilment\Http\Controllers\Admin\PackagingAdminController;
use App\Domains\GiftCards\Http\Controllers\Admin\GiftCardAdminController;
use App\Domains\Identity\Http\Controllers\Admin\RoleAdminController;
use App\Domains\Identity\Http\Controllers\Admin\StaffController;
use App\Domains\Listings\Http\Controllers\Admin\ListingReviewController;
use App\Domains\LiveMedia\Http\Controllers\Admin\LiveMediaController;
use App\Domains\Marketers\Http\Controllers\Admin\CelebrityReportAdminController;
use App\Domains\Marketers\Http\Controllers\Admin\MarketerAdminController;
use App\Domains\Membership\Http\Controllers\Admin\MembershipAdminController;
use App\Domains\Migration\Http\Controllers\Admin\LegacyRecordController;
use App\Domains\Migration\Http\Controllers\Admin\LegacyRedirectAdminController;
use App\Domains\Migration\Http\Controllers\Admin\MigrationJobController;
use App\Domains\Migration\Http\Controllers\Admin\MigrationRunController;
use App\Domains\Migration\Http\Controllers\Admin\QuarantineController;
use App\Domains\Migration\Http\Controllers\Admin\ReconciliationController;
use App\Domains\Onboarding\Http\Controllers\Admin\ApplicationReviewController;
use App\Domains\Onboarding\Http\Controllers\Admin\PortalAdminController;
use App\Domains\Orders\Http\Controllers\Admin\AdminOrderController;
use App\Domains\Panels\Http\Controllers\Admin\ActivityLogController;
use App\Domains\Panels\Http\Controllers\Admin\SystemHealthController;
use App\Domains\Panels\Http\Controllers\AdminSettingsController;
use App\Domains\Payments\Http\Controllers\Admin\PaymentGatewayController;
use App\Domains\Payments\Http\Controllers\Admin\PaymentReviewController;
use App\Domains\RealEstate\Http\Controllers\Admin\ExclusiveContractAdminController;
use App\Domains\Refunds\Http\Controllers\Admin\RefundAdminController;
use App\Domains\Reporting\Http\Controllers\Admin\CrossCountryReportController;
use App\Domains\Returns\Http\Controllers\Admin\AdminReturnController;
use App\Domains\Reviews\Http\Controllers\Admin\ReviewModerationController;
use App\Domains\Shipping\Http\Controllers\Admin\ShippingZoneController;
use App\Domains\Support\Http\Controllers\Admin\AdminSupportTicketController;
use App\Domains\Travel\Http\Controllers\Admin\TravelReviewController;
use App\Domains\Vendors\Http\Controllers\Admin\ChangeRequestAdminController;
use App\Domains\Vendors\Http\Controllers\Admin\VendorAdminController;
use App\Domains\Warranty\Http\Controllers\Admin\WarrantyClaimAdminController;
use App\Domains\Warranty\Http\Controllers\Admin\WarrantyPlanController;
use App\Shared\Notifications\Email\Http\EmailLogController;
use Illuminate\Support\Facades\Route;

Route::get('approvals', [ListingReviewController::class, 'index'])->middleware('can:vendor_listings.view')->name('approvals');
Route::middleware('can:vendor_listings.approve')->group(function (): void {
    Route::post('approvals/listings/{listing}/approve', [ListingReviewController::class, 'approve'])->whereNumber('listing')->name('approvals.approve');
    Route::post('approvals/listings/{listing}/reject', [ListingReviewController::class, 'reject'])->whereNumber('listing')->name('approvals.reject');
});

Route::middleware('can:orders.view')->group(function (): void {
    Route::get('orders', [AdminOrderController::class, 'index'])->name('orders');
    Route::get('orders/{order}', [AdminOrderController::class, 'show'])->whereNumber('order')->name('orders.show');
    Route::get('orders/{order}/invoice.pdf', [AdminOrderController::class, 'invoicePdf'])->whereNumber('order')->middleware('throttle:30,1')->name('orders.invoice-pdf');
});
Route::post('sub-orders/{subOrder}/status', [AdminOrderController::class, 'move'])->whereNumber('subOrder')->middleware('can:orders.status')->name('orders.move');
Route::post('sub-orders/{subOrder}/cancel', [AdminOrderController::class, 'forceCancel'])->whereNumber('subOrder')->middleware('can:orders.force_cancel')->name('orders.force-cancel');

Route::get('settings', AdminSettingsController::class)->name('settings');

Route::get('delivery', [ShippingZoneController::class, 'index'])->middleware('can:shipping_zones.view')->name('delivery');
Route::get('delivery/zones/{zone}', [ShippingZoneController::class, 'show'])->whereNumber('zone')->middleware('can:shipping_zones.view')->name('delivery.zones.show');
Route::middleware('can:shipping_zones.configure')->group(function (): void {
    Route::post('delivery/zones', [ShippingZoneController::class, 'store'])->name('delivery.zones.store');
    Route::put('delivery/zones/{zone}', [ShippingZoneController::class, 'update'])->whereNumber('zone')->name('delivery.zones.update');
    Route::post('delivery/zones/{zone}/areas', [ShippingZoneController::class, 'storeArea'])->whereNumber('zone')->name('delivery.zones.areas.store');
    Route::delete('delivery/areas/{area}', [ShippingZoneController::class, 'destroyArea'])->whereNumber('area')->name('delivery.areas.destroy');
    Route::post('delivery/zones/{zone}/rates', [ShippingZoneController::class, 'storeRate'])->whereNumber('zone')->name('delivery.zones.rates.store');
    Route::put('delivery/rates/{rate}', [ShippingZoneController::class, 'updateRate'])->whereNumber('rate')->name('delivery.rates.update');
    Route::delete('delivery/rates/{rate}', [ShippingZoneController::class, 'destroyRate'])->whereNumber('rate')->name('delivery.rates.destroy');
});

Route::get('settings/holidays', [HolidayController::class, 'index'])->middleware('can:calendars.view')->name('settings.holidays');
Route::middleware('can:calendars.configure')->group(function (): void {
    Route::post('settings/holidays', [HolidayController::class, 'store'])->name('settings.holidays.store');
    Route::put('settings/holidays/{holiday}', [HolidayController::class, 'update'])->whereNumber('holiday')->name('settings.holidays.update');
    Route::delete('settings/holidays/{holiday}', [HolidayController::class, 'destroy'])->whereNumber('holiday')->name('settings.holidays.destroy');
});

Route::get('catalog/warranty-plans', [WarrantyPlanController::class, 'index'])->middleware('can:warranty_plans.view')->name('catalog.warranty-plans');
Route::middleware('can:warranty_plans.update')->group(function (): void {
    Route::post('catalog/warranty-plans', [WarrantyPlanController::class, 'store'])->name('catalog.warranty-plans.store');
    Route::put('catalog/warranty-plans/{plan}', [WarrantyPlanController::class, 'update'])->whereNumber('plan')->name('catalog.warranty-plans.update');
});

Route::get('flash-sales', [FlashSaleEventController::class, 'index'])->middleware('can:flash_sales.view')->name('flash-sales');
Route::get('flash-sales/{event}', [FlashSaleEventController::class, 'show'])->whereNumber('event')->middleware('can:flash_sales.view')->name('flash-sales.show');
Route::middleware('can:flash_sales.manage')->group(function (): void {
    Route::post('flash-sales', [FlashSaleEventController::class, 'store'])->name('flash-sales.store');
    Route::post('flash-sales/{event}/status', [FlashSaleEventController::class, 'transition'])->whereNumber('event')->name('flash-sales.transition');
    Route::post('flash-sales/submissions/{submission}/approve', [FlashSaleEventController::class, 'approveSubmission'])->whereNumber('submission')->name('flash-sales.submissions.approve');
    Route::post('flash-sales/submissions/{submission}/reject', [FlashSaleEventController::class, 'rejectSubmission'])->whereNumber('submission')->name('flash-sales.submissions.reject');
});

/*
| Countries & gateways → Payment gateways (ADR 6, Figma 13:648): super admin
| and Finance (matrix §3.2 `payment_gateways`).
*/
Route::get('countries', fn () => redirect()->route('admin.countries.payment-gateways'))->middleware('can:payment_gateways.view')->name('countries');
Route::get('countries/payment-gateways', [PaymentGatewayController::class, 'index'])->middleware('can:payment_gateways.view')->name('countries.payment-gateways');
Route::middleware('can:payment_gateways.update')->group(function (): void {
    Route::post('countries/payment-gateways', [PaymentGatewayController::class, 'store'])->name('countries.payment-gateways.store');
    Route::put('countries/payment-gateways/{config}', [PaymentGatewayController::class, 'update'])->whereNumber('config')->name('countries.payment-gateways.update');
    Route::post('countries/payment-gateways/{config}/recommend', [PaymentGatewayController::class, 'recommend'])->whereNumber('config')->name('countries.payment-gateways.recommend');
});

/*
| Countries & gateways → Exchange rates (PayPal USD conversion; Finance) and
| Domains (QC-044 hostname → market / panel / portal; super admin).
*/
Route::get('countries/exchange-rates', [ExchangeRateController::class, 'index'])->middleware('can:exchange_rates.view')->name('countries.exchange-rates');
Route::middleware('can:exchange_rates.manage')->group(function (): void {
    Route::post('countries/exchange-rates', [ExchangeRateController::class, 'store'])->name('countries.exchange-rates.store');
    Route::post('countries/exchange-rates/sync', [ExchangeRateController::class, 'sync'])->middleware('throttle:6,1')->name('countries.exchange-rates.sync');
});
Route::get('countries/domains', [DomainAdminController::class, 'index'])->middleware('can:domains.view')->name('countries.domains');
Route::middleware('can:domains.manage')->group(function (): void {
    Route::post('countries/domains', [DomainAdminController::class, 'store'])->name('countries.domains.store');
    Route::get('countries/domains/{domain}/edit', [DomainAdminController::class, 'edit'])->whereNumber('domain')->name('countries.domains.edit');
    Route::put('countries/domains/{domain}', [DomainAdminController::class, 'update'])->whereNumber('domain')->name('countries.domains.update');
    Route::delete('countries/domains/{domain}', [DomainAdminController::class, 'destroy'])->whereNumber('domain')->name('countries.domains.destroy');
});

/*
| Returns (SRS §4.1.4, §7.4) and Finance → Payouts / COD settlements
| (SRS §4.1.12, Figma 98:4242) — matrix §3.2 `returns`, `payouts`,
| `cod_settlements`.
*/
Route::get('returns', [AdminReturnController::class, 'index'])->middleware('can:returns.view')->name('returns');
Route::get('returns/{return}', [AdminReturnController::class, 'show'])->whereNumber('return')->middleware('can:returns.view')->name('returns.show');
Route::middleware('can:returns.manage')->group(function (): void {
    Route::post('returns/{return}/approve', [AdminReturnController::class, 'approve'])->whereNumber('return')->name('returns.approve');
    Route::post('returns/{return}/reject', [AdminReturnController::class, 'reject'])->whereNumber('return')->name('returns.reject');
    Route::post('returns/{return}/advance', [AdminReturnController::class, 'advance'])->whereNumber('return')->name('returns.advance');
    Route::post('returns/{return}/inspect', [AdminReturnController::class, 'inspect'])->whereNumber('return')->name('returns.inspect');
});

/*
| Disputes (QC-001, SRS §7.6): review, escalate, resolve with refund, close.
*/
Route::get('disputes', [AdminDisputeController::class, 'index'])->middleware('can:disputes.view')->name('disputes');
Route::get('disputes/{dispute}', [AdminDisputeController::class, 'show'])->whereNumber('dispute')->middleware('can:disputes.view')->name('disputes.show');
Route::middleware('can:disputes.manage')->group(function (): void {
    Route::post('disputes/{dispute}/messages', [AdminDisputeController::class, 'message'])->whereNumber('dispute')->middleware('throttle:30,1')->name('disputes.message');
    Route::post('disputes/{dispute}/review', [AdminDisputeController::class, 'review'])->whereNumber('dispute')->name('disputes.review');
    Route::post('disputes/{dispute}/escalate', [AdminDisputeController::class, 'escalate'])->whereNumber('dispute')->name('disputes.escalate');
    Route::post('disputes/{dispute}/resolve', [AdminDisputeController::class, 'resolve'])->whereNumber('dispute')->name('disputes.resolve');
    Route::post('disputes/{dispute}/close', [AdminDisputeController::class, 'close'])->whereNumber('dispute')->name('disputes.close');
});

/*
| Support tickets (QC-007, SRS §15, lifecycle §9.3): queue, thread with
| internal notes, assignment, priority and status moves.
*/
Route::get('support', [AdminSupportTicketController::class, 'index'])->middleware('can:tickets.view')->name('support');
Route::get('support/{ticket}', [AdminSupportTicketController::class, 'show'])->whereNumber('ticket')->middleware('can:tickets.view')->name('support.show');
Route::middleware('can:tickets.manage')->group(function (): void {
    Route::post('support/{ticket}/reply', [AdminSupportTicketController::class, 'reply'])->whereNumber('ticket')->middleware('throttle:30,1')->name('support.reply');
    Route::post('support/{ticket}/assign', [AdminSupportTicketController::class, 'assign'])->whereNumber('ticket')->name('support.assign');
    Route::post('support/{ticket}/priority', [AdminSupportTicketController::class, 'priority'])->whereNumber('ticket')->name('support.priority');
    Route::post('support/{ticket}/status', [AdminSupportTicketController::class, 'status'])->whereNumber('ticket')->name('support.status');
});

Route::get('finance', fn () => redirect()->route('admin.finance.payouts'))->middleware('can:payouts.view')->name('finance');
Route::get('finance/payouts', [PayoutController::class, 'index'])->middleware('can:payouts.view')->name('finance.payouts');
Route::get('finance/payouts/{payout}', [PayoutController::class, 'show'])->whereNumber('payout')->middleware('can:payouts.view')->name('finance.payouts.show');
Route::middleware('can:payouts.approve')->group(function (): void {
    Route::post('finance/payouts/{payout}/approve', [PayoutController::class, 'approve'])->whereNumber('payout')->name('finance.payouts.approve');
    Route::post('finance/payouts/{payout}/hold', [PayoutController::class, 'hold'])->whereNumber('payout')->name('finance.payouts.hold');
    Route::post('finance/payouts/{payout}/release', [PayoutController::class, 'release'])->whereNumber('payout')->name('finance.payouts.release');
    Route::post('finance/payouts/{payout}/recalculate', [PayoutController::class, 'recalculate'])->whereNumber('payout')->name('finance.payouts.recalculate');
});
Route::middleware('can:payouts.process')->group(function (): void {
    Route::post('finance/payouts/generate', [PayoutController::class, 'generate'])->name('finance.payouts.generate');
    Route::post('finance/payouts/{payout}/process', [PayoutController::class, 'process'])->whereNumber('payout')->name('finance.payouts.process');
    Route::post('finance/payouts/{payout}/complete', [PayoutController::class, 'complete'])->whereNumber('payout')->name('finance.payouts.complete');
    Route::post('finance/payouts/{payout}/fail', [PayoutController::class, 'fail'])->whereNumber('payout')->name('finance.payouts.fail');
});
Route::get('finance/cod-settlements', [CodSettlementController::class, 'index'])->middleware('can:cod_settlements.view')->name('finance.cod-settlements');
Route::post('finance/cod-settlements/{settlement}/settle', [CodSettlementController::class, 'settle'])->whereNumber('settlement')->middleware('can:cod_settlements.settle')->name('finance.cod-settlements.settle');

/*
| Finance → Ledger (read-only): accounts and balances, journal entries with
| filters, an entry's debit / credit lines and the CSV export.
*/
Route::middleware('can:ledger.view')->group(function (): void {
    Route::get('finance/ledger', [LedgerController::class, 'accounts'])->name('finance.ledger');
    Route::get('finance/ledger/entries', [LedgerController::class, 'entries'])->name('finance.ledger.entries');
    Route::get('finance/ledger/entries/export', [LedgerController::class, 'export'])->middleware('throttle:10,1')->name('finance.ledger.entries.export');
    Route::get('finance/ledger/entries/{transaction}', [LedgerController::class, 'show'])->whereNumber('transaction')->name('finance.ledger.entries.show');
});

/*
| Acquisition commissions (FR-FIN-6, SRS §4.1.5) and vendor subscriptions
| (FR-FIN-7, SRS §4.1.12). "My acquisition commissions" is open to every
| admin (own rows only).
*/
Route::get('my-acquisition-commissions', [AcquisitionCommissionController::class, 'mine'])->name('finance.my-acquisition-commissions');
Route::middleware('can:acquisition_commissions.view')->group(function (): void {
    Route::get('finance/acquisition-commissions', [AcquisitionCommissionController::class, 'index'])->name('finance.acquisition-commissions');
    Route::get('finance/acquisition-commissions/{commission}', [AcquisitionCommissionController::class, 'show'])->whereNumber('commission')->name('finance.acquisition-commissions.show');
    Route::get('finance/acquisition-rules', [AcquisitionCommissionController::class, 'rules'])->name('finance.acquisition-rules');
});
Route::middleware('can:acquisition_commissions.manage')->group(function (): void {
    Route::post('finance/acquisition-commissions/generate', [AcquisitionCommissionController::class, 'generate'])->name('finance.acquisition-commissions.generate');
    Route::post('finance/acquisition-commissions/{commission}/approve', [AcquisitionCommissionController::class, 'approve'])->whereNumber('commission')->name('finance.acquisition-commissions.approve');
    Route::post('finance/acquisition-commissions/{commission}/pay', [AcquisitionCommissionController::class, 'pay'])->whereNumber('commission')->name('finance.acquisition-commissions.pay');
    Route::post('finance/acquisition-commissions/{commission}/reject', [AcquisitionCommissionController::class, 'reject'])->whereNumber('commission')->name('finance.acquisition-commissions.reject');
    Route::post('finance/acquisition-rules', [AcquisitionCommissionController::class, 'storeRule'])->name('finance.acquisition-rules.store');
    Route::get('finance/acquisition-rules/{rule}/edit', [AcquisitionCommissionController::class, 'editRule'])->whereNumber('rule')->name('finance.acquisition-rules.edit');
    Route::put('finance/acquisition-rules/{rule}', [AcquisitionCommissionController::class, 'updateRule'])->whereNumber('rule')->name('finance.acquisition-rules.update');
});
Route::middleware('can:vendor_subscriptions.view')->group(function (): void {
    Route::get('finance/subscriptions', [VendorSubscriptionAdminController::class, 'index'])->name('finance.subscriptions');
    Route::get('finance/subscriptions/{subscription}', [VendorSubscriptionAdminController::class, 'show'])->whereNumber('subscription')->name('finance.subscriptions.show');
    Route::get('finance/subscription-plans', [VendorSubscriptionAdminController::class, 'plans'])->name('finance.subscription-plans');
    Route::get('finance/subscription-invoices/{invoice}/pdf', [VendorSubscriptionAdminController::class, 'invoicePdf'])->whereNumber('invoice')->name('finance.subscription-invoices.pdf');
});
Route::middleware('can:vendor_subscriptions.manage')->group(function (): void {
    Route::post('finance/subscriptions', [VendorSubscriptionAdminController::class, 'subscribe'])->name('finance.subscriptions.store');
    Route::post('finance/subscriptions/{subscription}/plan', [VendorSubscriptionAdminController::class, 'changePlan'])->whereNumber('subscription')->name('finance.subscriptions.plan');
    Route::post('finance/subscriptions/{subscription}/cancel', [VendorSubscriptionAdminController::class, 'cancel'])->whereNumber('subscription')->name('finance.subscriptions.cancel');
    Route::post('finance/subscription-invoices/{invoice}/deduct', [VendorSubscriptionAdminController::class, 'deduct'])->whereNumber('invoice')->name('finance.subscription-invoices.deduct');
    Route::post('finance/subscription-plans', [VendorSubscriptionAdminController::class, 'storePlan'])->name('finance.subscription-plans.store');
    Route::get('finance/subscription-plans/{plan}/edit', [VendorSubscriptionAdminController::class, 'editPlan'])->whereNumber('plan')->name('finance.subscription-plans.edit');
    Route::put('finance/subscription-plans/{plan}', [VendorSubscriptionAdminController::class, 'updatePlan'])->whereNumber('plan')->name('finance.subscription-plans.update');
});

/*
| Marketers & campaigns, Contracts, Documents (WP5, SRS §4.1.6, §13,
| matrix §3.2 `marketers`, `marketer_campaigns`, `marketer_settings`,
| `cross_promotion`, `contract_templates`, `documents`, `document_types`).
*/
Route::get('marketers', [MarketerAdminController::class, 'index'])->middleware('can:marketers.view')->name('marketers');
Route::post('marketers/{marketer}/status', [MarketerAdminController::class, 'status'])->whereNumber('marketer')->middleware('can:marketers.manage')->name('marketers.status');
Route::get('marketers/settings', [MarketerAdminController::class, 'settings'])->middleware('can:marketer_settings.configure')->name('marketers.settings');
Route::middleware('can:marketer_settings.configure')->group(function (): void {
    Route::post('marketers/settings/rules', [MarketerAdminController::class, 'saveRule'])->name('marketers.settings.rules');
    Route::post('marketers/settings/fees', [MarketerAdminController::class, 'saveFee'])->name('marketers.settings.fees');
    Route::post('marketers/settings/general', [MarketerAdminController::class, 'saveGeneral'])->name('marketers.settings.general');
});
Route::get('marketers/withdrawals', [MarketerAdminController::class, 'withdrawals'])->middleware('can:marketers.view')->name('marketers.withdrawals');
Route::post('marketers/withdrawals/{withdrawal}', [MarketerAdminController::class, 'withdrawal'])->whereNumber('withdrawal')->middleware('can:marketer_withdrawals.process')->name('marketers.withdrawals.update');
Route::get('marketers/cross-promotion', [MarketerAdminController::class, 'crossPromotion'])->middleware('can:cross_promotion.manage')->name('marketers.cross-promotion');
Route::middleware('can:cross_promotion.manage')->group(function (): void {
    Route::post('marketers/cross-promotion', [MarketerAdminController::class, 'storePolicy'])->name('marketers.cross-promotion.store');
    Route::post('marketers/cross-promotion/{policy}/toggle', [MarketerAdminController::class, 'togglePolicy'])->whereNumber('policy')->name('marketers.cross-promotion.toggle');
});

Route::get('marketers/campaigns', [CampaignAdminController::class, 'index'])->middleware('can:marketer_campaigns.view')->name('marketers.campaigns');
Route::get('marketers/campaigns/{campaign}', [CampaignAdminController::class, 'show'])->whereNumber('campaign')->middleware('can:marketer_campaigns.view')->name('marketers.campaigns.show');
Route::middleware('can:marketer_campaigns.manage')->group(function (): void {
    Route::post('marketers/campaigns', [CampaignAdminController::class, 'store'])->name('marketers.campaigns.store');
    Route::post('marketers/campaigns/{campaign}/status', [CampaignAdminController::class, 'transition'])->whereNumber('campaign')->name('marketers.campaigns.transition');
    Route::post('marketers/campaigns/{campaign}/invite', [CampaignAdminController::class, 'invite'])->whereNumber('campaign')->name('marketers.campaigns.invite');
    Route::post('marketers/invitations/{invitation}/resend', [CampaignAdminController::class, 'resend'])->whereNumber('invitation')->name('marketers.invitations.resend');
    Route::post('marketers/samples/{sample}', [CampaignAdminController::class, 'sample'])->whereNumber('sample')->name('marketers.samples.update');
    Route::post('marketers/content/{submission}', [CampaignAdminController::class, 'content'])->whereNumber('submission')->name('marketers.content.review');
});

Route::get('contracts', [ContractTemplateController::class, 'index'])->middleware('can:contract_templates.view')->name('contracts');
Route::get('contracts/{template}', [ContractTemplateController::class, 'show'])->whereNumber('template')->middleware('can:contract_templates.view')->name('contracts.show');
Route::middleware('can:contract_templates.manage')->group(function (): void {
    Route::post('contracts', [ContractTemplateController::class, 'store'])->name('contracts.store');
    Route::post('contracts/{template}/draft', [ContractTemplateController::class, 'saveDraft'])->whereNumber('template')->name('contracts.draft');
    Route::post('contracts/versions/{version}/publish', [ContractTemplateController::class, 'publish'])->whereNumber('version')->name('contracts.publish');
    Route::post('contracts/{template}/assignments', [ContractTemplateController::class, 'assign'])->whereNumber('template')->name('contracts.assign');
    Route::delete('contracts/assignments/{assignment}', [ContractTemplateController::class, 'unassign'])->whereNumber('assignment')->name('contracts.unassign');
});

Route::get('documents', [DocumentReviewController::class, 'index'])->middleware('can:documents.view')->name('documents');
Route::get('documents/{document}/download', [DocumentReviewController::class, 'download'])->whereNumber('document')->middleware('can:documents.view')->name('documents.download');
Route::post('documents/{document}/review', [DocumentReviewController::class, 'review'])->whereNumber('document')->middleware('can:documents.review')->name('documents.review');
Route::get('documents/types', [DocumentReviewController::class, 'types'])->middleware('can:document_types.configure')->name('documents.types');
Route::post('documents/types', [DocumentReviewController::class, 'storeType'])->middleware('can:document_types.configure')->name('documents.types.store');

/*
| Verticals & advertising review queues (WP6).
*/
Route::middleware('can:classifieds.review')->group(function (): void {
    Route::get('classifieds', [ClassifiedReviewController::class, 'index'])->name('classifieds');
    Route::post('classifieds/{ad}/approve', [ClassifiedReviewController::class, 'approve'])->whereNumber('ad')->name('classifieds.approve');
    Route::post('classifieds/{ad}/reject', [ClassifiedReviewController::class, 'reject'])->whereNumber('ad')->name('classifieds.reject');
    Route::get('classifieds/reports', [ClassifiedReviewController::class, 'reports'])->name('classifieds.reports');
    Route::post('classifieds/reports/{report}', [ClassifiedReviewController::class, 'decide'])->whereNumber('report')->name('classifieds.reports.decide');
});
Route::get('classifieds/categories', [ClassifiedCategoryController::class, 'index'])->middleware('can:categories.view')->name('classifieds.categories');
Route::post('classifieds/categories/{category}/contract', [ClassifiedCategoryController::class, 'contract'])->whereNumber('category')->middleware('can:categories.manage')->name('classifieds.categories.contract');
Route::post('classifieds/categories/{category}/limits', [ClassifiedCategoryController::class, 'limits'])->whereNumber('category')->middleware('can:categories.manage')->name('classifieds.categories.limits');
Route::middleware('can:travel_packages.review')->group(function (): void {
    Route::get('travel', [TravelReviewController::class, 'index'])->name('travel');
    Route::post('travel/{package}/approve', [TravelReviewController::class, 'approve'])->whereNumber('package')->name('travel.approve');
    Route::post('travel/{package}/reject', [TravelReviewController::class, 'reject'])->whereNumber('package')->name('travel.reject');
    Route::get('travel/{package}/contract', [TravelReviewController::class, 'contract'])->whereNumber('package')->name('travel.contract');
});
Route::middleware('can:ads.review')->group(function (): void {
    Route::get('marketing', [AdsReviewController::class, 'index'])->name('marketing');
    Route::post('marketing/campaigns/{campaign}', [AdsReviewController::class, 'decideCampaign'])->whereNumber('campaign')->name('marketing.campaigns.decide');
    Route::post('marketing/bookings/{booking}', [AdsReviewController::class, 'decideBooking'])->whereNumber('booking')->name('marketing.bookings.decide');
    Route::post('marketing/bookings/{booking}/video', [AdsReviewController::class, 'bookingVideo'])->whereNumber('booking')->name('marketing.bookings.video');
    Route::post('marketing/subscriptions/{subscription}', [AdsReviewController::class, 'decideSubscription'])->whereNumber('subscription')->name('marketing.subscriptions.decide');
});
/*
| D14: travel promotions — placement, options (price per country, duration) and paid promotions.
*/
Route::get('marketing/travel-promotions', [TravelPromotionAdminController::class, 'index'])->middleware('can:travel_promotions.view')->name('marketing.travel-promotions');
Route::middleware('can:travel_promotions.manage')->group(function (): void {
    Route::post('marketing/travel-promotions/slot', [TravelPromotionAdminController::class, 'updateSlot'])->name('marketing.travel-promotions.slot');
    Route::post('marketing/travel-promotions/products', [TravelPromotionAdminController::class, 'storeProduct'])->name('marketing.travel-promotions.products.store');
    Route::put('marketing/travel-promotions/products/{product}', [TravelPromotionAdminController::class, 'updateProduct'])->whereNumber('product')->name('marketing.travel-promotions.products.update');
    Route::post('marketing/travel-promotions/{promotion}/cancel', [TravelPromotionAdminController::class, 'cancel'])->whereNumber('promotion')->name('marketing.travel-promotions.cancel');
    Route::post('marketing/travel-promotions/{promotion}/refund', [TravelPromotionAdminController::class, 'refund'])->whereNumber('promotion')->name('marketing.travel-promotions.refund');
});
Route::middleware('can:coupon_participation.manage')->group(function (): void {
    Route::get('marketing/coupon-participation', [AdsReviewController::class, 'participation'])->name('marketing.coupon-participation');
    Route::post('marketing/coupon-participation', [AdsReviewController::class, 'storeInvitation'])->name('marketing.coupon-participation.store');
    Route::post('marketing/coupon-participation/requests/{participation}', [AdsReviewController::class, 'decideRequest'])->whereNumber('participation')->name('marketing.coupon-participation.decide');
});

/*
| Delivery & logistics (WP7, SRS §4.1.11, ADR 15): dispatch queue and
| assignments, failed shipments (reschedule / return to origin), agents,
| shipping companies, carrier claims and scorecards.
*/
Route::middleware('can:delivery_assignments.view')->group(function (): void {
    Route::get('delivery/assignments', [DeliveryDispatchController::class, 'index'])->name('delivery.assignments');
    Route::get('delivery/assignments/{assignment}', [DeliveryDispatchController::class, 'show'])->whereNumber('assignment')->name('delivery.assignments.show');
    Route::get('delivery/assignments/{assignment}/proof/{kind}', [DeliveryDispatchController::class, 'proof'])->whereNumber('assignment')->whereIn('kind', ['photo', 'signature'])->name('delivery.assignments.proof');
    Route::get('delivery/scorecard', [DeliveryNetworkController::class, 'scorecard'])->name('delivery.scorecard');
});
Route::middleware('can:delivery_assignments.manage')->group(function (): void {
    Route::post('delivery/sub-orders/{subOrder}/assign', [DeliveryDispatchController::class, 'assign'])->whereNumber('subOrder')->name('delivery.assign');
    Route::post('delivery/sub-orders/{subOrder}/allocate', [DeliveryDispatchController::class, 'allocate'])->whereNumber('subOrder')->name('delivery.allocate');
    Route::post('delivery/assignments/{assignment}/reassign', [DeliveryDispatchController::class, 'reassign'])->whereNumber('assignment')->name('delivery.assignments.reassign');
    Route::post('delivery/shipments/{shipment}/reschedule', [DeliveryDispatchController::class, 'reschedule'])->whereNumber('shipment')->name('delivery.shipments.reschedule');
    Route::post('delivery/shipments/{shipment}/return-to-origin', [DeliveryDispatchController::class, 'returnToOrigin'])->whereNumber('shipment')->name('delivery.shipments.rto');
    Route::post('delivery/shipments/{shipment}/returned', [DeliveryDispatchController::class, 'confirmReturned'])->whereNumber('shipment')->name('delivery.shipments.returned');
});
Route::get('delivery/withdrawals', [AgentWithdrawalController::class, 'index'])->middleware('can:payouts.view')->name('delivery.withdrawals');
Route::post('delivery/withdrawals/{withdrawal}', [AgentWithdrawalController::class, 'update'])->whereNumber('withdrawal')->middleware('can:payouts.view')->name('delivery.withdrawals.update');
Route::get('delivery/shipments/{shipment}/label', [DeliveryDispatchController::class, 'label'])->whereNumber('shipment')->middleware('can:delivery_assignments.view')->name('delivery.shipments.label');
Route::get('delivery/shipments/{shipment}/label.pdf', [DeliveryDispatchController::class, 'label'])->whereNumber('shipment')->middleware('can:delivery_assignments.view')->name('delivery.shipments.label-pdf');
Route::middleware('can:delivery_agents.manage')->group(function (): void {
    Route::get('delivery/agents', [DeliveryNetworkController::class, 'agents'])->name('delivery.agents');
    Route::post('delivery/agents', [DeliveryNetworkController::class, 'storeAgent'])->name('delivery.agents.store');
    Route::post('delivery/agents/{agent}/status', [DeliveryNetworkController::class, 'agentStatus'])->whereNumber('agent')->name('delivery.agents.status');
});
Route::middleware('can:shipping_companies.manage')->group(function (): void {
    Route::get('delivery/carriers', [DeliveryNetworkController::class, 'carriers'])->name('delivery.carriers');
    Route::post('delivery/carriers', [DeliveryNetworkController::class, 'storeCarrier'])->name('delivery.carriers.store');
    Route::put('delivery/carriers/{carrier}', [DeliveryNetworkController::class, 'updateCarrier'])->whereNumber('carrier')->name('delivery.carriers.update');
});
Route::get('delivery/claims', [CarrierClaimController::class, 'index'])->middleware('can:carrier_claims.view')->name('delivery.claims');
Route::middleware('can:carrier_claims.manage')->group(function (): void {
    Route::post('delivery/claims', [CarrierClaimController::class, 'store'])->name('delivery.claims.store');
    Route::post('delivery/claims/{claim}/{action}', [CarrierClaimController::class, 'act'])->whereNumber('claim')->whereIn('action', ['review', 'approve', 'reject', 'compensate'])->name('delivery.claims.act');
});

/*
| Content (WP7, SRS §14–15, ADR 11, ADR 16): page builder, app contexts,
| live & videos (admin-only media), blog, help centre / knowledge hub,
| static pages, footer and notification templates.
*/
Route::middleware('can:pages.view')->group(function (): void {
    Route::get('pages', [PageBuilderController::class, 'index'])->name('pages');
    Route::get('pages/{page}', [PageBuilderController::class, 'show'])->whereNumber('page')->name('pages.show');
});
Route::middleware('can:pages.manage')->group(function (): void {
    Route::post('pages', [PageBuilderController::class, 'store'])->name('pages.store');
    Route::post('pages/{page}/save', [PageBuilderController::class, 'save'])->whereNumber('page')->name('pages.save');
    Route::get('pages/pickers/{kind}', [PageBuilderPickerController::class, 'search'])
        ->whereIn('kind', ['product', 'category', 'brand', 'coupon', 'flash_sale', 'ad_slot', 'stream', 'vendor', 'link'])->middleware('throttle:120,1')->name('pages.picker');
    Route::post('pages/uploads', [PageBuilderPickerController::class, 'upload'])->middleware('throttle:30,1')->name('pages.upload');
    Route::post('pages/{page}/{action}', [PageBuilderController::class, 'act'])->whereNumber('page')
        ->whereIn('action', ['publish', 'schedule', 'draft', 'archive', 'restore', 'copy', 'locales', 'layout', 'duplicate', 'delete'])->name('pages.act');
});
Route::middleware('can:app_contexts.manage')->group(function (): void {
    Route::get('app-contexts', [AppContextController::class, 'index'])->name('app-contexts');
    Route::put('app-contexts/{context}', [AppContextController::class, 'update'])->whereNumber('context')->name('app-contexts.update');
});
Route::middleware('can:live_media.view')->group(function (): void {
    Route::get('media', [LiveMediaController::class, 'index'])->name('media');
    Route::get('media/{stream}', [LiveMediaController::class, 'show'])->whereNumber('stream')->name('media.show');
});
Route::middleware('can:live_media.manage')->group(function (): void {
    Route::post('media', [LiveMediaController::class, 'store'])->name('media.store');
    Route::put('media/{stream}', [LiveMediaController::class, 'update'])->whereNumber('stream')->name('media.update');
    Route::post('media/{stream}/live', [LiveMediaController::class, 'goLive'])->whereNumber('stream')->name('media.live');
    Route::post('media/{stream}/end', [LiveMediaController::class, 'end'])->whereNumber('stream')->name('media.end');
    // D18: the browser studio's WebRTC signalling (offer / ICE per viewer) and heartbeat.
    Route::post('media/{stream}/webrtc/signal', [LiveMediaController::class, 'webrtcSignal'])->whereNumber('stream')->middleware('throttle:600,1')->name('media.webrtc.signal');
    Route::post('media/{stream}/webrtc/online', [LiveMediaController::class, 'webrtcOnline'])->whereNumber('stream')->middleware('throttle:60,1')->name('media.webrtc.online');
    Route::post('media/webrtc-settings', [LiveMediaController::class, 'webrtcSettings'])->name('media.webrtc.settings');
    Route::post('media/{stream}/products', [LiveMediaController::class, 'products'])->whereNumber('stream')->name('media.products');
    Route::post('media/floating', [LiveMediaController::class, 'floating'])->name('media.floating');
    Route::post('media/{stream}/comments/{comment}', [LiveMediaController::class, 'moderateComment'])->whereNumber(['stream', 'comment'])->name('media.comments.moderate');
});
Route::middleware('can:blog.manage')->group(function (): void {
    Route::get('cms', [CmsController::class, 'blog'])->name('cms');
    Route::post('cms/blog/categories', [CmsController::class, 'storeCategory'])->name('cms.blog.categories.store');
    Route::post('cms/blog/posts', [CmsController::class, 'storePost'])->name('cms.blog.posts.store');
    Route::put('cms/blog/posts/{post}', [CmsController::class, 'updatePost'])->whereNumber('post')->name('cms.blog.posts.update');
    Route::post('cms/blog/posts/{post}/{action}', [CmsController::class, 'postAction'])->whereNumber('post')->whereIn('action', ['publish', 'schedule', 'draft', 'archive'])->name('cms.blog.posts.act');
});
Route::middleware('can:help_center.manage')->group(function (): void {
    Route::get('cms/help', [CmsController::class, 'help'])->name('cms.help');
    Route::post('cms/help/collections', [CmsController::class, 'storeCollection'])->name('cms.help.collections.store');
    Route::post('cms/help/articles', [CmsController::class, 'storeArticle'])->name('cms.help.articles.store');
    Route::put('cms/help/articles/{article}', [CmsController::class, 'updateArticle'])->whereNumber('article')->name('cms.help.articles.update');
});
Route::middleware('can:static_pages.manage')->group(function (): void {
    Route::get('cms/static-pages', [CmsController::class, 'staticPages'])->name('cms.static');
    Route::post('cms/static-pages', [CmsController::class, 'saveStaticPage'])->name('cms.static.save');
});
Route::middleware('can:footer.manage')->group(function (): void {
    Route::get('cms/footer', [CmsController::class, 'footer'])->name('cms.footer');
    Route::post('cms/footer', [CmsController::class, 'saveFooter'])->name('cms.footer.save');
});
Route::middleware('can:notification_templates.manage')->group(function (): void {
    Route::get('notification-templates', [NotificationTemplateController::class, 'index'])->name('notification-templates');
    Route::post('notification-templates', [NotificationTemplateController::class, 'store'])->name('notification-templates.store');
    Route::post('notification-templates/preview', [NotificationTemplateController::class, 'preview'])->name('notification-templates.preview');
    Route::post('notification-templates/test', [NotificationTemplateController::class, 'test'])->middleware('throttle:10,1')->name('notification-templates.test');
    Route::post('notification-templates/reset', [NotificationTemplateController::class, 'reset'])->name('notification-templates.reset');
    Route::post('notification-templates/versions/{version}/restore', [NotificationTemplateController::class, 'restore'])->whereNumber('version')->name('notification-templates.restore');
});

/*
| Portal backend (WP8): partner applications review, join options per
| country, advertise requests.
*/
Route::middleware('can:applications.view')->group(function (): void {
    Route::get('applications', [ApplicationReviewController::class, 'index'])->name('applications');
    Route::get('applications/{application}', [ApplicationReviewController::class, 'show'])->whereNumber('application')->name('applications.show');
    Route::get('applications/{application}/documents/{document}', [ApplicationReviewController::class, 'download'])->whereNumber(['application', 'document'])->name('applications.documents.download');
});
Route::middleware('can:applications.approve')->group(function (): void {
    Route::post('applications/{application}/assign', [ApplicationReviewController::class, 'assign'])->whereNumber('application')->name('applications.assign');
    Route::post('applications/{application}/decide', [ApplicationReviewController::class, 'decide'])->whereNumber('application')->name('applications.decide');
    Route::post('applications/{application}/acquirer', [ApplicationReviewController::class, 'acquirer'])->whereNumber('application')->name('applications.acquirer');
    Route::post('applications/{application}/documents/{document}', [ApplicationReviewController::class, 'reviewDocument'])->whereNumber(['application', 'document'])->name('applications.documents.review');
});
Route::get('join-options', [PortalAdminController::class, 'joinOptions'])->middleware('can:join_options.configure')->name('join-options');
Route::put('join-options', [PortalAdminController::class, 'saveJoinOptions'])->middleware('can:join_options.configure')->name('join-options.save');
Route::get('advertise-requests', [PortalAdminController::class, 'inquiries'])->middleware('can:advertise_inquiries.view')->name('advertise-requests');
Route::get('portal-analytics', [PortalAdminController::class, 'analytics'])->middleware('can:applications.view')->name('portal-analytics');
Route::post('advertise-requests/{inquiry}', [PortalAdminController::class, 'moveInquiry'])->whereNumber('inquiry')->middleware('can:advertise_inquiries.manage')->name('advertise-requests.move');

/*
| Reviews & Q&A moderation (G-W3, SRS §7.8): reviews need
| `reviews.moderate`, questions and answers `product_qa.moderate`.
*/
Route::get('reviews', [ReviewModerationController::class, 'index'])->middleware('can:reviews.view')->name('reviews');
Route::post('reviews/reviews/{id}', [ReviewModerationController::class, 'decide'])->whereNumber('id')->defaults('type', 'reviews')->middleware('can:reviews.moderate')->name('reviews.reviews.decide');
Route::post('reviews/replies/{id}', [ReviewModerationController::class, 'decide'])->whereNumber('id')->defaults('type', 'replies')->middleware('can:reviews.moderate')->name('reviews.replies.decide');
Route::middleware('can:product_qa.moderate')->group(function (): void {
    Route::post('reviews/questions/{id}', [ReviewModerationController::class, 'decide'])->whereNumber('id')->defaults('type', 'questions')->name('reviews.questions.decide');
    Route::post('reviews/answers/{id}', [ReviewModerationController::class, 'decide'])->whereNumber('id')->defaults('type', 'answers')->name('reviews.answers.decide');
    Route::post('reviews/questions/{question}/answer', [ReviewModerationController::class, 'answer'])->whereNumber('question')->name('reviews.questions.answer');
});

/*
| QA wave 2 — admin back office (QA-011, QC-002–QC-004, QC-014, QC-019,
| QC-029, QC-030, QC-044). Catalog: categories (tree, attributes, copy to
| other countries), brands, products and variants.
*/
Route::get('catalog', fn () => redirect()->route('admin.catalog.categories'))->middleware('can:categories.view')->name('catalog');
Route::middleware('can:categories.view')->group(function (): void {
    Route::get('catalog/categories', [CategoryAdminController::class, 'index'])->name('catalog.categories');
    Route::get('catalog/categories/{category}', [CategoryAdminController::class, 'show'])->whereNumber('category')->name('catalog.categories.show');
});
Route::middleware('can:categories.manage')->group(function (): void {
    Route::get('catalog/categories/create', [CategoryAdminController::class, 'create'])->name('catalog.categories.create');
    Route::post('catalog/categories', [CategoryAdminController::class, 'store'])->name('catalog.categories.store');
    Route::get('catalog/categories/{category}/edit', [CategoryAdminController::class, 'edit'])->whereNumber('category')->name('catalog.categories.edit');
    Route::put('catalog/categories/{category}', [CategoryAdminController::class, 'update'])->whereNumber('category')->name('catalog.categories.update');
    Route::post('catalog/categories/{category}/attributes', [CategoryAdminController::class, 'storeAttribute'])->whereNumber('category')->name('catalog.categories.attributes.store');
    Route::delete('catalog/attributes/{attribute}', [CategoryAdminController::class, 'destroyAttribute'])->whereNumber('attribute')->name('catalog.attributes.destroy');
    Route::post('catalog/categories/copy', [CategoryAdminController::class, 'copy'])->name('catalog.categories.copy');
});
Route::get('catalog/brands', [BrandAdminController::class, 'index'])->middleware('can:brands.view')->name('catalog.brands');
Route::middleware('can:brands.manage')->group(function (): void {
    Route::post('catalog/brands', [BrandAdminController::class, 'store'])->name('catalog.brands.store');
    Route::get('catalog/brands/{brand}/edit', [BrandAdminController::class, 'edit'])->whereNumber('brand')->name('catalog.brands.edit');
    Route::put('catalog/brands/{brand}', [BrandAdminController::class, 'update'])->whereNumber('brand')->name('catalog.brands.update');
});
Route::middleware('can:products.view')->group(function (): void {
    Route::get('catalog/products', [ProductAdminController::class, 'index'])->name('catalog.products');
    Route::get('catalog/products/{product}', [ProductAdminController::class, 'show'])->whereNumber('product')->name('catalog.products.show');
});
Route::middleware('can:products.update')->group(function (): void {
    Route::get('catalog/products/{product}/edit', [ProductAdminController::class, 'edit'])->whereNumber('product')->name('catalog.products.edit');
    Route::put('catalog/products/{product}', [ProductAdminController::class, 'update'])->whereNumber('product')->name('catalog.products.update');
    Route::put('catalog/variants/{variant}', [ProductAdminController::class, 'updateVariant'])->whereNumber('variant')->name('catalog.variants.update');
});

/*
| Coupons (QC-014), gift cards and vouchers, Nawy One plans and subscriptions.
*/
Route::middleware('can:coupons.view')->group(function (): void {
    Route::get('coupons', [CouponAdminController::class, 'index'])->name('coupons');
    Route::get('coupons/{coupon}', [CouponAdminController::class, 'show'])->whereNumber('coupon')->name('coupons.show');
});
Route::middleware('can:coupons.manage')->group(function (): void {
    Route::get('coupons/create', [CouponAdminController::class, 'create'])->name('coupons.create');
    Route::post('coupons', [CouponAdminController::class, 'store'])->name('coupons.store');
    Route::get('coupons/{coupon}/edit', [CouponAdminController::class, 'edit'])->whereNumber('coupon')->name('coupons.edit');
    Route::put('coupons/{coupon}', [CouponAdminController::class, 'update'])->whereNumber('coupon')->name('coupons.update');
    Route::post('coupons/{coupon}/toggle', [CouponAdminController::class, 'toggle'])->whereNumber('coupon')->name('coupons.toggle');
});
Route::middleware('can:gift_cards.view')->group(function (): void {
    Route::get('gift-cards', [GiftCardAdminController::class, 'index'])->name('gift-cards');
    Route::get('gift-cards/{card}', [GiftCardAdminController::class, 'show'])->whereNumber('card')->name('gift-cards.show');
    Route::get('gift-cards/vouchers', [GiftCardAdminController::class, 'vouchers'])->name('gift-cards.vouchers');
    Route::get('gift-cards/vouchers/{voucher}', [GiftCardAdminController::class, 'voucher'])->whereNumber('voucher')->name('gift-cards.vouchers.show');
});
Route::middleware('can:gift_cards.manage')->group(function (): void {
    Route::post('gift-cards', [GiftCardAdminController::class, 'issue'])->name('gift-cards.issue');
    Route::post('gift-cards/{card}/void', [GiftCardAdminController::class, 'void'])->whereNumber('card')->name('gift-cards.void');
    Route::post('gift-cards/vouchers', [GiftCardAdminController::class, 'storeVoucher'])->name('gift-cards.vouchers.store');
    Route::post('gift-cards/vouchers/{voucher}/void', [GiftCardAdminController::class, 'voidVoucher'])->whereNumber('voucher')->name('gift-cards.vouchers.void');
});
Route::middleware('can:membership.view')->group(function (): void {
    Route::get('membership', [MembershipAdminController::class, 'plans'])->name('membership');
    Route::get('membership/subscriptions', [MembershipAdminController::class, 'subscriptions'])->name('membership.subscriptions');
    Route::get('membership/subscriptions/{membership}', [MembershipAdminController::class, 'subscription'])->whereNumber('membership')->name('membership.subscriptions.show');
});
Route::middleware('can:membership.manage')->group(function (): void {
    Route::post('membership/plans', [MembershipAdminController::class, 'store'])->name('membership.plans.store');
    Route::get('membership/plans/{plan}/edit', [MembershipAdminController::class, 'edit'])->whereNumber('plan')->name('membership.plans.edit');
    Route::put('membership/plans/{plan}', [MembershipAdminController::class, 'update'])->whereNumber('plan')->name('membership.plans.update');
});

/*
| Refunds approval queue (QC-029) and payments held under review (QA-009
| follow-up): resolved through the refund and payment state machines.
*/
Route::middleware('can:orders.refund')->group(function (): void {
    Route::get('refunds', [RefundAdminController::class, 'index'])->name('refunds');
    Route::get('refunds/{refund}', [RefundAdminController::class, 'show'])->whereNumber('refund')->name('refunds.show');
    Route::post('refunds/{refund}/approve', [RefundAdminController::class, 'approve'])->whereNumber('refund')->name('refunds.approve');
    Route::post('refunds/{refund}/reject', [RefundAdminController::class, 'reject'])->whereNumber('refund')->name('refunds.reject');
    Route::post('refunds/{refund}/retry', [RefundAdminController::class, 'retry'])->whereNumber('refund')->name('refunds.retry');
    Route::post('orders/{order}/refunds', [RefundAdminController::class, 'store'])->whereNumber('order')->name('refunds.store');
});
Route::middleware('can:payments.review')->group(function (): void {
    Route::get('finance/payments-review', [PaymentReviewController::class, 'index'])->name('finance.payments-review');
    Route::get('finance/payments-review/{payment}', [PaymentReviewController::class, 'show'])->whereNumber('payment')->name('finance.payments-review.show');
    Route::post('finance/payments-review/{payment}', [PaymentReviewController::class, 'resolve'])->whereNumber('payment')->name('finance.payments-review.resolve');
});

/*
| Warranty claims (QC-002): admin handles extended-plan claims and can step
| into standard ones; linked to disputes on the same shipment.
*/
Route::middleware('can:warranty_claims.view')->group(function (): void {
    Route::get('warranty-claims', [WarrantyClaimAdminController::class, 'index'])->name('warranty-claims');
    Route::get('warranty-claims/{claim}', [WarrantyClaimAdminController::class, 'show'])->whereNumber('claim')->name('warranty-claims.show');
});
Route::middleware('can:warranty_claims.manage')->group(function (): void {
    Route::post('warranty-claims/{claim}/messages', [WarrantyClaimAdminController::class, 'message'])->whereNumber('claim')->middleware('throttle:30,1')->name('warranty-claims.message');
    Route::post('warranty-claims/{claim}/{action}', [WarrantyClaimAdminController::class, 'act'])->whereNumber('claim')
        ->whereIn('action', ['review', 'approve', 'reject', 'fulfil', 'resolve', 'close'])->name('warranty-claims.act');
});

/*
| Users & vendors: vendors with account-manager assignment (QC-019); staff,
| invitations and roles (QC-004).
*/
Route::middleware('can:vendors.view')->group(function (): void {
    Route::get('users', fn () => redirect()->route('admin.vendors'))->name('users');
    Route::get('vendors', [VendorAdminController::class, 'index'])->name('vendors');
    Route::get('vendors/{vendor}', [VendorAdminController::class, 'show'])->whereNumber('vendor')->name('vendors.show');
});
// QC-009: exclusive real-estate contracts (matrix "marketers … exclusive").
Route::middleware('can:marketers.view')->group(function (): void {
    Route::get('real-estate', [ExclusiveContractAdminController::class, 'index'])->name('real-estate');
    Route::get('real-estate/exclusive-contracts/{contract}/file', [ExclusiveContractAdminController::class, 'file'])->whereNumber('contract')->name('real-estate.file');
});
Route::middleware('can:marketers.manage')->group(function (): void {
    Route::post('real-estate/exclusive-contracts', [ExclusiveContractAdminController::class, 'store'])->name('real-estate.store');
    Route::post('real-estate/exclusive-contracts/{contract}/revoke', [ExclusiveContractAdminController::class, 'revoke'])->whereNumber('contract')->name('real-estate.revoke');
});

// QC-016: customer accounts (active ⇄ suspended, banned).
Route::get('customers', [CustomerAdminController::class, 'index'])->middleware('can:customers.view')->name('customers');
Route::post('customers/{user}/status', [CustomerAdminController::class, 'status'])->whereNumber('user')->middleware('can:customers.update')->name('customers.status');
Route::post('vendors/{vendor}/status', [VendorAdminController::class, 'status'])->whereNumber('vendor')->middleware('can:vendors.update')->name('vendors.status');
Route::post('vendors/{vendor}/account-manager', [VendorAdminController::class, 'accountManager'])->whereNumber('vendor')->middleware('can:vendors.assign_account_manager')->name('vendors.account-manager');
Route::post('vendors/{vendor}/acquirer', [VendorAdminController::class, 'acquirer'])->whereNumber('vendor')->middleware('can:vendors.assign_account_manager')->name('vendors.acquirer');
Route::middleware('can:admins.view')->group(function (): void {
    Route::get('staff', [StaffController::class, 'index'])->name('staff');
    Route::get('staff/{user}', [StaffController::class, 'show'])->whereNumber('user')->name('staff.show');
});
Route::middleware('can:admins.manage')->group(function (): void {
    Route::post('staff/invitations', [StaffController::class, 'invite'])->name('staff.invite');
    Route::delete('staff/invitations/{invitation}', [StaffController::class, 'revokeInvitation'])->whereNumber('invitation')->name('staff.invitations.revoke');
    Route::put('staff/{user}/roles', [StaffController::class, 'roles'])->whereNumber('user')->name('staff.roles');
    Route::post('staff/{user}/status', [StaffController::class, 'status'])->whereNumber('user')->name('staff.status');
});
Route::get('roles', [RoleAdminController::class, 'index'])->middleware('can:roles.view')->name('roles');
Route::middleware('can:roles.manage')->group(function (): void {
    Route::post('roles', [RoleAdminController::class, 'store'])->name('roles.store');
    Route::put('roles/{role}', [RoleAdminController::class, 'update'])->whereNumber('role')->name('roles.update');
});

/*
| Activity log (QC-044) and system health (Figma 99:3364).
*/
Route::get('activity', [ActivityLogController::class, 'index'])->middleware('can:activity_log.view')->name('activity');
Route::get('health', [SystemHealthController::class, 'index'])->middleware('can:system_health.view')->name('health');
Route::middleware('can:system_health.manage')->group(function (): void {
    Route::post('health/failed-jobs/{uuid}/retry', [SystemHealthController::class, 'retry'])->whereUuid('uuid')->name('health.retry');
    Route::delete('health/failed-jobs/{uuid}', [SystemHealthController::class, 'forget'])->whereUuid('uuid')->name('health.forget');
});

/*
| Delivery agents created by admin go through the team invitation (wave 1).
*/
Route::middleware('can:delivery_agents.manage')->group(function (): void {
    Route::post('delivery/agents/invitations/{invitation}/revoke', [DeliveryNetworkController::class, 'revokeInvitation'])->whereNumber('invitation')->name('delivery.agents.invitations.revoke');
});

/*
| WP11 extras — Data migration (super admin + Ops, `data_migration.*`): runs,
| quarantine review and re-tries, legacy records, redirects, reconciliation.
*/
Route::prefix('migration')->name('migration.')->group(function (): void {
    Route::middleware('can:data_migration.view')->group(function (): void {
        Route::get('/', fn () => redirect()->route('admin.migration.quarantine'))->name('index');
        Route::get('runs', [MigrationRunController::class, 'index'])->name('runs');
        Route::get('runs/{run}', [MigrationRunController::class, 'show'])->whereNumber('run')->name('runs.show');
        Route::get('quarantine', [QuarantineController::class, 'index'])->name('quarantine');
        Route::get('quarantine/{entry}', [QuarantineController::class, 'show'])->whereNumber('entry')->name('quarantine.show');
        Route::get('jobs/{job}', [MigrationJobController::class, 'show'])->whereNumber('job')->name('jobs.show');
        Route::get('records', [LegacyRecordController::class, 'index'])->name('records');
        Route::get('records/{database}/{record}', [LegacyRecordController::class, 'show'])->where('database', '[a-z]{3,7}')->whereNumber('record')->name('records.show');
        Route::get('redirects', [LegacyRedirectAdminController::class, 'index'])->name('redirects');
        Route::get('redirects/export.{format}', [LegacyRedirectAdminController::class, 'export'])->whereIn('format', ['json', 'map'])->name('redirects.export');
        Route::get('reconciliation', [ReconciliationController::class, 'show'])->name('reconciliation');
    });
    Route::middleware('can:data_migration.manage')->group(function (): void {
        Route::post('quarantine/{entry}/reveal', [QuarantineController::class, 'reveal'])->whereNumber('entry')->middleware('throttle:30,1')->name('quarantine.reveal');
        Route::post('quarantine/{entry}/review', [QuarantineController::class, 'review'])->whereNumber('entry')->name('quarantine.review');
        Route::post('quarantine/{entry}/preview', [QuarantineController::class, 'preview'])->whereNumber('entry')->middleware('throttle:20,1')->name('quarantine.preview');
        Route::post('retries', [MigrationJobController::class, 'store'])->middleware('throttle:10,1')->name('retries.store');
        Route::post('jobs/{job}/apply', [MigrationJobController::class, 'apply'])->whereNumber('job')->middleware('throttle:10,1')->name('jobs.apply');
        Route::post('records/{database}/{record}/reveal', [LegacyRecordController::class, 'show'])->where('database', '[a-z]{3,7}')->whereNumber('record')->middleware('throttle:30,1')->name('records.reveal');
        Route::post('redirects/rebuild', [LegacyRedirectAdminController::class, 'rebuild'])->middleware('throttle:5,1')->name('redirects.rebuild');
        Route::post('reconciliation', [ReconciliationController::class, 'run'])->middleware('throttle:5,1')->name('reconciliation.run');
    });
});

/*
| Inventory & fulfilment (Fulfilled by Nawy): overview, warehouses, stock and
| adjustments, inbound receiving, pick & pack, transfers, removals, fees.
*/
Route::middleware('can:fbn.view')->group(function (): void {
    Route::get('inventory', [FbnAdminController::class, 'overview'])->name('inventory');
    Route::get('inventory/warehouses', [FbnAdminController::class, 'warehouses'])->name('inventory.warehouses');
    Route::get('inventory/stock', [FbnAdminController::class, 'stock'])->name('inventory.stock');
    Route::get('inventory/inbound', [FbnOperationsController::class, 'inbound'])->name('inventory.inbound');
    Route::get('inventory/inbound/{shipment}', [FbnOperationsController::class, 'showInbound'])->whereNumber('shipment')->name('inventory.inbound.show');
    Route::get('inventory/pick-pack', [FbnOperationsController::class, 'pickPack'])->name('inventory.pick-pack');
    Route::get('inventory/transfers', [FbnOperationsController::class, 'transfers'])->name('inventory.transfers');
    Route::get('inventory/removals', [FbnOperationsController::class, 'removals'])->name('inventory.removals');
    Route::get('inventory/fees', [FbnAdminController::class, 'fees'])->name('inventory.fees');
});
Route::middleware('can:fbn.operate')->group(function (): void {
    Route::post('inventory/stock/adjust', [FbnAdminController::class, 'adjust'])->name('inventory.stock.adjust');
    Route::post('inventory/inbound/{shipment}/{action}', [FbnOperationsController::class, 'actInbound'])->whereNumber('shipment')->whereIn('action', ['arrive', 'receive', 'finish', 'close', 'cancel'])->name('inventory.inbound.act');
    Route::post('inventory/inbound/lines/{line}/receive', [FbnOperationsController::class, 'receiveLine'])->whereNumber('line')->name('inventory.inbound.lines.receive');
    Route::post('inventory/pick-pack/{subOrder}', [FbnOperationsController::class, 'advanceOrder'])->whereNumber('subOrder')->name('inventory.pick-pack.advance');
    Route::post('inventory/transfers', [FbnOperationsController::class, 'storeTransfer'])->name('inventory.transfers.store');
    Route::post('inventory/transfers/{transfer}/{action}', [FbnOperationsController::class, 'actTransfer'])->whereNumber('transfer')->whereIn('action', ['dispatch', 'receive', 'cancel'])->name('inventory.transfers.act');
    Route::post('inventory/removals/{removal}/{action}', [FbnOperationsController::class, 'actRemoval'])->whereNumber('removal')->whereIn('action', ['process', 'complete', 'reject'])->name('inventory.removals.act');
});
Route::middleware('can:fbn.configure')->group(function (): void {
    Route::post('inventory/warehouses', [FbnAdminController::class, 'storeWarehouse'])->name('inventory.warehouses.store');
    Route::put('inventory/warehouses/{warehouse}', [FbnAdminController::class, 'updateWarehouse'])->whereNumber('warehouse')->name('inventory.warehouses.update');
    Route::put('inventory/fees', [FbnAdminController::class, 'updateFees'])->name('inventory.fees.update');
});

/*
| Reports (FR-CTY-10, FR-ANL-2): cross-country figures from the reporting
| database only; the celebrity (influencer) monthly report of the country.
*/
Route::get('reports/cross-country', [CrossCountryReportController::class, 'index'])->middleware('can:reports.view')->name('reports.cross-country');
Route::post('reports/cross-country/run', [CrossCountryReportController::class, 'run'])->middleware(['can:reports.view', 'throttle:6,1'])->name('reports.cross-country.run');
Route::middleware('can:celebrity_reports.view')->group(function (): void {
    Route::get('marketers/celebrity-reports', [CelebrityReportAdminController::class, 'index'])->name('marketers.celebrity-reports');
    Route::get('marketers/celebrity-reports/{report}', [CelebrityReportAdminController::class, 'show'])->whereNumber('report')->name('marketers.celebrity-reports.show');
    Route::get('marketers/celebrity-reports/{report}/pdf', [CelebrityReportAdminController::class, 'pdf'])->whereNumber('report')->name('marketers.celebrity-reports.pdf');
});
Route::post('marketers/celebrity-reports/regenerate', [CelebrityReportAdminController::class, 'regenerate'])->middleware(['can:marketers.manage', 'throttle:10,1'])->name('marketers.celebrity-reports.regenerate');

/*
| QC-016 lifecycles: product requests (§1.3), profile change requests
| (§7.3), packaging catalogue and orders (§6.3), flash-sale marketer
| invites (§4.10).
*/
Route::middleware('can:product_requests.view')->group(function (): void {
    Route::get('product-requests', [ProductRequestAdminController::class, 'index'])->name('product-requests');
    Route::get('product-requests/{productRequest}', [ProductRequestAdminController::class, 'show'])->whereNumber('productRequest')->name('product-requests.show');
});
Route::middleware('can:product_requests.review')->group(function (): void {
    Route::post('product-requests/{productRequest}/approve', [ProductRequestAdminController::class, 'approve'])->whereNumber('productRequest')->name('product-requests.approve');
    Route::post('product-requests/{productRequest}/reject', [ProductRequestAdminController::class, 'reject'])->whereNumber('productRequest')->name('product-requests.reject');
});
Route::middleware('can:change_requests.view')->group(function (): void {
    Route::get('change-requests', [ChangeRequestAdminController::class, 'index'])->name('change-requests');
    Route::get('change-requests/{changeRequest}', [ChangeRequestAdminController::class, 'show'])->whereNumber('changeRequest')->name('change-requests.show');
});
Route::middleware('can:change_requests.review')->group(function (): void {
    Route::post('change-requests/{changeRequest}/approve', [ChangeRequestAdminController::class, 'approve'])->whereNumber('changeRequest')->name('change-requests.approve');
    Route::post('change-requests/{changeRequest}/reject', [ChangeRequestAdminController::class, 'reject'])->whereNumber('changeRequest')->name('change-requests.reject');
});
Route::middleware('can:packaging.view')->group(function (): void {
    Route::get('packaging', [PackagingAdminController::class, 'supplies'])->name('packaging');
    Route::get('packaging/orders', [PackagingAdminController::class, 'orders'])->name('packaging.orders');
    Route::get('packaging/orders/{order}', [PackagingAdminController::class, 'order'])->whereNumber('order')->name('packaging.orders.show');
});
Route::middleware('can:packaging.manage')->group(function (): void {
    Route::post('packaging/supplies', [PackagingAdminController::class, 'storeSupply'])->name('packaging.supplies.store');
    Route::get('packaging/supplies/{supply}/edit', [PackagingAdminController::class, 'editSupply'])->whereNumber('supply')->name('packaging.supplies.edit');
    Route::put('packaging/supplies/{supply}', [PackagingAdminController::class, 'updateSupply'])->whereNumber('supply')->name('packaging.supplies.update');
    Route::post('packaging/delivery-fee', [PackagingAdminController::class, 'deliveryFee'])->name('packaging.delivery-fee');
    Route::post('packaging/orders/{order}/{action}', [PackagingAdminController::class, 'move'])->whereNumber('order')->whereIn('action', ['approve', 'reject', 'ship', 'deliver'])->name('packaging.orders.move');
});
Route::post('flash-sales/{event}/marketer-invites', [FlashSaleEventController::class, 'inviteMarketers'])->whereNumber('event')->middleware(['can:flash_sales.manage', 'throttle:20,1'])->name('flash-sales.marketer-invites');

/*
| Email system: Admin → Platform → Email log (delivery log, resend,
| suppression list).
*/
Route::middleware('can:system_health.view')->group(function (): void {
    Route::get('email-log', [EmailLogController::class, 'index'])->name('email-log');
});
Route::middleware('can:system_health.manage')->group(function (): void {
    Route::post('email-log/{log}/resend', [EmailLogController::class, 'resend'])->whereNumber('log')->middleware('throttle:30,1')->name('email-log.resend');
    Route::post('email-log/suppressions', [EmailLogController::class, 'suppress'])->name('email-log.suppress');
    Route::delete('email-log/suppressions/{suppression}', [EmailLogController::class, 'lift'])->whereNumber('suppression')->name('email-log.suppressions.lift');
});
