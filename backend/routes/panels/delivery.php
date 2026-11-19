<?php

/*
| Delivery agent web panel (delivery.) — SRS §4.6, ADR 15. Loaded inside the
| panel group; every page needs an active agent profile in the country and
| only reaches the agent's own assignments.
*/

use App\Domains\Delivery\Http\Controllers\Panel\DeliveryPanelController;
use App\Domains\Delivery\Http\Middleware\EnsureDeliveryAgent;
use Illuminate\Support\Facades\Route;

Route::middleware(EnsureDeliveryAgent::class)->group(function (): void {
    Route::post('availability', [DeliveryPanelController::class, 'availability'])->name('availability');
    Route::get('assignments', [DeliveryPanelController::class, 'index'])->name('assignments');
    Route::get('assignments/{number}', [DeliveryPanelController::class, 'show'])->name('assignments.show');
    Route::post('assignments/{number}/{action}', [DeliveryPanelController::class, 'act'])
        ->whereIn('action', ['accept', 'decline', 'start', 'arrive', 'proof', 'confirm', 'fail'])
        ->middleware('throttle:60,1')->name('assignments.act');
    Route::get('cod', [DeliveryPanelController::class, 'cod'])->name('cod');
    Route::get('earnings', [DeliveryPanelController::class, 'earnings'])->name('earnings');
    Route::get('payslips/{number}', [DeliveryPanelController::class, 'payslip'])->name('payslips.show');
    Route::get('payslips/{number}/pdf', [DeliveryPanelController::class, 'payslipPdf'])->middleware('throttle:30,1')->name('payslips.pdf');
    Route::post('withdrawals', [DeliveryPanelController::class, 'withdraw'])->middleware('throttle:10,1')->name('withdrawals.store');
    Route::post('withdrawals/{number}/cancel', [DeliveryPanelController::class, 'cancelWithdrawal'])->middleware('throttle:10,1')->name('withdrawals.cancel');
    Route::get('profile', [DeliveryPanelController::class, 'profile'])->name('profile');
    Route::put('profile', [DeliveryPanelController::class, 'updateProfile'])->name('profile.update');
    Route::get('documents', [DeliveryPanelController::class, 'documents'])->name('documents');
    Route::post('documents', [DeliveryPanelController::class, 'storeDocument'])->middleware('throttle:20,1')->name('documents.store');
});
