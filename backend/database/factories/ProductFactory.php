<?php

namespace Database\Factories;

use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = Str::title(fake()->unique()->words(3, true));

        return [
            'category_id' => Category::factory(),
            'slug' => Str::slug($title).'-'.fake()->unique()->numberBetween(1, 99999),
            'title_en' => $title,
            'title_ar' => 'منتج '.$title,
            'status' => 'active',
            'fulfilment_mode' => 'ready_to_ship',
        ];
    }
}
