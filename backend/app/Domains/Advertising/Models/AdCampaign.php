<?php

namespace App\Domains\Advertising\Models;

use App\Domains\Advertising\Enums\AdCampaignStatus;
use App\Domains\Listings\Models\Listing;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A self-serve sponsored-product campaign (CPC / CPM) promoting one listing, paced by a daily and an optional total budget.
 */
#[Fillable(['number', 'advertiser_type', 'advertiser_id', 'listing_id', 'name', 'pricing_model', 'bid', 'currency', 'daily_budget', 'total_budget', 'keywords', 'category_ids', 'starts_on', 'ends_on'])]
class AdCampaign extends Model
{
    use HasStateTransitions, UsesCountryDatabase;

    public const ADVERTISER_VENDOR = 'vendor';

    public const ADVERTISER_MARKETER = 'marketer';

    protected $table = 'ad_campaigns';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AdCampaignStatus::class,
            'bid' => 'decimal:4',
            'daily_budget' => 'decimal:4',
            'total_budget' => 'decimal:4',
            'spent_today' => 'decimal:4',
            'spent_total' => 'decimal:4',
            'unbilled_spend' => 'decimal:4',
            'keywords' => 'array',
            'category_ids' => 'array',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'spend_date' => 'immutable_date',
            'total_exhausted' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }
}
