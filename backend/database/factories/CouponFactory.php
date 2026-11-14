<?php

namespace Database\Factories;

use App\Domains\Coupons\Enums\CouponScope;
use App\Domains\Coupons\Enums\CouponType;
use App\Domains\Coupons\Models\Coupon;
use App\Domains\Vendors\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Coupon>
 */
class CouponFactory extends Factory
{
    protected $model = Coupon::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('NAWY##??')),
            'scope' => CouponScope::Platform,
            'type' => CouponType::Percentage,
            'value' => '10',
            'is_active' => true,
        ];
    }

    public function forVendor(Vendor $vendor): static
    {
        return $this->state(fn (): array => ['scope' => CouponScope::Vendor, 'vendor_id' => $vendor->id]);
    }
}
