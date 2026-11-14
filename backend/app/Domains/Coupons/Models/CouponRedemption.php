<?php

namespace App\Domains\Coupons\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['coupon_id', 'order_id', 'customer_user_id', 'discount_amount'])]
class CouponRedemption extends Model
{
    use UsesCountryDatabase;

    public const UPDATED_AT = null;
}
