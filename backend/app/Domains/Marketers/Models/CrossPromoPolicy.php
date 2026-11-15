<?php

namespace App\Domains\Marketers\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An admin-defined cross-promotion policy (FR-MKT-15). `eligibility`:
 * job_types, min_account_age_days, min_profile_completion, verified_only,
 * category_ids, min_products. `obligation`: activity (peer_promotion |
 * content_submission | accepted_campaign), count, period (month).
 * `allocation`: promoter_percent / owner_percent of the peer commission.
 * `penalty`: action (warning | suspend). `exemptions`: marketer ids.
 */
#[Fillable(['name', 'type', 'is_active', 'eligibility', 'obligation', 'allocation', 'penalty', 'exemptions', 'starts_on', 'ends_on'])]
class CrossPromoPolicy extends Model
{
    use UsesCountryDatabase;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'eligibility' => 'array',
            'obligation' => 'array',
            'allocation' => 'array',
            'penalty' => 'array',
            'exemptions' => 'array',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
        ];
    }

    /**
     * @return HasMany<CrossPromoParticipation, $this>
     */
    public function participations(): HasMany
    {
        return $this->hasMany(CrossPromoParticipation::class, 'policy_id');
    }
}
