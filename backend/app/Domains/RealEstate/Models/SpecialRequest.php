<?php

namespace App\Domains\RealEstate\Models;

use App\Domains\RealEstate\Enums\SpecialRequestStatus;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A customer special request ("looking for a 3-bedroom villa") that one broker claims.
 */
#[Fillable(['number', 'customer_user_id', 'classified_category_id', 'purpose', 'property_type', 'city', 'bedrooms', 'budget_min', 'budget_max', 'details'])]
class SpecialRequest extends Model
{
    use HasStateTransitions, UsesCountryDatabase;

    protected $table = 'special_requests';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SpecialRequestStatus::class,
            'budget_min' => 'decimal:4',
            'budget_max' => 'decimal:4',
            'claimed_at' => 'immutable_datetime',
        ];
    }
}
