<?php

namespace App\Domains\Travel\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * An optional traveller name on a booking request.
 */
#[Fillable(['travel_booking_id', 'full_name'])]
class TravelTraveller extends Model
{
    use UsesCountryDatabase;

    protected $table = 'travel_travellers';
}
