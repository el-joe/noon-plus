<?php

namespace App\Domains\Advertising\Models;

use App\Domains\Advertising\Enums\AdBookingStatus;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A paid booking of an ad slot for a date range (Nawi Ads).
 */
#[Fillable(['number', 'ad_slot_id', 'advertiser_type', 'advertiser_id', 'starts_on', 'ends_on', 'days', 'currency', 'amount', 'creative_path', 'creative_type', 'link_url', 'headline', 'payment_status'])]
class AdBooking extends Model
{
    use HasStateTransitions, UsesCountryDatabase;

    protected $table = 'ad_bookings';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AdBookingStatus::class,
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'amount' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<AdSlot, $this>
     */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(AdSlot::class, 'ad_slot_id');
    }
}
