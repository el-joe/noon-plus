<?php

use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Models\Domain;
use App\Domains\Countries\Support\DomainMap;
use App\Shared\Http\StorefrontLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
});

function countryId(string $code): int
{
    return Country::query()->where('code', $code)->value('id');
}

function domainPayload(array $overrides = []): array
{
    return $overrides + ['hostname' => 'nawy.om', 'kind' => 'storefront', 'country_id' => countryId('omn'), 'locale' => 'ar', 'panel' => '', 'redirect_to' => '',
        'tls_status' => 'issued', 'note' => '', 'is_primary' => '1', 'is_active' => '1', 'dns_verified' => '1'];
}

it('lets a super admin map hosts with validation and audit; other admins cannot', function () {
    $admin = adminAccount();

    $this->actingAs($admin)->post(onPanel('admin', 'countries/domains'), domainPayload(['hostname' => 'HTTPS://bad host/']))->assertSessionHasErrors('hostname');
    $this->actingAs($admin)->post(onPanel('admin', 'countries/domains'), domainPayload(['hostname' => 'admin.'.config('nawy.base_domain')]))->assertSessionHasErrors('hostname');
    $this->actingAs($admin)->post(onPanel('admin', 'countries/domains'), domainPayload(['country_id' => '']))->assertSessionHasErrors('country_id');
    $this->actingAs($admin)->post(onPanel('admin', 'countries/domains'), domainPayload(['kind' => 'panel', 'panel' => '', 'country_id' => '']))->assertSessionHasErrors('panel');

    $this->actingAs($admin)->post(onPanel('admin', 'countries/domains'), domainPayload(['hostname' => 'NAWY.OM.']))->assertSessionHasNoErrors();
    $this->actingAs($admin)->post(onPanel('admin', 'countries/domains'), domainPayload())->assertSessionHasErrors('hostname');

    $domain = Domain::query()->sole();
    expect($domain)->hostname->toBe('nawy.om')->is_primary->toBeTrue()->dns_verified_at->not->toBeNull()
        ->and(Activity::query()->where('log_name', 'domains')->where('subject_id', $domain->id)->exists())->toBeTrue();

    $this->actingAs($admin)->get(onPanel('admin', 'countries/domains'))->assertOk()->assertSee('nawy.om')->assertSee('omn-ar');

    // A second primary host for Oman takes over.
    $this->actingAs($admin)->post(onPanel('admin', 'countries/domains'), domainPayload(['hostname' => 'nawy.com.om', 'locale' => 'en']))->assertSessionHasNoErrors();
    expect($domain->fresh()->is_primary)->toBeFalse();

    $this->actingAs(adminAccount('uae', 'finance'))->get(onPanel('admin', 'countries/domains'))->assertForbidden();
    $this->actingAs(adminAccount('uae', 'operations'))->post(onPanel('admin', 'countries/domains'), domainPayload(['hostname' => 'x.example.com']))->assertForbidden();
});

it('resolves a custom storefront host for the website and uses the primary host for links', function () {
    Domain::query()->create(['hostname' => 'nawy.om', 'kind' => 'storefront', 'country_id' => countryId('omn'), 'locale' => 'ar', 'is_primary' => true, 'tls_status' => 'issued']);
    Domain::query()->create(['hostname' => 'www.nawy.om', 'kind' => 'storefront', 'country_id' => countryId('omn'), 'redirect_to' => 'nawy.om', 'tls_status' => 'issued']);

    $this->getJson('/api/v1/domains/resolve?host=NAWY.OM:443')->assertOk()
        ->assertJsonPath('data.kind', 'storefront')->assertJsonPath('data.market', 'omn-ar')->assertJsonPath('data.country', 'omn')
        ->assertJsonPath('data.canonical_host', 'nawy.om');
    $this->getJson('/api/v1/domains/resolve?host=www.nawy.om')->assertOk()->assertJsonPath('data.redirect_to', 'nawy.om');
    $this->getJson('/api/v1/domains/resolve?host=unknown.example.com')->assertNotFound()->assertJsonPath('error.code', 'domain_not_mapped');

    $this->getJson('/api/v1/omn-ar/context')->assertOk()->assertJsonPath('meta.storefront_host', 'nawy.om');
    $this->getJson('/api/v1/uae-en/context')->assertOk()->assertJsonPath('meta.storefront_host', null);

    $this->actAsCountry('omn');
    expect(app(StorefrontLinks::class)->base())->toBe((parse_url((string) config('nawy.payments.storefront_url'), PHP_URL_SCHEME) ?: 'https').'://nawy.om');
    $this->actAsCountry('uae');
    expect(app(StorefrontLinks::class)->base())->toBe(rtrim((string) config('nawy.payments.storefront_url'), '/'));
});

it('redirects a panel alias to the canonical panel on its country, and forgets the cache on change', function () {
    $alias = Domain::query()->create(['hostname' => 'admin.omn.nawy.com', 'kind' => 'panel', 'panel' => 'admin', 'country_id' => countryId('omn'), 'tls_status' => 'issued']);

    $this->get('http://admin.omn.nawy.com/orders?status=placed')
        ->assertRedirect(onPanel('admin', 'orders').'?status=placed&country=omn')->assertStatus(302);

    $alias->update(['is_active' => false]);
    expect(app(DomainMap::class)->resolve('admin.omn.nawy.com'))->toBeNull();
    $this->get('http://admin.omn.nawy.com/orders')->assertNotFound();

    Domain::query()->create(['hostname' => 'old-admin.example.com', 'kind' => 'panel', 'panel' => 'admin', 'redirect_to' => 'admin.omn.nawy.com', 'tls_status' => 'issued']);
    $this->get('http://old-admin.example.com/login')->assertStatus(301)->assertRedirect((parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https').'://admin.omn.nawy.com/login');
});
