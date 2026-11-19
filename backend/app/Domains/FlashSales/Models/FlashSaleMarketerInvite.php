<?php

namespace App\Domains\FlashSales\Models;

use App\Domains\FlashSales\Enums\FlashSaleInviteStatus;
use App\Domains\Marketers\Models\MarketerProfile;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A marketer invited to promote a flash-sale event (lifecycle catalogue
 * §4.10 sub-machine). One per event and marketer.
 *
 * @property FlashSaleInviteStatus $status
 */
#[Fillable(['flash_sale_event_id', 'marketer_profile_id', 'status', 'invited_by', 'responded_at'])]
class FlashSaleMarketerInvite extends Model
{
    use HasStateTransitions, UsesCountryDatabase;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => FlashSaleInviteStatus::class, 'responded_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<FlashSaleEvent, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(FlashSaleEvent::class, 'flash_sale_event_id');
    }

    /**
     * @return BelongsTo<MarketerProfile, $this>
     */
    public function marketer(): BelongsTo
    {
        return $this->belongsTo(MarketerProfile::class, 'marketer_profile_id');
    }
}
