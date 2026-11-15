<?php

namespace App\Domains\Classifieds\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Real-estate details of a property ad (spec §5.7): purpose rent / sale / off-plan, permit fields, size, rooms.
 */
#[Fillable(['classified_ad_id', 'purpose', 'property_type', 'bedrooms', 'bathrooms', 'area_sqm', 'furnishing', 'rent_period', 'permit_number', 'permit_authority', 'permit_expires_on', 'completion_on', 'developer', 'payment_plan', 'features', 'verified_at'])]
class PropertyDetail extends Model
{
    use UsesCountryDatabase;

    protected $table = 'property_details';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'permit_expires_on' => 'immutable_date',
            'completion_on' => 'immutable_date',
            'payment_plan' => 'array',
            'features' => 'array',
            'verified_at' => 'immutable_datetime',
            'area_sqm' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<ClassifiedAd, $this>
     */
    public function ad(): BelongsTo
    {
        return $this->belongsTo(ClassifiedAd::class, 'classified_ad_id');
    }
}
