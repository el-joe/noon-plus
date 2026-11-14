<?php

namespace Database\Seeders;

use App\Domains\Countries\Models\Country;
use Illuminate\Database\Seeder;

class CountrySeeder extends Seeder
{
    /**
     * Seed the 8 countries from config/nawy.php.
     */
    public function run(): void
    {
        foreach (array_keys(config('nawy.countries')) as $code) {
            Country::query()->updateOrCreate(['code' => $code], Country::referenceAttributes($code));
        }
    }
}
