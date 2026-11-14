<?php

namespace Database\Factories;

use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Models\CountryCalendar;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CountryCalendar>
 */
class CountryCalendarFactory extends Factory
{
    protected $model = CountryCalendar::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'country_id' => Country::factory(),
            'starts_on' => '2026-12-02',
            'ends_on' => '2026-12-02',
            'name_en' => 'National Day',
            'name_ar' => 'اليوم الوطني',
            'is_confirmed' => false,
        ];
    }

    /**
     * A holiday from one country-local date to another (inclusive).
     */
    public function between(string $startsOn, ?string $endsOn = null): static
    {
        return $this->state(fn (): array => ['starts_on' => $startsOn, 'ends_on' => $endsOn ?? $startsOn]);
    }
}
