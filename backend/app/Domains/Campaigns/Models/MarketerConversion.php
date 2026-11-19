<?php

namespace App\Domains\Campaigns\Models;

use App\Domains\Campaigns\Enums\ConversionStatus;
use App\Domains\Marketers\Models\MarketerProfile;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A commission earned on one source (order item) by one invitation —
 * unique per source × invitation, so recording is idempotent (linear
 * attribution creates one row per clicked invitation with its weight).
 * Gross/fee/net/bonus are snapshotted when recorded.
 *
 * @property ConversionStatus $status
 */
#[Fillable([
    'campaign_id', 'invitation_id', 'marketer_profile_id', 'type', 'source_type', 'source_id', 'order_id', 'sub_order_id', 'attribution', 'weight',
    'currency', 'basis', 'gross', 'fee', 'net', 'bonus', 'reversed_gross', 'status', 'clawback_amount', 'recovered_amount', 'approved_at', 'released_at', 'paid_at', 'reversed_at',
])]
class MarketerConversion extends Model
{
    use HasStateTransitions, UsesCountryDatabase;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ConversionStatus::class,
            'approved_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'reversed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<MarketerCampaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MarketerCampaign::class, 'campaign_id');
    }

    /**
     * @return BelongsTo<CampaignInvitation, $this>
     */
    public function invitation(): BelongsTo
    {
        return $this->belongsTo(CampaignInvitation::class, 'invitation_id');
    }

    /**
     * @return BelongsTo<MarketerProfile, $this>
     */
    public function marketer(): BelongsTo
    {
        return $this->belongsTo(MarketerProfile::class, 'marketer_profile_id');
    }
}
