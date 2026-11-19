<?php

use App\Domains\Contracts\Enums\ContractKind;
use App\Domains\Contracts\Models\ContractTemplate;
use App\Domains\Contracts\Support\ContractPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->country = $this->actAsCountry('uae');
});

it('QC-042: serves the current published seller agreement for the public legal page', function () {
    $this->getJson('/api/v1/uae-en/contracts/current/seller_platform')->assertNotFound();

    $template = ContractTemplate::query()->create(['country_id' => $this->country->id, 'kind' => ContractKind::SellerPlatform, 'name' => 'Seller Agreement']);
    $draft = app(ContractPublisher::class)->draft($template, ['body_ar' => 'اتفاقية البائع', 'body_en' => 'Seller terms for {store_name}']);
    $version = app(ContractPublisher::class)->publish($draft, 1);

    $this->getJson('/api/v1/uae-en/contracts/current/seller_platform')
        ->assertOk()
        ->assertJsonPath('data.id', $version->id)
        ->assertJsonPath('data.kind', 'seller_platform')
        ->assertJsonPath('data.text', 'Seller terms for {store_name}');

    $this->getJson('/api/v1/uae-ar/contracts/current/seller_platform')->assertJsonPath('data.text', 'اتفاقية البائع');

    // Customer-facing and individual kinds are not public here.
    $this->getJson('/api/v1/uae-en/contracts/current/customer_purchase')->assertNotFound();
    $this->getJson('/api/v1/uae-en/contracts/current/nonsense')->assertNotFound();
    // Another country's agreement is not served.
    $this->getJson('/api/v1/omn-en/contracts/current/seller_platform')->assertNotFound();
});
