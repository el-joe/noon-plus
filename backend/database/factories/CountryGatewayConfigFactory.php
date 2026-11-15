<?php

namespace Database\Factories;

use App\Domains\Countries\Models\Country;
use App\Domains\Payments\Enums\GatewayMode;
use App\Domains\Payments\Enums\PaymentMethod;
use App\Domains\Payments\Models\CountryGatewayConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CountryGatewayConfig>
 */
class CountryGatewayConfigFactory extends Factory
{
    protected $model = CountryGatewayConfig::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'country_id' => Country::factory(),
            'gateway' => PaymentMethod::Stripe,
            'is_enabled' => true,
            'mode' => GatewayMode::Sandbox,
            'credentials' => ['secret_key' => 'sk_test_123', 'webhook_secret' => 'whsec_test'],
            'currencies' => ['AED'],
            'sort_order' => 1,
            'is_recommended' => false,
        ];
    }
}
