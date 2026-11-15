<?php

namespace Database\Seeders;

use App\Domains\Catalog\Models\Category;
use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Membership\Models\MembershipPlan;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Development only (QA wave 2, QA-011): gives every country a few
 * categories and a monthly Nawy One plan so each market can list products
 * and sell membership. Idempotent (matched by slug / code; existing rows
 * are left as they are). Refuses to run in production.
 *
 * php artisan db:seed --class=Database\\Seeders\\DevCatalogSeeder
 */
class DevCatalogSeeder extends Seeder
{
    /**
     * @var array<string, array{0: string, 1: string, 2: array<string, array{0: string, 1: string}>}>
     */
    private const TREE = [
        'electronics' => ['Electronics', 'إلكترونيات', ['mobiles' => ['Mobiles', 'هواتف'], 'audio' => ['Audio', 'صوتيات']]],
        'fashion' => ['Fashion', 'أزياء', ['abayas' => ['Abayas', 'عبايات'], 'mens-clothing' => ['Men\'s clothing', 'ملابس رجالية']]],
        'home-kitchen' => ['Home & kitchen', 'المنزل والمطبخ', ['kitchen-appliances' => ['Kitchen appliances', 'أجهزة المطبخ']]],
    ];

    /**
     * Monthly Nawy One price per currency (proposed defaults, settings catalogue §9).
     *
     * @var array<string, string>
     */
    private const PLAN_PRICE = ['AED' => '25.00', 'SAR' => '25.00', 'OMR' => '2.500', 'KWD' => '2.000', 'BHD' => '2.500', 'QAR' => '25.00', 'EGP' => '99.00', 'JOD' => '5.000'];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DevCatalogSeeder is for development databases only.');
        }

        $context = app(CountryContext::class);

        foreach (Country::query()->get() as $country) {
            $context->runAs($country, function (Country $country): void {
                $sort = 0;

                foreach (self::TREE as $slug => [$nameEn, $nameAr, $children]) {
                    $parent = Category::query()->firstOrCreate(['slug' => $slug], ['name_en' => $nameEn, 'name_ar' => $nameAr, 'is_active' => true, 'sort_order' => $sort++]);

                    foreach ($children as $childSlug => [$childEn, $childAr]) {
                        Category::query()->firstOrCreate(['slug' => $childSlug], ['parent_id' => $parent->id, 'name_en' => $childEn, 'name_ar' => $childAr, 'is_active' => true]);
                    }
                }

                MembershipPlan::query()->firstOrCreate(['code' => 'one-monthly'], [
                    'name_en' => 'Nawy One', 'name_ar' => 'ناوي ون', 'interval' => 'monthly',
                    'price' => self::PLAN_PRICE[$country->currency] ?? '25.00', 'trial_days' => 0, 'free_shipping' => true, 'is_active' => true,
                ]);
            });
        }
    }
}
