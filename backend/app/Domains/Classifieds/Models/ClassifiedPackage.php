<?php

namespace App\Domains\Classifieds\Models;

use App\Domains\Classifieds\Enums\PackageType;
use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A paid upsell for an ad (featured, boost, republish) with its duration and price.
 */
#[Fillable(['code', 'type', 'name_en', 'name_ar', 'duration_days', 'price', 'is_active'])]
class ClassifiedPackage extends Model
{
    use UsesCountryDatabase;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['is_active' => true];

    protected $table = 'classified_packages';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PackageType::class,
            'price' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }
}
