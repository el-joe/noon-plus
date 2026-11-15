<?php

namespace App\Domains\Advertising\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A paid ad package marketers (or vendors) subscribe to.
 */
#[Fillable(['code', 'name_en', 'name_ar', 'target_type', 'price', 'duration_days', 'features', 'is_active'])]
class AdPackage extends Model
{
    use UsesCountryDatabase;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['is_active' => true, 'target_type' => 'marketer'];

    protected $table = 'ad_packages';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:4',
            'features' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
