<?php

namespace Database\Seeders;

use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Fulfilment\Models\Warehouse;
use App\Domains\Fulfilment\Models\WarehouseStock;
use App\Domains\Fulfilment\Support\FbnEnrolment;
use App\Domains\Fulfilment\Support\WarehouseStockService;
use App\Domains\Listings\Enums\ListingStatus;
use App\Domains\Listings\Models\Listing;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Development only: two Fulfilled-by-Nawy warehouses per country and FBN
 * stock for a few demo listings (active, created on this platform — never
 * a migrated legacy row, never an e2e store). Idempotent: warehouses are
 * matched by code; a listing already in a warehouse is left as it is.
 * Refuses to run in production.
 *
 * php artisan db:seed --class=Database\\Seeders\\FbnDevSeeder
 */
class FbnDevSeeder extends Seeder
{
    /**
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    private const CITIES = [
        'uae' => ['Dubai', 'Jebel Ali', 'Abu Dhabi'], 'omn' => ['Muscat', 'Rusayl', 'Sohar'], 'ksa' => ['Riyadh', 'Sulay', 'Jeddah'],
        'egy' => ['Cairo', '6th of October', 'Alexandria'], 'kwt' => ['Kuwait City', 'Shuwaikh', 'Ahmadi'], 'qat' => ['Doha', 'Industrial Area', 'Al Wakrah'],
        'bhr' => ['Manama', 'Sitra', 'Muharraq'], 'jor' => ['Amman', 'Sahab', 'Zarqa'],
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('FbnDevSeeder is for development databases only.');
        }

        $context = app(CountryContext::class);

        foreach (Country::query()->get() as $country) {
            $context->runAs($country, function (Country $country): void {
                [$city, $area, $second] = self::CITIES[$country->code] ?? ['Main city', 'Logistics park', 'Second city'];
                $iso = strtoupper($country->iso2);
                $main = Warehouse::query()->firstOrCreate(['code' => "{$iso}-FC1"], [
                    'name_en' => "{$city} Fulfilment Centre", 'name_ar' => "مركز الاستيفاء {$city}", 'address' => "{$area}, warehouse 1", 'city' => $city,
                    'capacity_cubic_feet' => '250000', 'is_active' => true,
                ]);
                Warehouse::query()->firstOrCreate(['code' => "{$iso}-FC2"], [
                    'name_en' => "{$second} Fulfilment Centre", 'name_ar' => "مركز الاستيفاء {$second}", 'address' => "{$second} logistics park", 'city' => $second,
                    'capacity_cubic_feet' => '120000', 'is_active' => true,
                ]);

                $demo = Listing::query()->whereNull('legacy_id')->where('status', ListingStatus::Active)
                    ->whereHas('vendor', fn ($vendor) => $vendor->whereNull('legacy_id')->where('type', 'product')->where('slug', 'not like', 'e2e-%'))
                    ->whereNotIn('id', WarehouseStock::query()->select('listing_id'))
                    ->with('variant')->orderBy('id')->limit(4)->get();

                foreach ($demo as $index => $listing) {
                    app(FbnEnrolment::class)->enrol($listing, null);
                    app(WarehouseStockService::class)->receive($main, $listing->refresh()->load('variant'), 20 + 10 * $index, $index === 0 ? 2 : 0, 'A-0'.($index + 1), 'dev_seed', 0, null);
                }
            });
        }
    }
}
