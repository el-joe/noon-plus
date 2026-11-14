<?php

namespace Database\Factories;

use App\Domains\Shipping\Enums\ShippingMethod;
use App\Domains\Shipping\Models\ShippingRate;
use App\Domains\Shipping\Models\ShippingZone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShippingRate>
 */
class ShippingRateFactory extends Factory
{
    protected $model = ShippingRate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shipping_zone_id' => ShippingZone::factory(),
            'method' => ShippingMethod::Standard,
            'base_fee' => '15.0000',
            'base_weight_kg' => '1.000',
            'per_kg_fee' => '2.0000',
            'transit_min_days' => 1,
            'transit_max_days' => 2,
            'cutoff_time' => null,
            'is_active' => true,
        ];
    }

    public function method(ShippingMethod $method): static
    {
        return $this->state(fn (): array => ['method' => $method]);
    }
}
