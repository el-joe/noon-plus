<?php

namespace Database\Factories;

use App\Domains\Catalog\Models\Category;
use App\Domains\Warranty\Models\WarrantyPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WarrantyPlan>
 */
class WarrantyPlanFactory extends Factory
{
    protected $model = WarrantyPlan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'name_en' => '1-year extended warranty',
            'name_ar' => 'ضمان ممتد لمدة سنة',
            'duration_months' => 12,
            'price' => '49.0000',
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
