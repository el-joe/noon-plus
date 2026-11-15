<?php

namespace App\Domains\Advertising\Models;

use App\Domains\Advertising\Enums\ParticipationRequestStatus;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A vendor or marketer request to join a coupon invitation with an offered fee.
 */
#[Fillable(['coupon_participation_invitation_id', 'participant_type', 'participant_id', 'offered_fee', 'payment_method', 'proof_path', 'decision_note', 'paid_at'])]
class CouponParticipationRequest extends Model
{
    use HasStateTransitions, UsesCountryDatabase;

    protected $table = 'coupon_participation_requests';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ParticipationRequestStatus::class,
            'offered_fee' => 'decimal:4',
            'paid_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<CouponParticipationInvitation, $this>
     */
    public function invitation(): BelongsTo
    {
        return $this->belongsTo(CouponParticipationInvitation::class, 'coupon_participation_invitation_id');
    }
}
