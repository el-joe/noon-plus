<?php

namespace App\Domains\Marketers\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One marketer's standing on one policy for one month (participation
 * dashboard): required vs completed, status pending | met | missed | exempt.
 */
#[Fillable(['policy_id', 'marketer_profile_id', 'period', 'required', 'completed', 'status', 'penalty', 'evaluated_at'])]
class CrossPromoParticipation extends Model
{
    use UsesCountryDatabase;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['penalty' => 'array', 'evaluated_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<CrossPromoPolicy, $this>
     */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(CrossPromoPolicy::class, 'policy_id');
    }
}
