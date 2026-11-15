<?php

namespace App\Domains\Classifieds\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A customer favourite ad.
 */
#[Fillable(['customer_user_id', 'classified_ad_id'])]
class ClassifiedFavourite extends Model
{
    use UsesCountryDatabase;

    protected $table = 'classified_favourites';
}
