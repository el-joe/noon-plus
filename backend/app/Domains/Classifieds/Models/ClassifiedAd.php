<?php

namespace App\Domains\Classifieds\Models;

use App\Domains\Classifieds\Enums\ClassifiedAdStatus;
use App\Domains\Marketers\Models\MarketerProfile;
use App\Domains\Vendors\Models\Vendor;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A classified ad (offer or wanted) owned by a user, optionally through a verified business shop (vendor of type classified) or a broker (marketer profile). Status changes only through ClassifiedAdMachine.
 */
#[Fillable(['number', 'classified_category_id', 'owner_user_id', 'vendor_id', 'marketer_profile_id', 'kind', 'title', 'description', 'currency', 'price', 'is_negotiable', 'city', 'area', 'latitude', 'longitude', 'contact_phone', 'specs'])]
class ClassifiedAd extends Model
{
    use HasStateTransitions, UsesCountryDatabase;

    protected $table = 'classified_ads';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ClassifiedAdStatus::class,
            'price' => 'decimal:4',
            'sold_price' => 'decimal:4',
            'is_negotiable' => 'boolean',
            'specs' => 'array',
            'reminders_sent' => 'array',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'submitted_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'featured_until' => 'immutable_datetime',
            'boosted_until' => 'immutable_datetime',
            'bumped_at' => 'immutable_datetime',
            'sold_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ClassifiedCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ClassifiedCategory::class, 'classified_category_id');
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * @return BelongsTo<MarketerProfile, $this>
     */
    public function marketer(): BelongsTo
    {
        return $this->belongsTo(MarketerProfile::class, 'marketer_profile_id');
    }

    /**
     * @return HasMany<ClassifiedAdMedia, $this>
     */
    public function media(): HasMany
    {
        return $this->hasMany(ClassifiedAdMedia::class);
    }

    /**
     * @return HasMany<ClassifiedAdValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(ClassifiedAdValue::class);
    }

    /**
     * @return HasOne<PropertyDetail, $this>
     */
    public function property(): HasOne
    {
        return $this->hasOne(PropertyDetail::class);
    }

    /**
     * @return HasMany<ClassifiedThread, $this>
     */
    public function threads(): HasMany
    {
        return $this->hasMany(ClassifiedThread::class);
    }
}
