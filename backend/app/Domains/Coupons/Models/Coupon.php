<?php

namespace App\Domains\Coupons\Models;

use App\Domains\Coupons\Enums\CouponFunding;
use App\Domains\Coupons\Enums\CouponScope;
use App\Domains\Coupons\Enums\CouponTarget;
use App\Domains\Coupons\Enums\CouponType;
use App\Domains\Coupons\Enums\CustomerSegment;
use App\Shared\Audit\LogsCountryActivity;
use App\Shared\Database\UsesCountryDatabase;
use Database\Factories\CouponFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[UseFactory(CouponFactory::class)]
#[Fillable([
    'code', 'description', 'scope', 'vendor_id', 'funded_by', 'target_type', 'target_ids', 'first_order_only', 'customer_segment',
    'type', 'value', 'buy_quantity', 'get_quantity', 'min_order_amount', 'max_discount_amount',
    'usage_limit', 'per_customer_limit', 'starts_at', 'ends_at', 'is_active', 'created_by_user_id',
])]
class Coupon extends Model
{
    /** @use HasFactory<CouponFactory> */
    use HasFactory, LogsCountryActivity, UsesCountryDatabase;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => CouponScope::class,
            'type' => CouponType::class,
            'funded_by' => CouponFunding::class,
            'target_type' => CouponTarget::class,
            'target_ids' => 'array',
            'first_order_only' => 'boolean',
            'customer_segment' => CustomerSegment::class,
            'buy_quantity' => 'integer',
            'get_quantity' => 'integer',
            'value' => 'decimal:4',
            'min_order_amount' => 'decimal:4',
            'max_discount_amount' => 'decimal:4',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['funded_by' => 'platform', 'target_type' => 'all', 'customer_segment' => 'everyone', 'first_order_only' => false];

    /**
     * Who pays: seller coupons are always the seller's.
     */
    public function funding(): CouponFunding
    {
        return $this->scope === CouponScope::Vendor ? CouponFunding::Vendor : ($this->funded_by ?? CouponFunding::Platform);
    }

    /**
     * Needs to know who the customer is (first order or a segment).
     */
    public function needsCustomer(): bool
    {
        return $this->first_order_only || ($this->customer_segment ?? CustomerSegment::Everyone) !== CustomerSegment::Everyone;
    }

    /**
     * @return array<int, int>
     */
    public function targetIds(): array
    {
        return array_values(array_map('intval', $this->target_ids ?? []));
    }

    /**
     * @return HasMany<CouponRedemption, $this>
     */
    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }
}
