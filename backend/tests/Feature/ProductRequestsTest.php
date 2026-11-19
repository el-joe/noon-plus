<?php

use App\Domains\Catalog\Enums\ProductRequestStatus;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\ProductRequest;
use App\Domains\Catalog\Models\ProductVariant;
use App\Domains\Customers\Models\CustomerNotification;
use App\Shared\StateMachine\StateTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

/*
| QC-016 / FR-CAT-2: product requests (lifecycle catalogue §1.3).
*/

beforeEach(function () {
    $this->setUpCountries();
    $this->withoutVite();
    $this->country = $this->actAsCountry('uae');
    Notification::fake();
});

it('lets a store request a missing product, refuses duplicates of the catalogue, and an admin approves it into a new product', function () {
    [$seller, $vendor] = sellerAccount();
    $admin = adminAccount();
    $category = Category::factory()->create(['name_en' => 'Coffee machines']);
    $existing = ProductVariant::factory()->create(['barcode' => '6291041500213']);

    $this->actingAs($seller)->get(onPanel('partner', 'product-requests'))->assertOk()->assertSee(__('Request a product'));
    $this->post(onPanel('partner', 'product-requests'), ['title_en' => 'Some product', 'gtin' => '6291041500213'])
        ->assertSessionHasErrors('title_en');
    $this->post(onPanel('partner', 'product-requests'), ['title_en' => $existing->product->title_en])->assertSessionHasErrors('title_en');

    $this->post(onPanel('partner', 'product-requests'), [
        'title_en' => 'Barista Pro 900', 'brand_name' => 'Brewly', 'gtin' => '6291041500999', 'category_id' => $category->id, 'description' => 'Dual boiler.',
    ])->assertSessionHasNoErrors();
    $request = ProductRequest::query()->sole();
    expect($request->status)->toBe(ProductRequestStatus::Pending)
        ->and($request->number)->toStartWith('PR-AE-')
        ->and($request->vendor_id)->toBe($vendor->id)
        ->and(CustomerNotification::query()->where('user_id', $admin->id)->where('type', 'product_request.submitted')->exists())->toBeTrue();

    // A second request for the same product is refused while the first is pending.
    $this->post(onPanel('partner', 'product-requests'), ['title_en' => 'Barista Pro 900'])->assertSessionHasErrors('title_en');

    $this->actingAs($admin)->get(onPanel('admin', 'product-requests'))->assertOk()->assertSee($request->number);
    $this->get(onPanel('admin', "product-requests/{$request->id}"))->assertOk()->assertSee('Brewly');
    $this->post(onPanel('admin', "product-requests/{$request->id}/approve"), [
        'category_id' => $category->id, 'title_en' => 'Barista Pro 900', 'title_ar' => 'باريستا برو 900', 'gtin' => '6291041500999', 'activate' => '1',
    ])->assertSessionHasNoErrors();

    $request->refresh();
    $product = Product::query()->findOrFail($request->product_id);
    expect($request->status)->toBe(ProductRequestStatus::Approved)
        ->and($request->reviewed_by)->toBe($admin->id)
        ->and($product->status)->toBe('active')
        ->and($product->variants()->sole()->barcode)->toBe('6291041500999')
        ->and(StateTransition::query()->where('subject_type', 'product_request')->where('subject_id', $request->id)->pluck('to_status')->all())->toBe(['pending', 'approved'])
        ->and(CustomerNotification::query()->where('user_id', $seller->id)->where('type', 'product_request.approved')->exists())->toBeTrue();

    // Decided requests cannot be decided again.
    $this->post(onPanel('admin', "product-requests/{$request->id}/reject"), ['reason' => 'Changed my mind'])->assertSessionHasErrors('action');
});

it('rejects with a reason, lets the requester cancel only pending requests, and scopes requests to their owner', function () {
    [$seller, $vendor] = sellerAccount();
    [$otherSeller] = sellerAccount();
    $admin = adminAccount();

    $this->actingAs($seller)->post(onPanel('partner', 'product-requests'), ['title_en' => 'Desk lamp X1'])->assertSessionHasNoErrors();
    $this->post(onPanel('partner', 'product-requests'), ['title_en' => 'Desk lamp X2'])->assertSessionHasNoErrors();
    [$first, $second] = ProductRequest::query()->orderBy('id')->get()->all();

    $this->actingAs($otherSeller)->post(onPanel('partner', "product-requests/{$first->id}/cancel"))->assertNotFound();
    $this->get(onPanel('partner', 'product-requests'))->assertDontSee($first->number);

    $this->actingAs($seller)->post(onPanel('partner', "product-requests/{$first->id}/cancel"))->assertSessionHasNoErrors();
    expect($first->refresh()->status)->toBe(ProductRequestStatus::Cancelled);

    $this->actingAs($admin)->post(onPanel('admin', "product-requests/{$second->id}/reject"), ['reason' => 'x'])->assertSessionHasErrors('reason');
    $this->post(onPanel('admin', "product-requests/{$second->id}/reject"), ['reason' => 'Not allowed in this country'])->assertSessionHasNoErrors();
    expect($second->refresh()->status)->toBe(ProductRequestStatus::Rejected)
        ->and($second->rejection_reason)->toBe('Not allowed in this country');

    $this->actingAs($seller)->post(onPanel('partner', "product-requests/{$second->id}/cancel"))->assertSessionHasErrors('action');
    $this->get(onPanel('partner', 'product-requests'))->assertSee('Not allowed in this country');
});

it('lets a marketer request a product from Creator Studio', function () {
    [$user, $marketer] = marketerAccount(['influencer']);

    $this->actingAs($user)->get(onPanel('marketer', 'product-requests'))->assertOk();
    $this->post(onPanel('marketer', 'product-requests'), ['title_en' => 'Glow serum 30ml', 'reference_url' => 'https://example.com/serum'])->assertSessionHasNoErrors();

    $request = ProductRequest::query()->sole();
    expect($request->requester_type)->toBe('marketer')->and($request->marketer_profile_id)->toBe($marketer->id)->and($request->vendor_id)->toBeNull();

    $this->actingAs(adminAccount())->post(onPanel('admin', "product-requests/{$request->id}/reject"), ['reason' => 'Already sold as a bundle'])->assertSessionHasNoErrors();
    expect(CustomerNotification::query()->where('user_id', $user->id)->where('type', 'product_request.rejected')->exists())->toBeTrue();
});

it('keeps product requests from admins without the permission', function () {
    $this->actingAs(adminAccount('uae', 'marketing'))->get(onPanel('admin', 'product-requests'))->assertForbidden();
    $this->actingAs(adminAccount('uae', 'vendor_relations'))->get(onPanel('admin', 'product-requests'))->assertOk();
});
