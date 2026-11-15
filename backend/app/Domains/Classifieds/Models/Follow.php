<?php

namespace App\Domains\Classifieds\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A customer following a shop (vendor) or a creator / broker (marketer).
 */
#[Fillable(['customer_user_id', 'followable_type', 'followable_id'])]
class Follow extends Model
{
    use UsesCountryDatabase;

    protected $table = 'follows';
}
