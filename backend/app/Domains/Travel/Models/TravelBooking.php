<?php

namespace App\Domains\Travel\Models;

use App\Domains\Travel\Enums\BookingStatus;
use App\Domains\Vendors\Models\Vendor;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A booking request on a departure with the customer's contact details (no payment on Nawy); lifecycle in TravelBookings.
 */
#[Fillable(['number', 'customer_user_id', 'contact_name', 'contact_email', 'contact_phone', 'message', 'access_token', 'vendor_id', 'travel_package_id', 'travel_departure_id', 'rooms', 'seats', 'currency', 'total', 'hold_expires_at', 'marketer_profile_id', 'referral_code', 'locale'])]
class TravelBooking extends Model
{
    use HasStateTransitions, UsesCountryDatabase;

    protected $table = 'travel_bookings';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BookingStatus::class,
            'rooms' => 'array',
            'total' => 'decimal:4',
            'hold_expires_at' => 'immutable_datetime',
            'requested_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<TravelPackage, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(TravelPackage::class, 'travel_package_id');
    }

    /**
     * @return BelongsTo<TravelDeparture, $this>
     */
    public function departure(): BelongsTo
    {
        return $this->belongsTo(TravelDeparture::class, 'travel_departure_id');
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * @return HasMany<TravelTraveller, $this>
     */
    public function travellers(): HasMany
    {
        return $this->hasMany(TravelTraveller::class);
    }

    /**
     * @return HasMany<TravelBookingModification, $this>
     */
    public function modifications(): HasMany
    {
        return $this->hasMany(TravelBookingModification::class);
    }
}
