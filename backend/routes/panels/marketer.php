<?php

/*
| Creator Studio (marketer.) — SRS §4.4, role-permission matrix §7. Loaded
| inside the panel group: signed-in marketer member, country selected,
| workspace marketer bound (pages refuse without a profile).
*/

use App\Domains\Marketers\Http\Controllers\Studio\CreatorStudioController;
use App\Domains\Marketers\Http\Controllers\Studio\MarketerProfileController;
use App\Domains\RealEstate\Http\Controllers\Studio\BrokerStudioController;
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
Route::get('broker/special-requests', [BrokerStudioController::class, 'specialRequests'])->middleware('tenant.can:marketer,broker')->name('broker.special-requests');
Route::post('broker/special-requests/{special}/claim', [BrokerStudioController::class, 'claim'])->whereNumber('special')->middleware('tenant.can:marketer,broker')->name('broker.special-requests.claim');
Route::post('broker/special-requests/{special}/finish', [BrokerStudioController::class, 'finish'])->whereNumber('special')->middleware('tenant.can:marketer,broker')->name('broker.special-requests.finish');
Route::get('broker/properties', [BrokerStudioController::class, 'properties'])->middleware('tenant.can:marketer,broker')->name('broker.properties');

Route::get('profile', [MarketerProfileController::class, 'show'])->middleware('tenant.can:marketer,profile')->name('profile');
Route::put('profile', [MarketerProfileController::class, 'update'])->middleware('tenant.can:marketer,profile')->name('profile.update');
