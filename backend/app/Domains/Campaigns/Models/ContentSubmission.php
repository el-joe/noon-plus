<?php

namespace App\Domains\Campaigns\Models;

use App\Domains\Campaigns\Enums\ContentStatus;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A marketer's published content for a campaign (MP §8), reviewed by the
 * campaign owner or an admin.
 *
 * @property ContentStatus $status
 */
#[Fillable(['campaign_id', 'invitation_id', 'marketer_profile_id', 'platform', 'url', 'caption', 'status', 'rejection_reason', 'reviewed_by_user_id', 'reviewed_at'])]
class ContentSubmission extends Model
{
    use HasStateTransitions, UsesCountryDatabase;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => ContentStatus::class, 'reviewed_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<MarketerCampaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MarketerCampaign::class, 'campaign_id');
    }
}
