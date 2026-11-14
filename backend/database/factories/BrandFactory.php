<?php

namespace Database\Factories;

use App\Domains\Catalog\Models\Brand;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Brand>
 */
class BrandFactory extends Factory
{
    protected $model = Brand::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::title(fake()->unique()->word()).' '.fake()->randomElement(['Audio', 'Home', 'Wear', 'Tech']);

        return ['slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999), 'name' => $name, 'is_active' => true];
    }
}
