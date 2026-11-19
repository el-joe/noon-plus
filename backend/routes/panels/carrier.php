<?php

/*
| Shipping panel (carrier.) — SRS §4.7, role-permission matrix §8. Loaded
| inside the panel group; every page is scoped to the supervisor's company
| (ResolveCarrier). Owner-only pages use ResolveCarrier:owner.
*/

use App\Domains\Delivery\Http\Controllers\Carrier\CarrierPanelController;
use App\Domains\Delivery\Http\Controllers\Carrier\CarrierPayController;
use App\Domains\Delivery\Http\Middleware\ResolveCarrier;
use App\Domains\Support\Http\Controllers\Panel\PartnerSupportTicketController;
use Illuminate\Support\Facades\Route;

Route::middleware(ResolveCarrier::class)->group(function (): void {
    /*
    | Support tickets (QC-007): the team's tickets with Nawy support.
    */
    Route::middleware('tenant.can:carrier,support')->group(function (): void {
        Route::get('support', [PartnerSupportTicketController::class, 'index'])->name('support');
        Route::post('support', [PartnerSupportTicketController::class, 'store'])->middleware('throttle:10,1')->name('support.store');
        Route::get('support/{ticket}', [PartnerSupportTicketController::class, 'show'])->whereNumber('ticket')->name('support.show');
        Route::post('support/{ticket}/reply', [PartnerSupportTicketController::class, 'reply'])->whereNumber('ticket')->middleware('throttle:30,1')->name('support.reply');
        Route::post('support/{ticket}/reopen', [PartnerSupportTicketController::class, 'reopen'])->whereNumber('ticket')->middleware('throttle:10,1')->name('support.reopen');
        Route::post('support/{ticket}/rate', [PartnerSupportTicketController::class, 'rate'])->whereNumber('ticket')->middleware('throttle:10,1')->name('support.rate');
    });
    Route::get('dispatch', [CarrierPanelController::class, 'dispatchBoard'])->middleware('tenant.can:carrier,dispatch')->name('dispatch');
    Route::get('pickups', [CarrierPanelController::class, 'pickups'])->middleware('tenant.can:carrier,dispatch')->name('pickups');
    Route::post('pickups/{shipment}/assign', [CarrierPanelController::class, 'assign'])->whereNumber('shipment')->middleware('throttle:60,1')->middleware('tenant.can:carrier,dispatch')->name('pickups.assign');
    Route::get('shipments', [CarrierPanelController::class, 'shipments'])->middleware('tenant.can:carrier,dispatch')->name('shipments');
    Route::post('shipments/{shipment}/{action}', [CarrierPanelController::class, 'shipmentAction'])->whereNumber('shipment')
        ->whereIn('action', ['reschedule', 'return', 'returned'])->middleware('throttle:60,1')->name('shipments.act');
    Route::get('assignments', [CarrierPanelController::class, 'assignments'])->middleware('tenant.can:carrier,dispatch')->name('assignments');
    Route::post('assignments/{assignment}/reassign', [CarrierPanelController::class, 'reassign'])->whereNumber('assignment')->middleware('tenant.can:carrier,dispatch')->name('assignments.reassign');
    Route::get('exceptions', [CarrierPanelController::class, 'exceptions'])->middleware('tenant.can:carrier,dispatch')->name('exceptions');
    Route::get('agents', [CarrierPanelController::class, 'agents'])->middleware('tenant.can:carrier,agents')->name('agents');
    Route::post('agents', [CarrierPanelController::class, 'storeAgent'])->middleware('throttle:20,1')->middleware('tenant.can:carrier,agents')->name('agents.store');
    Route::post('agents/{agent}/status', [CarrierPanelController::class, 'agentStatus'])->whereNumber('agent')->middleware('tenant.can:carrier,agents')->name('agents.status');
    Route::get('cod', [CarrierPanelController::class, 'cod'])->middleware('tenant.can:carrier,cod')->name('cod');
    Route::get('claims', [CarrierPanelController::class, 'claims'])->middleware('tenant.can:carrier,claims')->name('claims');
    Route::post('claims/{claim}/respond', [CarrierPanelController::class, 'respondClaim'])->whereNumber('claim')->middleware('tenant.can:carrier,claims')->name('claims.respond');
    Route::get('company', [CarrierPanelController::class, 'company'])->middleware('tenant.can:carrier,company')->name('company');
    Route::get('withdrawals', [CarrierPayController::class, 'withdrawals'])->middleware('tenant.can:carrier,payouts')->name('withdrawals');
    Route::post('withdrawals/{withdrawal}', [CarrierPayController::class, 'updateWithdrawal'])->whereNumber('withdrawal')->middleware('throttle:30,1')->middleware('tenant.can:carrier,payouts')->name('withdrawals.update');
    Route::get('driver-documents', [CarrierPayController::class, 'documents'])->middleware('tenant.can:carrier,agents')->name('driver-documents');
    Route::get('shipments/{shipment}/label', [CarrierPayController::class, 'label'])->whereNumber('shipment')->middleware('tenant.can:carrier,dispatch')->name('shipments.label');
    Route::get('shipments/{shipment}/label.pdf', [CarrierPayController::class, 'label'])->whereNumber('shipment')->middleware('tenant.can:carrier,dispatch')->name('shipments.label-pdf');
});

Route::middleware(ResolveCarrier::class.':owner')->group(function (): void {
    Route::post('supervisors', [CarrierPanelController::class, 'storeSupervisor'])->middleware('throttle:20,1')->middleware('tenant.can:carrier,supervisors')->name('supervisors.store');
    Route::put('company', [CarrierPanelController::class, 'updateCompany'])->middleware('tenant.can:carrier,company')->name('company.update');
});
