<?php

namespace App\Domains\Travel\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One departure date of a package with its seat capacity. `seats_reserved` (held + booked) changes only under a row lock in SeatAllocator.
 */
#[Fillable(['travel_package_id', 'departs_on', 'returns_on', 'seat_capacity', 'status'])]
class TravelDeparture extends Model
{
    use UsesCountryDatabase;

    protected $table = 'travel_departures';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'departs_on' => 'immutable_date',
            'returns_on' => 'immutable_date',
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
     * @return HasMany<TravelDepartureRoom, $this>
     */
    public function rooms(): HasMany
    {
        return $this->hasMany(TravelDepartureRoom::class);
    }

    /**
     * @return HasMany<TravelBooking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(TravelBooking::class, 'travel_departure_id');
    }
}
