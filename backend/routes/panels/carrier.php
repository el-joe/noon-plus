<?php

/*
| Shipping panel (carrier.) — SRS §4.7, role-permission matrix §8. Loaded
| inside the panel group; every page is scoped to the supervisor's company
| (ResolveCarrier). Owner-only pages use ResolveCarrier:owner.
*/

use App\Domains\Delivery\Http\Controllers\Carrier\CarrierPanelController;
use App\Domains\Delivery\Http\Middleware\ResolveCarrier;
use Illuminate\Support\Facades\Route;

Route::middleware(ResolveCarrier::class)->group(function (): void {
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
});

Route::middleware(ResolveCarrier::class.':owner')->group(function (): void {
    Route::post('supervisors', [CarrierPanelController::class, 'storeSupervisor'])->middleware('throttle:20,1')->middleware('tenant.can:carrier,supervisors')->name('supervisors.store');
    Route::put('company', [CarrierPanelController::class, 'updateCompany'])->middleware('tenant.can:carrier,company')->name('company.update');
});
