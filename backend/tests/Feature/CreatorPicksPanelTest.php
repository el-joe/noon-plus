<?php

use App\Domains\Campaigns\Support\Invitations;
use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Listings\Enums\ListingStatus;
use App\Domains\Marketers\Models\MarketerPick;
use App\Domains\Marketers\Models\MarketerProfile;
use App\Domains\Settings\SettingsService;
use App\Domains\Vendors\Models\Vendor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->withoutVite();
    $this->country = $this->actAsCountry('uae');
    Cache::flush();
    Notification::fake();
    marketerDefaults(['influencer_fixed' => '200', 'affiliate_percent' => '10', 'fee_percent' => '10']);
});

/**
 * @return array{0: User, 1: MarketerProfile}
 */
function picksMarketer(array $types = ['affiliate']): array
{
    [$user, $marketer] = marketerAccount($types);
    $user->memberships()->update(['country_id' => app(CountryContext::class)->current()->id]);

    return [$user, $marketer];
}

/**
 * @return array<int, int> listing ids in pick order
 */
function pickOrder(MarketerProfile $marketer): array
{
    return $marketer->picks()->pluck('listing_id')->map(fn ($id): int => (int) $id)->all();
}

it('searches the catalogue, adds, reorders and removes picks', function () {
    [$user, $marketer] = picksMarketer();
    $watch = sellableListing('120.00');
    $watch->variant->product->update(['title_en' => 'Pulse smart watch']);
    $lamp = sellableListing('40.00');
    $lamp->variant->product->update(['title_en' => 'Desk lamp']);
    $hidden = sellableListing('40.00', attributes: ['status' => ListingStatus::Paused]);
    $hidden->variant->product->update(['title_en' => 'Paused watch']);

    $this->actingAs($user)->get(onPanel('marketer', 'products?q=watch'))->assertOk()
        ->assertSee('Pulse smart watch')->assertDontSee('Paused watch')->assertDontSee('Desk lamp');
    $this->actingAs($user)->get(onPanel('marketer', 'products?q='.$lamp->variant->product->id))->assertOk()->assertSee('Desk lamp');

    $this->actingAs($user)->post(onPanel('marketer', 'products'), ['listing_id' => $watch->id])->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($user)->post(onPanel('marketer', 'products'), ['listing_id' => $lamp->id])->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($user)->post(onPanel('marketer', 'products'), ['listing_id' => $lamp->id])->assertSessionHasErrors('listing_id');
    $this->actingAs($user)->post(onPanel('marketer', 'products'), ['listing_id' => $hidden->id])->assertSessionHasErrors('listing_id');
    $this->actingAs($user)->post(onPanel('marketer', 'products'), ['listing_id' => 999999])->assertSessionHasErrors('listing_id');
    expect(pickOrder($marketer))->toBe([$watch->id, $lamp->id]);

    $this->actingAs($user)->get(onPanel('marketer', 'products?q=watch'))->assertOk()->assertDontSee('data-result="'.$watch->id.'"', false);

    $lampPick = MarketerPick::query()->where('listing_id', $lamp->id)->sole();
    $this->actingAs($user)->post(onPanel('marketer', "products/{$lampPick->id}/move"), ['direction' => 'up'])->assertRedirect();
    expect(pickOrder($marketer))->toBe([$lamp->id, $watch->id]);
    $this->actingAs($user)->post(onPanel('marketer', "products/{$lampPick->id}/move"), ['direction' => 'up'])->assertRedirect();
    expect(pickOrder($marketer))->toBe([$lamp->id, $watch->id]);
    $this->actingAs($user)->post(onPanel('marketer', "products/{$lampPick->id}/move"), ['direction' => 'sideways'])->assertSessionHasErrors('direction');

    $this->actingAs($user)->delete(onPanel('marketer', "products/{$lampPick->id}"))->assertRedirect();
    expect(pickOrder($marketer))->toBe([$watch->id]);

    app(SettingsService::class)->set('creators.max_picks', 1, $this->country);
    $this->actingAs($user)->post(onPanel('marketer', 'products'), ['listing_id' => $lamp->id])->assertSessionHasErrors('listing_id');
});

