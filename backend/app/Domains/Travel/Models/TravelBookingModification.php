<?php

namespace App\Domains\Travel\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A change request on a booking (change departure, rooms, name correction, other): requested → approved | rejected.
 */
#[Fillable(['travel_booking_id', 'type', 'details', 'status', 'decision_note', 'requested_by_user_id', 'decided_by_user_id'])]
class TravelBookingModification extends Model
{
    use UsesCountryDatabase;

    protected $table = 'travel_booking_modifications';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'details' => 'array',
        ];
    }

    /**
     * @return BelongsTo<TravelBooking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(TravelBooking::class, 'travel_booking_id');
    }
}
