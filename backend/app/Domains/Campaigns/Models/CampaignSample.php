<?php

namespace App\Domains\Campaigns\Models;

use App\Domains\Campaigns\Enums\SampleStatus;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A promotion sample (SRS §11.9): kind platform | influencer | affiliate.
 *
 * @property SampleStatus $status
 */
#[Fillable(['campaign_id', 'invitation_id', 'marketer_profile_id', 'kind', 'quantity', 'status', 'address', 'tracking_number', 'commission_amount', 'shipped_at', 'delivered_at', 'review_due_at', 'returned_at'])]
class CampaignSample extends Model
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
            'status' => SampleStatus::class,
            'address' => 'array',
            'shipped_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'review_due_at' => 'immutable_datetime',
            'returned_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<MarketerCampaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MarketerCampaign::class, 'campaign_id');
    }
}
