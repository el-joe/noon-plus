<?php

use App\Domains\Catalog\Models\Brand;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\CategoryAttribute;
use App\Domains\Catalog\Models\Product;
use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Listings\Enums\ListingStatus;
use App\Domains\Listings\Models\Listing;
use App\Domains\Vendors\Models\Vendor;
use App\Shared\StateMachine\StateTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->withoutVite();
    $this->country = $this->actAsCountry('uae');
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function categoryPayload(array $overrides = []): array
{
    return $overrides + [
        'name_en' => 'Electronics', 'name_ar' => 'إلكترونيات', 'slug' => 'electronics',
        'commission_rate' => '8.50', 'return_window_days' => 14, 'sort_order' => 1,
        'requires_contract' => 0, 'requires_certification' => 0, 'is_active' => 1,
    ];
}

it('QC-003: an admin creates a bilingual category with image and commission, adds a filter attribute, and every change is audited with the country', function () {
    Storage::fake('public');
    $admin = adminAccount();

    $this->actingAs($admin)->post(onPanel('admin', 'catalog/categories'), categoryPayload(['image' => UploadedFile::fake()->image('tv.jpg')]))
        ->assertRedirect()->assertSessionHasNoErrors();
    $category = Category::query()->where('slug', 'electronics')->sole();
    expect((string) $category->commission_rate)->toBe('8.50')
        ->and($category->name_ar)->toBe('إلكترونيات');
    Storage::disk('public')->assertExists($category->image_path);

    $this->actingAs($admin)->post(onPanel('admin', "catalog/categories/{$category->id}/attributes"), [
        'key' => 'colour', 'label_en' => 'Colour', 'label_ar' => 'اللون', 'type' => 'select', 'options' => 'Black, White', 'is_required' => 0, 'is_filterable' => 1,
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect(CategoryAttribute::query()->sole()->options)->toBe(['Black', 'White']);

    $this->actingAs($admin)->put(onPanel('admin', "catalog/categories/{$category->id}"), categoryPayload(['commission_rate' => '9.00']))->assertRedirect();

    $audit = Activity::query()->where('log_name', 'categories')->where('event', 'updated')->sole();
    expect($audit->causer_id)->toBe($admin->id)
        ->and($audit->properties['country'])->toBe('uae')
        ->and($audit->properties['attributes']['commission_rate'])->toBe('9.00');

    $this->actingAs($admin)->get(onPanel('admin', 'catalog/categories'))->assertOk()->assertSee('Electronics');
    $this->actingAs($admin)->get(onPanel('admin', "catalog/categories/{$category->id}"))->assertOk()->assertSee('Colour');
});

it('QC-003: category validation refuses duplicate slugs, a commission above 100 and moving a category under itself', function () {
    $admin = adminAccount();
    $parent = Category::factory()->create(['slug' => 'home']);
    $child = Category::factory()->create(['slug' => 'kitchen', 'parent_id' => $parent->id]);

    $this->actingAs($admin)->post(onPanel('admin', 'catalog/categories'), categoryPayload(['slug' => 'home', 'commission_rate' => '120']))
        ->assertSessionHasErrors(['slug', 'commission_rate']);
    $this->actingAs($admin)->put(onPanel('admin', "catalog/categories/{$parent->id}"), categoryPayload(['slug' => 'home', 'parent_id' => $child->id]))
        ->assertSessionHasErrors('parent_id');
});

it('QC-003: catalog permissions follow the matrix — content views, vendor relations manages, support has no access', function () {
    $category = Category::factory()->create();
    $content = adminAccount('uae', 'content');
    $vendorRelations = adminAccount('uae', 'vendor_relations');
    $support = adminAccount('uae', 'support');

    $this->actingAs($content)->get(onPanel('admin', 'catalog/categories'))->assertOk();
    $this->actingAs($content)->post(onPanel('admin', 'catalog/categories'), categoryPayload())->assertForbidden();
    $this->actingAs($vendorRelations)->post(onPanel('admin', 'catalog/categories'), categoryPayload())->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($support)->get(onPanel('admin', "catalog/categories/{$category->id}"))->assertForbidden();
    $this->actingAs($support)->get(onPanel('admin', 'catalog/brands'))->assertForbidden();
});

it('QA-011: categories are copied with sub-categories and attributes to other countries, matched by slug so a second copy updates', function () {
    $admin = adminAccount();
    $root = Category::factory()->create(['slug' => 'fashion', 'name_en' => 'Fashion', 'commission_rate' => '12.00']);
    $child = Category::factory()->create(['slug' => 'abayas', 'name_en' => 'Abayas', 'parent_id' => $root->id]);
    $child->attributeDefinitions()->create(['key' => 'size', 'label_en' => 'Size', 'label_ar' => 'المقاس', 'type' => 'select', 'options' => ['S', 'M'], 'is_filterable' => true]);

    $this->actingAs($admin)->post(onPanel('admin', 'catalog/categories/copy'), ['category_ids' => [$root->id], 'countries' => ['omn', 'ksa'], 'with_commission' => 0])
        ->assertRedirect()->assertSessionHasNoErrors();

    $oman = Country::query()->where('code', 'omn')->sole();
    app(CountryContext::class)->runAs($oman, function () {
        $copiedRoot = Category::query()->where('slug', 'fashion')->sole();
        $copiedChild = Category::query()->where('slug', 'abayas')->sole();
        expect($copiedChild->parent_id)->toBe($copiedRoot->id)
            ->and($copiedRoot->commission_rate)->toBeNull()
            ->and($copiedChild->attributeDefinitions()->sole()->options)->toBe(['S', 'M']);
    });

    $root->update(['name_en' => 'Fashion & style']);
    $this->actingAs($admin)->post(onPanel('admin', 'catalog/categories/copy'), ['category_ids' => [$root->id], 'countries' => ['omn']])->assertRedirect();
    app(CountryContext::class)->runAs($oman, fn () => expect(Category::query()->count())->toBe(2)
        ->and(Category::query()->where('slug', 'fashion')->value('name_en'))->toBe('Fashion & style'));

    expect(Activity::query()->where('log_name', 'categories')->where('event', 'copied')->count())->toBe(2);

    $this->getJson('/api/v1/omn-en/categories')->assertOk()->assertJsonFragment(['slug' => 'fashion']);
});

it('QA-011: an admin can only copy to countries where they manage categories', function () {
    $uaeVendorRelations = adminAccount('uae', 'vendor_relations');
    $root = Category::factory()->create();

    $this->actingAs($uaeVendorRelations)->post(onPanel('admin', 'catalog/categories/copy'), ['category_ids' => [$root->id], 'countries' => ['omn']])
        ->assertSessionHasErrors('countries.0');
});

it('QC-003: catalogue data is per country — an Oman admin never opens a UAE category, brand or product', function () {
    $category = Category::factory()->create();
    $productId = sellableListing()->variant->product_id;
    $omanAdmin = adminAccount('omn', 'operations');

    $this->actingAs($omanAdmin)->get(onPanel('admin', "catalog/categories/{$category->id}"))->assertNotFound();
    $this->actingAs($omanAdmin)->get(onPanel('admin', "catalog/products/{$productId}"))->assertNotFound();
});

it('QC-003: brands are created and edited with an Arabic name and audited', function () {
    $admin = adminAccount('uae', 'operations');

    $this->actingAs($admin)->post(onPanel('admin', 'catalog/brands'), ['name' => 'SoundMax', 'name_ar' => 'ساوند ماكس', 'slug' => 'soundmax', 'is_active' => 1])->assertRedirect()->assertSessionHasNoErrors();
    $brand = Brand::query()->sole();
    $this->actingAs($admin)->post(onPanel('admin', 'catalog/brands'), ['name' => 'Again', 'slug' => 'soundmax'])->assertSessionHasErrors('slug');
    $this->actingAs($admin)->put(onPanel('admin', "catalog/brands/{$brand->id}"), ['name' => 'SoundMax Pro', 'slug' => 'soundmax', 'is_active' => 0])->assertRedirect();

    expect($brand->refresh()->is_active)->toBeFalse()
        ->and(Activity::query()->where('log_name', 'brands')->count())->toBe(2);
    $this->actingAs($admin)->get(onPanel('admin', 'catalog/brands?q=sound'))->assertOk()->assertSee('SoundMax Pro');
});

it('QC-003: an admin edits a product and its variant; an inactive product blocks offer approval in the listing review', function () {
    $admin = adminAccount();
    $listing = sellableListing();
    $product = $listing->variant->product;
    $listing->forceFill(['status' => ListingStatus::PendingReview])->save();

    $this->actingAs($admin)->get(onPanel('admin', 'catalog/products?q='.urlencode($listing->variant->sku)))->assertOk()->assertSee($product->title_en);
    $this->actingAs($admin)->put(onPanel('admin', "catalog/products/{$product->id}"), [
        'title_en' => 'New title', 'title_ar' => 'عنوان', 'slug' => $product->slug, 'category_id' => $product->category_id,
        'status' => 'restricted', 'status_reason' => 'Import licence pending', 'fulfilment_mode' => 'ready_to_ship',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($admin)->put(onPanel('admin', "catalog/variants/{$listing->product_variant_id}"), ['sku' => 'NEW-SKU-1', 'weight_grams' => 900])->assertRedirect();

    expect(Product::query()->find($product->id)->status)->toBe('restricted')
        ->and($listing->variant->refresh()->sku)->toBe('NEW-SKU-1')
        // Product fields, the status move (QC-016 machine, wave 3) and the variant.
        ->and(Activity::query()->where('log_name', 'products')->where('event', 'updated')->count())->toBe(3)
        ->and(StateTransition::query()->where('subject_type', 'product')->where('to_status', 'restricted')->value('reason'))->toBe('Import licence pending');

    $this->actingAs($admin)->post(onPanel('admin', "approvals/listings/{$listing->id}/approve"))->assertSessionHasErrors();
    expect(Listing::query()->find($listing->id)->status)->toBe(ListingStatus::PendingReview);

    $marketing = adminAccount('uae', 'marketing');
    $this->actingAs($marketing)->get(onPanel('admin', "catalog/products/{$product->id}"))->assertOk();
    $this->actingAs($marketing)->put(onPanel('admin', "catalog/products/{$product->id}"), ['title_en' => 'x'])->assertForbidden();
});

it('QC-019: an account manager sees only products their vendors sell', function () {
    $manager = adminAccount('uae', 'account_manager');
    $mine = sellableListing(vendor: Vendor::factory()->create(['account_manager_id' => $manager->id]));
    $other = sellableListing();

    $this->actingAs($manager)->get(onPanel('admin', 'catalog/products'))->assertOk()
        ->assertSee($mine->variant->product->title_en)->assertDontSee($other->variant->product->title_en);
    $this->actingAs($manager)->get(onPanel('admin', "catalog/products/{$other->variant->product_id}"))->assertNotFound();
});
