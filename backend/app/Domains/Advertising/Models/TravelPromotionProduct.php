<?php

namespace App\Domains\Advertising\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A travel promotion option on sale in this country (D14): a duration with
 * its price (VAT included) on the travel placement slot. Admin sets these up.
 */
#[Fillable(['ad_slot_id', 'code', 'name_en', 'name_ar', 'duration_days', 'price', 'is_active'])]
class TravelPromotionProduct extends Model
{
    use UsesCountryDatabase;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['is_active' => true];

    protected $table = 'travel_promotion_products';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:4',
            'duration_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<AdSlot, $this>
     */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(AdSlot::class, 'ad_slot_id');
    }

    public function localizedName(): string
    {
        return app()->getLocale() === 'ar' && filled($this->name_ar) ? $this->name_ar : $this->name_en;
    }
}
