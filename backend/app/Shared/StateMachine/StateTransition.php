<?php

namespace App\Shared\StateMachine;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One history row of a WP4 state machine (return, refund, dispute, warranty
 * claim, payout, COD settlement, gift card, voucher, membership): from, to,
 * actor and reason (lifecycle catalogue §0). Append-only.
 */
#[Fillable(['subject_type', 'subject_id', 'from_status', 'to_status', 'actor_type', 'actor_user_id', 'reason', 'meta'])]
class StateTransition extends Model
{
    use UsesCountryDatabase;

    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
