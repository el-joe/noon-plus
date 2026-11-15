<?php

namespace App\Domains\Campaigns\Models;

use App\Domains\Campaigns\Enums\CampaignOrigin;
use App\Domains\Campaigns\Enums\CampaignStatus;
use App\Domains\Campaigns\Enums\FeeStatus;
use App\Domains\Campaigns\Support\CampaignStateMachine;
use App\Domains\Catalog\Models\Category;
use App\Domains\Listings\Models\Listing;
use App\Domains\Marketers\Models\MarketerProfile;
use App\Domains\Vendors\Models\Vendor;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A marketer campaign (SRS §11.3–11.5) promoting one listing. Budget null =
 * unlimited (then `ends_at` is required). Budget used = commissions spent +
 * influencer fixed amounts reserved. `vendor_id` is the store that pays
 * commissions, fees and sample commissions (none for platform campaigns).
 * Moves only through {@see CampaignStateMachine}.
 *
 * @property CampaignStatus $status
 * @property CampaignOrigin $origin
 * @property FeeStatus $fee_status
 */
#[Fillable([
    'number', 'origin', 'owner_type', 'owner_id', 'vendor_id', 'requested_by_marketer_id', 'listing_id', 'category_id', 'title', 'notes',
    'ad_types', 'planned_marketers', 'open_to_affiliates', 'attribution_model', 'currency', 'budget', 'budget_spent', 'influencer_fixed_reserved',
    'ends_at', 'status', 'owner_paused_at', 'auto_approve_at', 'live_at', 'done_at', 'influencers_settled_at', 'fee_amount', 'fee_snapshot',
    'fee_status', 'fee_charged_at', 'rejection_reason', 'created_by_user_id',
])]
class MarketerCampaign extends Model
{
    use HasStateTransitions, UsesCountryDatabase;

    public const OWNER_VENDOR = 'vendor';

    public const OWNER_PLATFORM = 'platform';

    public const OWNER_MARKETER = 'marketer';

    public const OWNER_AGENCY = 'agency';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'origin' => CampaignOrigin::class,
            'status' => CampaignStatus::class,
            'fee_status' => FeeStatus::class,
            'ad_types' => 'array',
            'planned_marketers' => 'array',
            'fee_snapshot' => 'array',
            'open_to_affiliates' => 'boolean',
            'ends_at' => 'immutable_datetime',
            'owner_paused_at' => 'immutable_datetime',
            'auto_approve_at' => 'immutable_datetime',
            'live_at' => 'immutable_datetime',
            'done_at' => 'immutable_datetime',
            'influencers_settled_at' => 'immutable_datetime',
            'fee_charged_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<MarketerProfile, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(MarketerProfile::class, 'requested_by_marketer_id');
    }

    /**
     * @return HasMany<CampaignInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(CampaignInvitation::class, 'campaign_id');
    }

    /**
     * @return HasMany<CampaignSample, $this>
     */
    public function samples(): HasMany
    {
        return $this->hasMany(CampaignSample::class, 'campaign_id');
    }

    /**
     * @return HasMany<MarketerConversion, $this>
     */
    public function conversions(): HasMany
    {
        return $this->hasMany(MarketerConversion::class, 'campaign_id');
    }

    /**
     * @return HasMany<ContentSubmission, $this>
     */
    public function contentSubmissions(): HasMany
    {
        return $this->hasMany(ContentSubmission::class, 'campaign_id');
    }

    public function isUnlimited(): bool
    {
        return $this->budget === null;
    }
}
