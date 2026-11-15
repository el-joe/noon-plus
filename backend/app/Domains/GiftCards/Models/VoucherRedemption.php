<?php

namespace App\Domains\GiftCards\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['voucher_id', 'customer_user_id', 'promotional_credit_id'])]
class VoucherRedemption extends Model
{
    use UsesCountryDatabase;

    public const UPDATED_AT = null;
}
