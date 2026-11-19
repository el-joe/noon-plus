<?php

use App\Domains\Campaigns\Models\MarketerConversion;
use App\Domains\Campaigns\Models\ReferralClick;
use App\Domains\Campaigns\Support\Invitations;
use App\Domains\Marketers\Models\CelebrityMonthlyReport;
use App\Domains\Marketers\Models\CrossPromoParticipation;
use App\Domains\Marketers\Models\CrossPromoPolicy;
use App\Domains\Marketers\Models\MarketerProfile;
use App\Domains\Marketers\Notifications\CelebrityMonthlyReportReady;
use App\Domains\Vendors\Models\Vendor;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->withoutVite();
    $this->actAsCountry('uae');
    Notification::fake();
});

/**
 * An influencer (also affiliate, so they can join an open campaign) with
 * October 2026 sales: two kept conversions on two orders, one reversed, one
 * referral click; plus a pure affiliate who is not a celebrity.
 *
 * @return array{0: User, 1: MarketerProfile}
 */
function celebrityWithOctoberSales(): array
{
    marketerDefaults(['influencer_fixed' => '200', 'affiliate_percent' => '10', 'broker_fixed' => '2', 'broker_percent' => '5', 'fee_percent' => '10', 'fee_fixed' => '0']);
    [$user, $marketer] = marketerAccount(['influencer', 'affiliate'], attributes: ['display_name' => 'Layla Glam']);
    [, $affiliate] = marketerAccount(['affiliate']);
    $vendor = Vendor::factory()->create();
    $campaign = liveCampaign($vendor);
    $invitation = app(Invitations::class)->joinOpen($campaign, $marketer);
    app(Invitations::class)->joinOpen($campaign, $affiliate);
    $october = CarbonImmutable::parse('2026-10-15 10:00', 'Asia/Dubai')->utc();

    foreach ([['pending', 2], ['approved', 1], ['reversed', 1]] as [$status, $quantity]) {
        $subOrder = subOrderFor($vendor, quantity: $quantity, listing: $campaign->listing, placedAt: $october);
        $conversion = MarketerConversion::query()->create([
            'campaign_id' => $campaign->id, 'invitation_id' => $invitation->id, 'marketer_profile_id' => $marketer->id, 'source_type' => 'order_item',
            'source_id' => $subOrder->items()->value('id'), 'order_id' => $subOrder->order_id, 'sub_order_id' => $subOrder->id, 'attribution' => 'last_click',
            'currency' => 'AED', 'basis' => bcmul('105', (string) $quantity, 2), 'gross' => '10.50', 'fee' => '1.05', 'net' => '9.45', 'bonus' => '1.00', 'status' => $status,
        ]);
        $conversion->forceFill(['created_at' => $october])->save();
    }

    ReferralClick::query()->create(['invitation_id' => $invitation->id, 'campaign_id' => $campaign->id, 'marketer_profile_id' => $marketer->id, 'listing_id' => $campaign->listing_id, 'visitor_token' => 'v1', 'clicked_at' => $october, 'expires_at' => $october->addDays(7)]);
    $policy = CrossPromoPolicy::query()->create(['name' => 'Peer promotion', 'type' => 'peer', 'is_active' => true, 'eligibility' => [], 'obligation' => ['count' => 1], 'allocation' => [], 'starts_on' => '2026-01-01']);
    CrossPromoParticipation::query()->create(['policy_id' => $policy->id, 'marketer_profile_id' => $marketer->id, 'period' => '2026-10', 'required' => 1, 'completed' => 1, 'status' => 'met', 'evaluated_at' => now()]);

    return [$user, $marketer];
}

