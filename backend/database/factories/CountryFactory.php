<?php

namespace Database\Factories;

use App\Domains\Countries\Models\Country;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Country>
 */
class CountryFactory extends Factory
{
    protected $model = Country::class;

    /**
     * Defaults to the UAE reference data; use {@see code()} for another country.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return Country::referenceAttributes('uae');
    }

    /**
     * A country from config/nawy.php by its code (uae, omn, ksa, egy, kwt, qat, bhr, jor).
     */
    public function code(string $code): static
    {
        return $this->state(fn (): array => Country::referenceAttributes($code));
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
