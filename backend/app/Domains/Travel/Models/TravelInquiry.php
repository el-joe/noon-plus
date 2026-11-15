<?php

namespace App\Domains\Travel\Models;

use App\Domains\Travel\Enums\InquiryStatus;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer or guest inquiry about a package.
 */
#[Fillable(['travel_package_id', 'vendor_id', 'customer_user_id', 'name', 'email', 'phone', 'message', 'travel_booking_id'])]
class TravelInquiry extends Model
{
    use HasStateTransitions, UsesCountryDatabase;

    protected $table = 'travel_inquiries';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => InquiryStatus::class,
        ];
    }

    /**
     * @return BelongsTo<TravelPackage, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(TravelPackage::class, 'travel_package_id');
    }
}
