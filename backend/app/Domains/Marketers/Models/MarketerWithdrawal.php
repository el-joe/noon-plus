<?php

namespace App\Domains\Marketers\Models;

use App\Domains\Marketers\Enums\WithdrawalStatus;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A marketer wallet withdrawal (lifecycle catalogue §3.2).
 *
 * @property WithdrawalStatus $status
 */
#[Fillable(['number', 'marketer_profile_id', 'currency', 'amount', 'status', 'payout_details', 'reason', 'approved_by_user_id', 'processed_at'])]
class MarketerWithdrawal extends Model
{
    use HasStateTransitions, UsesCountryDatabase;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => WithdrawalStatus::class, 'payout_details' => 'array', 'processed_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<MarketerProfile, $this>
     */
    public function marketer(): BelongsTo
    {
        return $this->belongsTo(MarketerProfile::class, 'marketer_profile_id');
    }
}
