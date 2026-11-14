<?php

namespace Database\Factories;

use App\Domains\Vendors\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Vendor>
 */
class VendorFactory extends Factory
{
    protected $model = Vendor::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'owner_user_id' => fake()->numberBetween(1, 1_000_000),
            'type' => 'product',
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'store_name' => $name,
            'status' => Vendor::STATUS_ACTIVE,
            'rating' => '4.50',
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => ['status' => 'pending']);
    }
}
