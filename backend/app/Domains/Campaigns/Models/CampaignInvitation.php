<?php

namespace App\Domains\Campaigns\Models;

use App\Domains\Campaigns\Enums\InvitationSource;
use App\Domains\Campaigns\Enums\InvitationStatus;
use App\Domains\Marketers\Models\MarketerProfile;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An invitation of one marketer to one campaign (SRS §11.7): a fresh
 * 10-character referral code (globally unique via `referral_codes`), a
 * 12-hour expiry, the influencer fixed amount snapshot and the per-sale
 * rates snapshot.
 *
 * @property InvitationStatus $status
 * @property InvitationSource $source
 * @property array<string, string|null>|null $rate_snapshot
 */
#[Fillable([
    'campaign_id', 'marketer_profile_id', 'effective_type', 'source', 'status', 'referral_code', 'expires_at', 'influencer_fixed',
    'influencer_fixed_status', 'rate_snapshot', 'replaced_invitation_id', 'replaced_at', 'closed_at', 'accepted_at', 'response_note', 'whatsapp_sent_at',
])]
class CampaignInvitation extends Model
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
            'status' => InvitationStatus::class,
            'source' => InvitationSource::class,
            'rate_snapshot' => 'array',
            'expires_at' => 'immutable_datetime',
            'replaced_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'whatsapp_sent_at' => 'immutable_datetime',
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
     * @return BelongsTo<MarketerProfile, $this>
     */
    public function marketer(): BelongsTo
    {
        return $this->belongsTo(MarketerProfile::class, 'marketer_profile_id');
    }
}
