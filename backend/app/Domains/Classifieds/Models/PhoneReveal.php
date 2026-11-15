<?php

namespace App\Domains\Classifieds\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Log of a customer revealing the seller phone of an ad (MP v2 §14b).
 */
#[Fillable(['classified_ad_id', 'customer_user_id', 'ip_address', 'user_agent'])]
class PhoneReveal extends Model
{
    use UsesCountryDatabase;

    public const UPDATED_AT = null;

    protected $table = 'phone_reveals';
}
