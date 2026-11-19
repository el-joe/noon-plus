<?php

use App\Domains\Onboarding\Models\PortalFunnelEvent;
use Database\Seeders\AdminRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
| FR-POR-2 / FR-POR-8 (QC-042): the website's portal funnel events are
| stored per country, read back in Admin → Portal analytics and pruned with
| the other raw analytics.
*/

beforeEach(function () {
    $this->setUpCountries();
    $this->seed(AdminRoleSeeder::class);
    $this->withoutVite();
    $this->actAsCountry('uae');
});

function funnelEvent(array $event, string $visitor): void
{
    test()->withHeader('X-Visitor-Token', $visitor)->postJson('/api/v1/uae-en/portal/funnel', $event)->assertStatus(202);
}

it('records portal views and wizard steps with an anonymous visitor hash only', function () {
    funnelEvent(['event' => 'view', 'page' => 'join'], 'visitor-a');
    funnelEvent(['event' => 'view', 'page' => 'join'], 'visitor-b');
    funnelEvent(['event' => 'view', 'page' => 'join'], 'visitor-a');
    funnelEvent(['event' => 'step', 'page' => 'join/vendor', 'type' => 'vendor', 'step' => 'account'], 'visitor-a');
    funnelEvent(['event' => 'step', 'page' => 'join/vendor', 'type' => 'vendor', 'step' => 'account'], 'visitor-b');
    funnelEvent(['event' => 'step', 'page' => 'join/vendor', 'type' => 'vendor', 'step' => 'store'], 'visitor-a');
    funnelEvent(['event' => 'submitted', 'page' => 'join/vendor', 'type' => 'vendor'], 'visitor-a');

    $this->postJson('/api/v1/uae-en/portal/funnel', ['event' => 'click', 'page' => 'join'])->assertUnprocessable();
    $this->postJson('/api/v1/uae-en/portal/funnel', ['event' => 'view', 'page' => '<script>'])->assertUnprocessable();

    expect(PortalFunnelEvent::query()->count())->toBe(7)
        ->and(PortalFunnelEvent::query()->where('visitor_hash', 'visitor-a')->exists())->toBeFalse();

    $this->actingAs(adminAccount())->get(onPanel('admin', 'portal-analytics?country=uae'))->assertOk()
        ->assertSee('/join')
        ->assertSeeInOrder(['Account', '2', 'Store', '1', 'Application submitted', '1'], false);
});

it('prunes funnel events with the raw analytics', function () {
    PortalFunnelEvent::query()->create(['event' => 'view', 'page' => 'join', 'created_at' => now()->subDays(400)]);
    PortalFunnelEvent::query()->create(['event' => 'view', 'page' => 'join', 'created_at' => now()->subDay()]);

    $this->artisan('nawy:analytics:prune')->assertSuccessful();

    expect(PortalFunnelEvent::query()->count())->toBe(1);
});
