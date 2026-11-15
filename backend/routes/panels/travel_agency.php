<?php

/*
| Travel Partner (travel-agency.) — SRS §4.5. Loaded inside the panel group:
| signed-in agency member, country selected; every page needs the agency
| of that country and is scoped to it.
*/

use App\Domains\Panels\Http\Controllers\TeamController;
use App\Domains\Travel\Http\Controllers\Panel\TravelPartnerController;
use App\Domains\Travel\Http\Controllers\Panel\TravelReportsController;
use App\Domains\Travel\Http\Middleware\ResolveTravelAgency;
use Illuminate\Support\Facades\Route;

Route::middleware(ResolveTravelAgency::class.':required')->group(function (): void {

    /*
    | Team & permissions (QC-020): owner only.
    */
    Route::middleware('tenant.can:travel_agency,team')->group(function (): void {
        Route::get('team', [TeamController::class, 'index'])->defaults('panel', 'travel_agency')->name('team');
        Route::post('team/invitations', [TeamController::class, 'invite'])->defaults('panel', 'travel_agency')->middleware('throttle:20,1')->name('team.invite');
        Route::post('team/invitations/{invitation}/revoke', [TeamController::class, 'revoke'])->whereNumber('invitation')->defaults('panel', 'travel_agency')->name('team.invitations.revoke');
        Route::put('team/{membership}', [TeamController::class, 'updateRole'])->whereNumber('membership')->defaults('panel', 'travel_agency')->name('team.role');
        Route::delete('team/{membership}', [TeamController::class, 'destroy'])->whereNumber('membership')->defaults('panel', 'travel_agency')->name('team.destroy');
    });
    Route::get('packages', [TravelPartnerController::class, 'packages'])->middleware('tenant.can:travel_agency,packages')->name('packages');
    Route::get('reports', TravelReportsController::class)->middleware('tenant.can:travel_agency,reports')->name('reports');
    Route::get('packages/create', [TravelPartnerController::class, 'createPackage'])->middleware('tenant.can:travel_agency,packages')->name('packages.create');
    Route::post('packages', [TravelPartnerController::class, 'storePackage'])->middleware('tenant.can:travel_agency,packages')->name('packages.store');
    Route::get('packages/{package}', [TravelPartnerController::class, 'editPackage'])->whereNumber('package')->middleware('tenant.can:travel_agency,packages')->name('packages.edit');
    Route::put('packages/{package}', [TravelPartnerController::class, 'updatePackage'])->whereNumber('package')->middleware('tenant.can:travel_agency,packages')->name('packages.update');
    Route::post('packages/{package}/departures', [TravelPartnerController::class, 'addDeparture'])->whereNumber('package')->middleware('tenant.can:travel_agency,packages')->name('packages.departures.store');
    Route::post('packages/{package}/submit', [TravelPartnerController::class, 'submitPackage'])->whereNumber('package')->middleware('tenant.can:travel_agency,packages')->name('packages.submit');

    Route::get('departures', [TravelPartnerController::class, 'departures'])->middleware('tenant.can:travel_agency,packages')->name('departures');
    Route::post('departures/{departure}/capacity', [TravelPartnerController::class, 'resizeDeparture'])->whereNumber('departure')->middleware('tenant.can:travel_agency,packages')->name('departures.resize');

    Route::get('bookings', [TravelPartnerController::class, 'bookings'])->middleware('tenant.can:travel_agency,bookings')->name('bookings');
    Route::post('bookings/{booking}/confirm', [TravelPartnerController::class, 'confirmBooking'])->whereNumber('booking')->middleware('tenant.can:travel_agency,bookings')->name('bookings.confirm');
    Route::post('bookings/{booking}/reject', [TravelPartnerController::class, 'rejectBooking'])->whereNumber('booking')->middleware('tenant.can:travel_agency,bookings')->name('bookings.reject');
    Route::post('bookings/{booking}/cancel', [TravelPartnerController::class, 'cancelBooking'])->whereNumber('booking')->middleware('tenant.can:travel_agency,bookings')->name('bookings.cancel');
    Route::post('modifications/{modification}/decide', [TravelPartnerController::class, 'decideModification'])->whereNumber('modification')->middleware('tenant.can:travel_agency,bookings')->name('modifications.decide');

    Route::get('inquiries', [TravelPartnerController::class, 'inquiries'])->middleware('tenant.can:travel_agency,inquiries')->name('inquiries');
    Route::post('inquiries/{inquiry}/status', [TravelPartnerController::class, 'moveInquiry'])->whereNumber('inquiry')->middleware('tenant.can:travel_agency,inquiries')->name('inquiries.move');
});