it('shows each pick\'s referral link and clicks and orders only where a live campaign promotes it', function () {
    [$user, $marketer] = picksMarketer();
    $campaign = liveCampaign(Vendor::factory()->create());
    $invitation = app(Invitations::class)->joinOpen($campaign, $marketer);
    $plain = sellableListing('30.00');
    MarketerPick::query()->create(['marketer_profile_id' => $marketer->id, 'listing_id' => $campaign->listing_id, 'position' => 1]);
    MarketerPick::query()->create(['marketer_profile_id' => $marketer->id, 'listing_id' => $plain->id, 'position' => 2]);
    $this->withHeader('X-Visitor-Token', 'visitor-1')->getJson("/api/v1/uae-en/referrals/{$invitation->referral_code}")->assertOk();
    $this->withHeader('X-Visitor-Token', 'visitor-2')->getJson("/api/v1/uae-en/referrals/{$invitation->referral_code}")->assertOk();

    $response = $this->actingAs($user)->get(onPanel('marketer', 'products'))->assertOk()
        ->assertSee($invitation->referral_code)->assertSee('/r/'.$invitation->referral_code)->assertSee('No campaign: shared through your creator page')
        ->assertSee('/uae-en/creators/'.$marketer->slug);
    $rows = $response->viewData('rows');

    expect($rows[0]['invitation']->id)->toBe($invitation->id)
        ->and($rows[0]['clicks'])->toBe(2)
        ->and($rows[0]['orders'])->toBe(0)
        ->and($rows[1]['invitation'])->toBeNull()
        ->and($rows[1]['clicks'])->toBeNull();
});

it('feeds curated picks, in order, to the creator page and the referral landing page', function () {
    [, $marketer] = picksMarketer();
    $campaign = liveCampaign(Vendor::factory()->create());
    $invitation = app(Invitations::class)->joinOpen($campaign, $marketer);
    $first = sellableListing('10.00');
    $second = sellableListing('20.00');
    $paused = sellableListing('30.00');
    foreach ([$second, $first, $paused] as $position => $listing) {
        MarketerPick::query()->create(['marketer_profile_id' => $marketer->id, 'listing_id' => $listing->id, 'position' => $position + 1]);
    }
    $paused->forceFill(['status' => ListingStatus::Paused])->save();

    $picks = $this->getJson("/api/v1/uae-en/creators/{$marketer->slug}")->assertOk()->json('data.picks');
    expect(array_column($picks, 'listing_id'))->toBe([$second->id, $first->id, $campaign->listing_id])
        ->and($picks[0]['referral_code'])->toBeNull()
        ->and($picks[2]['referral_code'])->toBe($invitation->referral_code);

    MarketerPick::query()->create(['marketer_profile_id' => $marketer->id, 'listing_id' => $campaign->listing_id, 'position' => 0]);
    $picks = $this->getJson("/api/v1/uae-en/creators/{$marketer->slug}")->assertOk()->json('data.picks');
    expect(array_column($picks, 'listing_id'))->toBe([$campaign->listing_id, $second->id, $first->id])
        ->and($picks[0]['referral_code'])->toBe($invitation->referral_code);

    $landing = $this->getJson("/api/v1/uae-en/referrals/{$invitation->referral_code}")->assertOk()->json('data.picks');
    expect(array_column($landing, 'listing_id'))->toBe([$second->id, $first->id]);
});

it('keeps picks to the marketer: another creator\'s pick is a 404 and non-marketers are refused', function () {
    [$user] = picksMarketer();
    [, $other] = picksMarketer();
    $theirs = MarketerPick::query()->create(['marketer_profile_id' => $other->id, 'listing_id' => sellableListing()->id, 'position' => 1]);

    $this->actingAs($user)->get(onPanel('marketer', 'products'))->assertOk()->assertDontSee('data-pick="'.$theirs->listing_id.'"', false);
    $this->actingAs($user)->post(onPanel('marketer', "products/{$theirs->id}/move"), ['direction' => 'down'])->assertNotFound();
    $this->actingAs($user)->delete(onPanel('marketer', "products/{$theirs->id}"))->assertNotFound();
    expect(MarketerPick::query()->whereKey($theirs->id)->exists())->toBeTrue();

    [$seller] = sellerAccount();
    app('auth')->forgetGuards();
    $this->actingAs($seller)->get(onPanel('marketer', 'products'))->assertForbidden();
});

it('keeps countries apart: picks live in the country database of the market', function () {
    [$user, $marketer] = picksMarketer();
    $uaeListing = sellableListing();
    $this->actingAs($user)->post(onPanel('marketer', 'products'), ['listing_id' => $uaeListing->id])->assertSessionHasNoErrors();

    $oman = Country::query()->where('code', 'omn')->sole();
    app(CountryContext::class)->runAs($oman, function (): void {
        expect(MarketerPick::query()->count())->toBe(0);
    });
    expect(MarketerPick::query()->sole()->listing_id)->toBe($uaeListing->id);
    $this->getJson('/api/v1/omn-en/creators/'.$marketer->slug)->assertNotFound();
});

it('links My products in the Creator Studio sidebar instead of "Soon"', function () {
    [$user] = picksMarketer();

    $this->actingAs($user)->get(onPanel('marketer', 'products'))->assertOk()->assertSee('href="'.onPanel('marketer', 'products').'"', false);
});
