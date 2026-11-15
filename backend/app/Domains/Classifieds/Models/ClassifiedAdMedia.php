<?php

namespace App\Domains\Classifieds\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A photo or the short video of an ad (owner media, public disk).
 */
#[Fillable(['classified_ad_id', 'type', 'path', 'duration_seconds', 'sort_order'])]
class ClassifiedAdMedia extends Model
{
    use UsesCountryDatabase;

    protected $table = 'classified_ad_media';
}
