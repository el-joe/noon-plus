<?php

namespace App\Domains\Finance\Models;

use App\Domains\Finance\Enums\CodSettlementStatus;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The COD cash of one agent for one business day (SRS §8, generated daily
 * 23:30). Marking it settled unlocks the vendor payouts of its sub-orders.
 */
#[Fillable(['number', 'agent_user_id', 'agent_key', 'business_date', 'currency', 'expected_amount', 'remitted_amount', 'collections_count', 'status', 'note', 'settled_at'])]
class CodSettlement extends Model
{
    use HasStateTransitions, UsesCountryDatabase;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => CodSettlementStatus::class, 'business_date' => 'immutable_date', 'settled_at' => 'immutable_datetime'];
    }

    /**
     * @return HasMany<CodCollection, $this>
     */
    public function collections(): HasMany
    {
        return $this->hasMany(CodCollection::class)->orderBy('id');
    }
}
