<?php

namespace App\Domains\Travel\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Price per person of a room type (single, double, triple, quad, child) on a departure.
 */
#[Fillable(['travel_departure_id', 'room_type', 'price_per_person'])]
class TravelDepartureRoom extends Model
{
    use UsesCountryDatabase;

    protected $table = 'travel_departure_rooms';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_per_person' => 'decimal:4',
        ];
    }
}
