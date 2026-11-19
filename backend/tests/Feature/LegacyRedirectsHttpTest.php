<?php

use App\Domains\Migration\Support\LegacyRedirects;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
| WP11 extras: old-platform URLs 301 on the new platform itself — the public
| lookup the website asks for paths that would 404, and the Laravel fallback
| for old portal paths that reach a panel or API host.
*/

beforeEach(function () {
    config(['nawy.payments.storefront_url' => 'https://nawy.example']);
    DB::table('legacy_redirects')->insert([
        ['from_path' => '/uae-en/live-streams', 'to_path' => '/uae-en/live', 'status' => 301, 'source' => 'storefront', 'country_code' => 'uae', 'created_at' => now(), 'updated_at' => now()],
        ['from_path' => '/uae/helpcenter/article/when-am-i-paid', 'to_path' => '/uae-en/help/articles/when-am-i-paid', 'status' => 301, 'source' => 'portal', 'country_code' => 'uae', 'created_at' => now(), 'updated_at' => now()],
        ['from_path' => '/blog/hello-legacy', 'to_path' => '/uae-en/blog/hello-legacy', 'status' => 301, 'source' => 'portal', 'country_code' => 'uae', 'created_at' => now(), 'updated_at' => now()],
    ]);
    LegacyRedirects::flush();
});

test('the public lookup answers the 301 target of an old URL, or a 404 envelope', function () {
    $this->getJson('/api/v1/legacy-redirects?path=/uae-en/live-streams')->assertOk()
        ->assertJsonPath('data', ['from' => '/uae-en/live-streams', 'to' => '/uae-en/live', 'status' => 301])
        ->assertHeader('Cache-Control', 'max-age=3600, public');
    $this->getJson('/api/v1/legacy-redirects?path='.urlencode('/uae-en/live-streams/?utm=x'))->assertOk()->assertJsonPath('data.to', '/uae-en/live');
    $this->getJson('/api/v1/legacy-redirects?path=/uae-en/nothing-here')->assertNotFound()->assertJsonPath('error.code', 'not_found');
    $this->getJson('/api/v1/legacy-redirects')->assertUnprocessable();
    $this->getJson('/api/v1/legacy-redirects?path='.urlencode('/uae-en//live-streams'))->assertNotFound();
});

test('the site root, an empty or a blank path is never mapped: 404, not a validation error', function (string $query) {
    $this->getJson('/api/v1/legacy-redirects?'.$query)->assertNotFound()->assertJsonPath('error.code', 'not_found');
})->with(['root' => 'path=/', 'encoded root' => 'path=%2F', 'empty' => 'path=', 'blank' => 'path=%20%20', 'slashes' => 'path=///']);

test('lookups are cached until the map is rebuilt', function () {
    $this->getJson('/api/v1/legacy-redirects?path=/uae-en/live-streams')->assertOk();
    $this->getJson('/api/v1/legacy-redirects?path=/uae-en/gone')->assertNotFound();

    DB::table('legacy_redirects')->where('from_path', '/uae-en/live-streams')->update(['to_path' => '/uae-en/live?changed=1']);
    DB::table('legacy_redirects')->insert(['from_path' => '/uae-en/gone', 'to_path' => '/uae-en', 'status' => 301, 'source' => 'storefront', 'created_at' => now(), 'updated_at' => now()]);
    DB::enableQueryLog();
    $this->getJson('/api/v1/legacy-redirects?path=/uae-en/live-streams')->assertJsonPath('data.to', '/uae-en/live');
    $this->getJson('/api/v1/legacy-redirects?path=/uae-en/gone')->assertNotFound();
    expect(collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql): bool => str_contains($sql, 'legacy_redirects')))->toBeEmpty();

    LegacyRedirects::flush();
    $this->getJson('/api/v1/legacy-redirects?path=/uae-en/live-streams')->assertJsonPath('data.to', '/uae-en/live?changed=1');
    $this->getJson('/api/v1/legacy-redirects?path=/uae-en/gone')->assertOk();
});

test('an old portal path that reaches a panel host is redirected to the website before the 404, keeping the query', function () {
    $this->get(onPanel('partner', 'uae/helpcenter/article/when-am-i-paid?ref=mail'))
        ->assertStatus(301)->assertRedirect('https://nawy.example/uae-en/help/articles/when-am-i-paid?ref=mail');
    $this->get(onPanel('admin', 'blog/hello-legacy'))->assertStatus(301)->assertRedirect('https://nawy.example/uae-en/blog/hello-legacy');
    $this->get('http://'.config('nawy.base_domain').'/blog/hello-legacy/')->assertStatus(301);
});

test('the panel fallback leaves real pages, unknown paths, other methods and the API alone', function () {
    $this->get(onPanel('partner', 'login'))->assertOk();
    $this->get(onPanel('partner', 'uae/helpcenter/article/unknown'))->assertNotFound();
    $this->post(onPanel('partner', 'blog/hello-legacy'))->assertNotFound();
    $this->getJson('/api/v1/blog/hello-legacy')->assertNotFound()->assertJsonPath('error.code', 'not_found');
});
