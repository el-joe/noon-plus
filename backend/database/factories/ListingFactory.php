<?php

namespace Database\Factories;

use App\Domains\Catalog\Models\ProductVariant;
use App\Domains\Listings\Enums\ListingStatus;
use App\Domains\Listings\Models\Listing;
use App\Domains\Vendors\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Listing>
 */
class ListingFactory extends Factory
{
    protected $model = Listing::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vendor_id' => Vendor::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'status' => ListingStatus::Draft,
            'price' => '249.0000',
            'fulfilment' => 'fbm',
            'condition' => 'new',
            'handling_days' => 1,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => ['status' => ListingStatus::Active]);
    }
}
