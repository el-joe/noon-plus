<?php

use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\CategoryMeasurementField;
use App\Domains\Catalog\Models\SizeGuideTemplate;
use App\Domains\Catalog\Support\SizeGuides;
use App\Domains\Listings\Models\Listing;
use App\Domains\Orders\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->setUpCountries();
    $this->country = $this->actAsCountry('uae');
    $this->modest = Category::factory()->create(['slug' => 'modest-fashion']);
    $this->abayas = Category::factory()->create(['slug' => 'abayas', 'parent_id' => $this->modest->id]);
});

function abayaListing(string $mode = 'made_to_order'): Listing
{
    $listing = sellableListing('450.00');
    $listing->variant->product->update(['category_id' => test()->abayas->id, 'fulfilment_mode' => $mode, 'lead_days' => 10]);

    return $listing->refresh();
}

describe('size guides', function () {
    it('resolves the seller template over the platform one, switches units and finds the abaya length for a height', function () {
        $listing = abayaListing('ready_to_ship');
        SizeGuideTemplate::query()->create(['category_id' => $this->modest->id, 'name' => 'Platform alpha', 'type' => 'apparel_alpha', 'unit' => 'cm', 'columns' => ['size', 'chest'], 'rows' => [['size' => 'S', 'chest' => 88]]]);
        SizeGuideTemplate::query()->create(['category_id' => $this->abayas->id, 'vendor_id' => $listing->vendor_id, 'name' => 'Designer abaya', 'type' => 'abaya_height_length', 'unit' => 'cm',
            'columns' => ['height_min', 'height_max', 'length', 'sleeve'], 'rows' => [['height_min' => 150, 'height_max' => 159, 'length' => 54, 'sleeve' => 22], ['height_min' => 160, 'height_max' => 169, 'length' => 56, 'sleeve' => 23]]]);
        $slug = $listing->variant->product->slug;

        $this->getJson("/api/v1/uae-en/products/{$slug}/size-guide?height=165")->assertOk()
            ->assertJsonPath('data.name', 'Designer abaya')->assertJsonPath('data.recommendation.length_in', 56);
        $this->getJson("/api/v1/uae-en/products/{$slug}/size-guide?height=5.4&height_unit=ft")->assertOk()
            ->assertJsonPath('data.recommendation.height_cm', 162.6)->assertJsonPath('data.recommendation.sleeve_in', 23);
        $this->getJson("/api/v1/uae-en/products/{$slug}/size-guide?height=190")->assertStatus(422);

        $other = sellableListing('99.00');
        $other->variant->product->update(['category_id' => $this->abayas->id]);
        $this->getJson("/api/v1/uae-en/products/{$other->variant->product->slug}/size-guide?unit=in")->assertOk()
            ->assertJsonPath('data.name', 'Platform alpha')->assertJsonPath('data.unit', 'in')->assertJsonPath('data.rows.0.chest', 34.6)->assertJsonPath('data.rows.0.size', 'S');
        expect(app(SizeGuides::class)->feetToCm(5.0))->toBe(152.4);
    });
});

describe('made-to-order measurements', function () {
    beforeEach(function () {
        CategoryMeasurementField::query()->create(['category_id' => $this->modest->id, 'key' => 'height', 'label_en' => 'Height', 'label_ar' => 'الطول', 'unit' => 'cm', 'min_value' => 120, 'max_value' => 210]);
        CategoryMeasurementField::query()->create(['category_id' => $this->abayas->id, 'key' => 'sleeve', 'label_en' => 'Sleeve', 'label_ar' => 'الكم', 'unit' => 'in', 'min_value' => 15, 'max_value' => 30]);
        CategoryMeasurementField::query()->create(['category_id' => $this->abayas->id, 'key' => 'shoulder', 'label_en' => 'Shoulder', 'label_ar' => 'الكتف', 'unit' => 'cm', 'is_required' => false]);
    });

    it('asks the category fields on the cart line, blocks checkout without them and stores them on the order item for the seller', function () {
        $listing = abayaListing();
        [$customer, $token] = customerWithToken();
        $this->getJson("/api/v1/uae-en/products/{$listing->variant->product->slug}")->assertOk()
            ->assertJsonPath('data.made_to_order', true)->assertJsonCount(3, 'data.measurement_fields');
        $this->withToken($token)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 1])->assertOk();

        $address = ['name' => 'Sara Ahmed', 'phone' => '+971500000000', 'city' => 'Dubai', 'area' => 'Marina', 'street' => 'Gate 2'];
        giveCredits($customer->id, '1000.00');
        $this->withToken($token)->postJson('/api/v1/uae-en/orders', ['payment_method' => 'wallet', 'address' => $address])->assertStatus(409)->assertJsonPath('error.code', 'measurements_required');

        $this->withToken($token)->putJson("/api/v1/uae-en/cart/items/{$listing->id}/measurements", ['measurements' => ['height' => 300, 'sleeve' => 22]])
            ->assertUnprocessable()->assertJsonValidationErrors(['measurements.height'], 'error.fields');
        $this->withToken($token)->putJson("/api/v1/uae-en/cart/items/{$listing->id}/measurements", ['measurements' => ['height' => 164, 'sleeve' => 22.5], 'note' => 'Wide sleeves please'])
            ->assertOk()->assertJsonPath('data.shipments.0.items.0.measurements.sleeve', 22.5);
        $this->withToken($token)->postJson('/api/v1/uae-en/orders', ['payment_method' => 'wallet', 'address' => $address])->assertCreated();

        $item = OrderItem::query()->sole();
        expect($item->measurements)->toBe(['height' => 164, 'sleeve' => 22.5])->and($item->measurement_note)->toBe('Wide sleeves please');
    });

    it('refuses measurements for a ready-to-ship item', function () {
        $listing = abayaListing('ready_to_ship');
        [, $token] = customerWithToken();
        $this->withToken($token)->putJson('/api/v1/uae-en/cart/items', ['listing_id' => $listing->id, 'quantity' => 1]);

        $this->withToken($token)->putJson("/api/v1/uae-en/cart/items/{$listing->id}/measurements", ['measurements' => ['height' => 160]])->assertStatus(422)->assertJsonPath('error.code', 'measurements_not_needed');
    });
});
