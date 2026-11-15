<?php

namespace App\Domains\Classifieds\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One attribute value of an ad, indexed for filtering (string or number).
 */
#[Fillable(['classified_ad_id', 'key', 'value_string', 'value_number'])]
class ClassifiedAdValue extends Model
{
    use UsesCountryDatabase;

    public $timestamps = false;

    protected $table = 'classified_ad_values';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value_number' => 'decimal:4',
        ];
    }
}
