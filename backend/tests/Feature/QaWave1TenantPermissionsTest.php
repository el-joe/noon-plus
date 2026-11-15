<?php

use App\Domains\Delivery\Models\Carrier;
use App\Domains\Delivery\Models\CarrierClaim;
use App\Domains\Identity\Enums\MembershipRole;
use App\Domains\Marketers\Support\MarketerProfiles;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Panels\Notifications\TeamInvitationNotification;
use App\Domains\Panels\Support\PanelCountries;
use App\Domains\Vendors\Models\Vendor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->withoutVite();
    $this->country = $this->actAsCountry('uae');
    Storage::fake('local');
    Storage::fake('public');
    Notification::fake();
});

function wave1Staff(Vendor $vendor, MembershipRole $role, ?string $tenantRole): User
{
    $user = User::factory()->create();
    $user->memberships()->create(['role' => $role, 'country_id' => test()->country->id, 'tenant_type' => PanelCountries::VENDOR_TENANT, 'tenant_id' => $vendor->id, 'tenant_role' => $tenantRole]);

    return $user;
}

it('QC-020: Seller Center staff get only what their role grants; the owner keeps every right', function () {
    [$owner, $vendor] = sellerAccount();
    $subOrder = subOrderFor($vendor, SubOrderStatus::Placed);
    $viewer = wave1Staff($vendor, MembershipRole::ProductVendor, null);
    $catalog = wave1Staff($vendor, MembershipRole::ProductVendor, 'catalog');
    $orders = wave1Staff($vendor, MembershipRole::ProductVendor, 'orders');

    $this->actingAs($viewer)->get(onPanel('partner', 'orders'))->assertOk();
    $this->actingAs($viewer)->post(onPanel('partner', "orders/{$subOrder->id}/advance"), ['to' => 'confirmed'])->assertForbidden();
    $this->actingAs($catalog)->get(onPanel('partner', 'orders'))->assertForbidden();
    $this->actingAs($catalog)->get(onPanel('partner', 'products'))->assertOk();
    $this->actingAs($orders)->post(onPanel('partner', "orders/{$subOrder->id}/advance"), ['to' => 'confirmed'])->assertRedirect();
    $this->actingAs($orders)->get(onPanel('partner', 'payouts'))->assertForbidden();
    $this->actingAs($orders)->get(onPanel('partner', 'team'))->assertForbidden();

    $this->actingAs($owner)->get(onPanel('partner', 'payouts'))->assertOk();
    $this->actingAs($owner)->get(onPanel('partner', 'team'))->assertOk()->assertSee($viewer->email);
});

it('QC-020: the owner invites staff with a role, the invitee accepts and gets exactly that role', function () {
    [$owner, $vendor] = sellerAccount();

    $this->actingAs($owner)->post(onPanel('partner', 'team/invitations'), ['name' => 'Huda', 'email' => 'huda@example.com', 'role' => 'finance'])->assertRedirect()->assertSessionHasNoErrors();
    $url = null;
    Notification::assertSentOnDemand(TeamInvitationNotification::class, function (TeamInvitationNotification $mail) use (&$url): bool {
        $url = $mail->acceptUrl;

        return true;
    });
    auth()->logout();
    $this->post($url, ['password' => 'huda-pass-1', 'password_confirmation' => 'huda-pass-1'])->assertRedirect(onPanel('partner', 'login'));

    $huda = User::query()->where('email', 'huda@example.com')->sole();
    $membership = $huda->memberships()->where('tenant_id', $vendor->id)->sole();
    expect($membership->tenant_role)->toBe('finance');
    $this->actingAs($huda)->get(onPanel('partner', 'payouts'))->assertOk();
    $this->actingAs($huda)->get(onPanel('partner', 'products'))->assertForbidden();

    $this->actingAs($owner)->put(onPanel('partner', "team/{$membership->id}"), ['role' => 'catalog'])->assertRedirect();
    $this->actingAs($huda)->get(onPanel('partner', 'products'))->assertOk();
});

it('QC-020: travel, carrier and Creator Studio staff are limited too', function () {
    $owner = User::factory()->create();
    $agency = Vendor::factory()->create(['type' => 'travel', 'owner_user_id' => $owner->id]);
    $owner->memberships()->create(['role' => MembershipRole::TravelAgency, 'country_id' => $this->country->id, 'tenant_type' => 'vendor', 'tenant_id' => $agency->id]);
    $finance = wave1Staff($agency, MembershipRole::TravelAgency, 'finance');
    $this->actingAs($finance)->get(onPanel('travel-agency', 'bookings'))->assertOk();
    $this->actingAs($finance)->get(onPanel('travel-agency', 'packages'))->assertForbidden();
    $this->actingAs($owner)->get(onPanel('travel-agency', 'packages'))->assertOk();

    $carrierOwner = User::factory()->create();
    $carrier = Carrier::query()->create(['code' => 'fast', 'name' => 'Fast', 'status' => 'active', 'owner_user_id' => $carrierOwner->id]);
    $supervisor = User::factory()->create();
    foreach ([$carrierOwner, $supervisor] as $member) {
        $member->memberships()->create(['role' => MembershipRole::CarrierSupervisor, 'country_id' => $this->country->id, 'tenant_type' => Carrier::TENANT, 'tenant_id' => $carrier->id]);
    }
    $claim = CarrierClaim::query()->create(['number' => 'CL-9', 'carrier_id' => $carrier->id, 'sub_order_id' => subOrderFor(Vendor::factory()->create())->id, 'type' => 'damaged', 'description' => 'Box', 'currency' => 'AED', 'claimed_amount' => '5.00', 'status' => 'submitted', 'raised_by_type' => 'admin']);
    $this->actingAs($supervisor)->get(onPanel('carrier', 'claims'))->assertOk();
    $this->actingAs($supervisor)->post(onPanel('carrier', "claims/{$claim->id}/respond"), ['response' => 'Our side of it'])->assertForbidden();
    $this->actingAs($supervisor)->get(onPanel('carrier', 'company'))->assertForbidden();
    $this->actingAs($carrierOwner)->post(onPanel('carrier', "claims/{$claim->id}/respond"), ['response' => 'Our side of it'])->assertRedirect();

    $agencyUser = User::factory()->create();
    $marketerAgency = app(MarketerProfiles::class)->createAgency($agencyUser, 'Star Agency');
    [, $managed] = marketerAccount(['influencer']);
    app(MarketerProfiles::class)->attachToAgency($managed, $marketerAgency);
    $this->actingAs($agencyUser)->post(onPanel('marketer', 'agency/switch'), ['marketer_id' => $managed->id])->assertRedirect();
    $this->actingAs($agencyUser)->get(onPanel('marketer', 'wallet'))->assertOk();
    $this->actingAs($agencyUser)->post(onPanel('marketer', 'wallet/withdrawals'), ['amount' => '5', 'iban' => 'AE07033', 'account_name' => 'X'])->assertForbidden();
});
