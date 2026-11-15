<?php

namespace App\Domains\Campaigns\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recorded `/r/{code}` visit, kept for the attribution window.
 */
#[Fillable(['invitation_id', 'campaign_id', 'marketer_profile_id', 'listing_id', 'visitor_token', 'customer_user_id', 'ip_address', 'user_agent', 'clicked_at', 'expires_at'])]
class ReferralClick extends Model
{
    use UsesCountryDatabase;

    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['clicked_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<CampaignInvitation, $this>
     */
    public function invitation(): BelongsTo
    {
        return $this->belongsTo(CampaignInvitation::class, 'invitation_id');
    }
}