it('generates the previous month for each influencer only, emails it once and is idempotent', function () {
    [$user, $marketer] = celebrityWithOctoberSales();

    $this->artisan('nawy:marketers:celebrity-reports', ['--period' => '2026-10'])->assertSuccessful();
    $this->artisan('nawy:marketers:celebrity-reports', ['--period' => '2026-10'])->assertSuccessful();

    $report = CelebrityMonthlyReport::query()->sole();

    expect($report->marketer_profile_id)->toBe($marketer->id)
        ->and($report->orders_count)->toBe(2)
        ->and($report->units_sold)->toBe(3)
        ->and((string) $report->attributed_sales)->toBe('315.0000')
        ->and($report->conversions_count)->toBe(2)
        ->and((string) $report->commission_net)->toBe('18.9000')
        ->and((string) $report->bonus_total)->toBe('2.0000')
        ->and((string) $report->total_earnings)->toBe('20.9000')
        ->and($report->referral_clicks)->toBe(1)
        ->and($report->metrics['conversions_by_status'])->toEqual(['approved' => 1, 'pending' => 1, 'reversed' => 1])
        ->and($report->metrics['top_products'][0]['units'])->toBe(3)
        ->and($report->metrics['cross_promotion'][0])->toEqual(['policy' => 'Peer promotion', 'required' => 1, 'completed' => 1, 'status' => 'met'])
        ->and($report->emailed_at)->not->toBeNull();

    Notification::assertSentToTimes($user, CelebrityMonthlyReportReady::class, 1);
    $mail = (new CelebrityMonthlyReportReady($report, 'uae'))->toMail($user);
    expect($mail->rawAttachments[0]['name'])->toBe($report->fileName())
        ->and(str_starts_with($mail->rawAttachments[0]['data'], '%PDF'))->toBeTrue();
});

it('is scheduled on the 1st after the cross-promotion evaluation', function () {
    $events = collect(app(Schedule::class)->events())->mapWithKeys(fn ($event): array => [trim(Str::after($event->command, 'artisan'), " '") => $event->expression]);

    expect($events['nawy:marketers:celebrity-reports'])->toBe('0 6 1 * *');
});

it('shows the creator their own reports in Creator Studio with a PDF, and nobody else\'s', function () {
    [$user, $marketer] = celebrityWithOctoberSales();
    $this->artisan('nawy:marketers:celebrity-reports', ['--period' => '2026-10', '--no-email' => true])->assertSuccessful();
    $report = CelebrityMonthlyReport::query()->sole();
    [$other] = marketerAccount(['influencer']);

    $this->actingAs($user)->get(onPanel('marketer', 'reports'))->assertOk()->assertSee('data-report="2026-10"', false);
    $this->actingAs($user)->get(onPanel('marketer', "reports/{$report->id}"))->assertOk()->assertSee('Peer promotion')->assertSee('Top products');
    $pdf = $this->actingAs($user)->get(onPanel('marketer', "reports/{$report->id}/pdf"))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(str_starts_with($pdf->getContent(), '%PDF'))->toBeTrue();

    $this->actingAs($other)->get(onPanel('marketer', "reports/{$report->id}"))->assertNotFound();
    expect($report->refresh()->emailed_at)->toBeNull();
});

it('lists the month for admins ranked by earnings and lets marketing regenerate it', function () {
    [, $marketer] = celebrityWithOctoberSales();
    $this->artisan('nawy:marketers:celebrity-reports', ['--period' => '2026-10', '--no-email' => true])->assertSuccessful();
    $report = CelebrityMonthlyReport::query()->sole();
    $report->forceFill(['orders_count' => 99])->save();
    $marketing = adminAccount('uae', 'marketing');

    $this->actingAs($marketing)->get(onPanel('admin', 'marketers/celebrity-reports?period=2026-10'))->assertOk()->assertSee('Layla Glam')->assertSee('data-report="'.$marketer->id.'"', false);
    $this->actingAs($marketing)->get(onPanel('admin', "marketers/celebrity-reports/{$report->id}"))->assertOk()->assertSee('Peer promotion');
    $this->actingAs($marketing)->get(onPanel('admin', "marketers/celebrity-reports/{$report->id}/pdf"))->assertOk()->assertHeader('Content-Type', 'application/pdf');

    $this->actingAs($marketing)->post(onPanel('admin', 'marketers/celebrity-reports/regenerate'), ['period' => '2026-10'])->assertRedirect();
    expect($report->refresh()->orders_count)->toBe(2)->and(CelebrityMonthlyReport::query()->count())->toBe(1);
    Notification::assertNothingSent();

    $this->actingAs($marketing)->post(onPanel('admin', 'marketers/celebrity-reports/regenerate'), ['period' => '2099-01'])->assertSessionHasErrors('period');
    $this->actingAs(adminAccount('uae', 'support'))->get(onPanel('admin', 'marketers/celebrity-reports'))->assertForbidden();
});
