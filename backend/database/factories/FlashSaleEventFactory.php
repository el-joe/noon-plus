<?php

namespace Database\Factories;

use App\Domains\FlashSales\Enums\FlashSaleEventStatus;
use App\Domains\FlashSales\Models\FlashSaleEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FlashSaleEvent>
 */
class FlashSaleEventFactory extends Factory
{
    protected $model = FlashSaleEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Mega Deals '.fake()->unique()->numberBetween(1, 9999),
            'status' => FlashSaleEventStatus::Draft,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(2),
            'max_qty_per_customer' => 2,
        ];
    }

    /**
     * Event in a given status (set directly, for arranging tests).
     */
    public function inStatus(FlashSaleEventStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    /**
     * Running now: started an hour ago, ends in an hour.
     */
    public function running(): static
    {
        return $this->state(fn (): array => [
            'status' => FlashSaleEventStatus::Live,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);
    }
}
