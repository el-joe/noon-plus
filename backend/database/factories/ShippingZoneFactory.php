<?php

namespace Database\Factories;

use App\Domains\Shipping\Models\ShippingZone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShippingZone>
 */
class ShippingZoneFactory extends Factory
{
    protected $model = ShippingZone::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->city(),
            'is_default' => false,
            'is_exceptional' => false,
            'vendor_subsidy_share_percent' => '0',
            'is_active' => true,
        ];
    }

    /**
     * The zone used when the destination matches no other zone.
     */
    public function asDefault(): static
    {
        return $this->state(fn (): array => ['is_default' => true]);
    }

    /**
     * Exceptional zone where the seller pays a share of subsidised delivery.
     */
    public function exceptional(string $vendorSharePercent): static
    {
        return $this->state(fn (): array => ['is_exceptional' => true, 'vendor_subsidy_share_percent' => $vendorSharePercent]);
    }

    /**
     * @param  array<int, string|array{0: string, 1: string}>  $places  city, or [city, area]
     */
    public function covering(array $places): static
    {
        return $this->afterCreating(function (ShippingZone $zone) use ($places): void {
            foreach ($places as $place) {
                [$city, $area] = is_array($place) ? $place : [$place, null];
                $zone->areas()->create(['city' => $city, 'area' => $area]);
            }
        });
    }
}
