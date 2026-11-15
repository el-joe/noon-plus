<?php

namespace App\Domains\RealEstate\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Broker extras of a marketer profile with the broker job type (licence, office, areas).
 */
#[Fillable(['marketer_profile_id', 'licence_number', 'licence_authority', 'licence_expires_on', 'office_name', 'areas', 'specialties', 'languages'])]
class BrokerDetail extends Model
{
    use UsesCountryDatabase;

    protected $table = 'broker_details';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'licence_expires_on' => 'immutable_date',
            'areas' => 'array',
            'specialties' => 'array',
            'languages' => 'array',
        ];
    }
}
