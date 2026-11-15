<?php

namespace App\Domains\Travel\Models;

use App\Domains\Travel\Enums\PackageStatus;
use App\Domains\Vendors\Models\Vendor;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A trip package of a travel agency (vendor of type travel) with itinerary, inclusions and cancellation tiers.
 */
#[Fillable(['vendor_id', 'slug', 'title_en', 'title_ar', 'description_en', 'description_ar', 'trip_type', 'destination_city', 'destination_country', 'nights', 'itinerary', 'inclusions', 'exclusions', 'cancellation_policy', 'cover_path', 'contract_path'])]
class TravelPackage extends Model
{
    use HasStateTransitions, UsesCountryDatabase;

    protected $table = 'travel_packages';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PackageStatus::class,
            'itinerary' => 'array',
            'inclusions' => 'array',
            'exclusions' => 'array',
            'cancellation_policy' => 'array',
            'submitted_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * @return HasMany<TravelDeparture, $this>
     */
    public function departures(): HasMany
    {
        return $this->hasMany(TravelDeparture::class);
    }
}
