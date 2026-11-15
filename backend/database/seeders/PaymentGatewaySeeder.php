<?php

namespace Database\Seeders;

use App\Domains\Countries\Models\Country;
use App\Domains\Payments\Enums\GatewayMode;
use App\Domains\Payments\Enums\PaymentMethod;
use App\Domains\Payments\Models\CountryGatewayConfig;
use Illuminate\Database\Seeder;

/**
 * Default gateways per country (MASTER_PLAN §4a): the first one of each
 * country is the recommended one (Thawani in Oman, Stripe in the UAE,
 * PayTabs elsewhere). Rows start in sandbox without credentials (risk R4);
 * existing rows are left untouched so admin changes survive a re-seed.
 */
class PaymentGatewaySeeder extends Seeder
{
    public function run(): void
    {
        $limits = config('nawy.payments.amount_limits');

        foreach (config('nawy.payments.country_gateways') as $code => $gateways) {
            $country = Country::query()->where('code', $code)->first();

            if ($country === null) {
                continue;
            }

            foreach ($gateways as $position => $gateway) {
                $method = PaymentMethod::from($gateway);
                $limit = $limits[$gateway][$country->currency] ?? [null, null];

                CountryGatewayConfig::query()->firstOrCreate(
                    ['country_id' => $country->id, 'gateway' => $method],
                    [
                        'is_enabled' => ! in_array($gateway, config('nawy.payments.seeded_disabled'), true),
                        'mode' => GatewayMode::Sandbox,
                        'currencies' => [$country->currency],
                        'min_amount' => $limit[0],
                        'max_amount' => $limit[1],
                        'sort_order' => $position + 1,
                        'is_recommended' => $position === 0,
                    ],
                );
            }
        }
    }
}
