<?php

use App\Domains\Countries\Models\Country;
use App\Domains\Customers\Models\CustomerNotification;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Panels\Support\PanelCountries;
use App\Domains\Vendors\Enums\ChangeRequestStatus;
use App\Domains\Vendors\Models\ChangeRequest;
use App\Domains\Vendors\Models\Vendor;
use App\Models\User;
use App\Shared\StateMachine\StateTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

/*
| QC-016: change requests for locked profile sections (lifecycle catalogue §7.3).
*/

beforeEach(function () {
    $this->setUpCountries();
    $this->withoutVite();
    $this->country = $this->actAsCountry('uae');
    Notification::fake();
});

it('lets a store ask to change its legal details, and an admin approval applies them with an audit diff', function () {
    [$seller, $vendor] = sellerAccount('uae', ['business_details' => ['store' => ['legal_name' => 'Old Trading LLC', 'cr_number' => 'CR-1', 'tax_id' => null]]]);
    $admin = adminAccount();

    $this->actingAs($seller)->get(onPanel('partner', 'change-requests'))->assertOk()->assertSee('Old Trading LLC');
    $this->post(onPanel('partner', 'change-requests'), ['section' => 'legal_details', 'values' => ['legal_name' => 'Old Trading LLC', 'cr_number' => 'CR-1']])
        ->assertSessionHasErrors('action');
    $this->post(onPanel('partner', 'change-requests'), ['section' => 'legal_details', 'values' => ['legal_name' => 'New Trading LLC', 'cr_number' => 'CR-1'], 'note' => 'Renamed'])
        ->assertSessionHasNoErrors();
    $this->post(onPanel('partner', 'change-requests'), ['section' => 'legal_details', 'values' => ['legal_name' => 'Third LLC', 'cr_number' => 'CR-1']])
        ->assertSessionHasErrors('action');

    $request = ChangeRequest::query()->sole();
    expect($request->number)->toStartWith('CR-AE-')
        ->and($request->current_values['legal_name'])->toBe('Old Trading LLC')
        ->and($vendor->refresh()->business_details['store']['legal_name'])->toBe('Old Trading LLC');

    $this->actingAs($admin)->get(onPanel('admin', 'change-requests'))->assertOk()->assertSee($request->number);
    $this->get(onPanel('admin', "change-requests/{$request->id}"))->assertOk()->assertSee('New Trading LLC');
    $this->post(onPanel('admin', "change-requests/{$request->id}/approve"))->assertSessionHasNoErrors();

    expect($request->refresh()->status)->toBe(ChangeRequestStatus::Approved)
        ->and($request->applied_diff)->toEqual(['legal_name' => ['from' => 'Old Trading LLC', 'to' => 'New Trading LLC']])
        ->and($vendor->refresh()->business_details['store']['legal_name'])->toBe('New Trading LLC')
        ->and($vendor->business_details['store']['cr_number'])->toBe('CR-1')
        ->and(Activity::query()->where('event', 'change_request_applied')->sole()->properties['attributes']['legal_name'])->toBe('New Trading LLC')
        ->and(StateTransition::query()->where('subject_type', 'change_request')->pluck('to_status')->all())->toBe(['pending', 'approved'])
        ->and(CustomerNotification::query()->where('user_id', $seller->id)->where('type', 'change_request.approved')->exists())->toBeTrue();
});

it('needs the bank verification before applying a bank change, and keeps the IBAN out of the audit log', function () {
    [$seller, $vendor] = sellerAccount();
    $admin = adminAccount();

    $this->actingAs($seller)->post(onPanel('partner', 'change-requests'), ['section' => 'bank_details', 'values' => ['bank_name' => 'ENBD', 'account_name' => 'Shop LLC', 'iban' => 'AE07 0331 2345 6789 0123 456']])
        ->assertSessionHasNoErrors();
    $request = ChangeRequest::query()->sole();
    expect($request->requested_values['iban'])->toBe('AE070331234567890123456');

    $this->actingAs($admin)->post(onPanel('admin', "change-requests/{$request->id}/approve"), ['bank_verified' => '0'])->assertSessionHasErrors('action');
    expect($request->refresh()->status)->toBe(ChangeRequestStatus::Pending);

    $this->post(onPanel('admin', "change-requests/{$request->id}/approve"), ['bank_verified' => '1'])->assertSessionHasNoErrors();
    expect($request->refresh()->bank_verified)->toBeTrue()
        ->and($vendor->refresh()->business_details['bank']['iban'])->toBe('AE070331234567890123456')
        ->and(json_encode(Activity::query()->where('event', 'change_request_applied')->sole()->properties))->not->toContain('AE0703');
});

it('lets the requester cancel, admins reject with a reason, and a travel agency use the same flow', function () {
    [$seller, $vendor] = sellerAccount();
    $admin = adminAccount();

    $this->actingAs($seller)->post(onPanel('partner', 'change-requests'), ['section' => 'store_name', 'values' => ['store_name' => 'Brand New Name']])->assertSessionHasNoErrors();
    $first = ChangeRequest::query()->sole();
    $this->post(onPanel('partner', "change-requests/{$first->id}/cancel"))->assertSessionHasNoErrors();
    expect($first->refresh()->status)->toBe(ChangeRequestStatus::Cancelled);

    $this->post(onPanel('partner', 'change-requests'), ['section' => 'address', 'values' => ['street' => 'Sheikh Zayed Rd', 'area' => 'Barsha', 'city' => 'Dubai']])->assertSessionHasNoErrors();
    $second = ChangeRequest::query()->latest('id')->first();
    $this->actingAs($admin)->post(onPanel('admin', "change-requests/{$second->id}/reject"), ['reason' => 'Upload the new tenancy contract first'])->assertSessionHasNoErrors();
    expect($second->refresh()->status)->toBe(ChangeRequestStatus::Rejected)
        ->and($vendor->refresh()->business_details['address'] ?? null)->toBeNull();

    $owner = User::factory()->create();
    $agency = Vendor::factory()->create(['type' => 'travel', 'owner_user_id' => $owner->id, 'store_name' => 'Sky Tours']);
    $owner->memberships()->create(['role' => MembershipRole::TravelAgency, 'country_id' => Country::query()->where('code', 'uae')->value('id'), 'tenant_type' => PanelCountries::VENDOR_TENANT, 'tenant_id' => $agency->id]);

    $this->actingAs($owner)->get(onPanel('travel-agency', 'change-requests'))->assertOk()->assertSee('Sky Tours');
    $this->post(onPanel('travel-agency', 'change-requests'), ['section' => 'store_name', 'values' => ['store_name' => 'Sky Tours & Travel']])->assertSessionHasNoErrors();
    $agencyRequest = ChangeRequest::query()->where('vendor_id', $agency->id)->sole();
    expect($agencyRequest->requester_type)->toBe('travel_agency');

    $this->actingAs($admin)->post(onPanel('admin', "change-requests/{$agencyRequest->id}/approve"))->assertSessionHasNoErrors();
    expect($agency->refresh()->store_name)->toBe('Sky Tours & Travel')
        ->and(CustomerNotification::query()->where('user_id', $owner->id)->where('type', 'change_request.approved')->exists())->toBeTrue();
});
