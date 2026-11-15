<?php

namespace App\Domains\Advertising\Models;

use App\Domains\Advertising\Enums\ParticipationInvitationStatus;
use App\Domains\Coupons\Models\Coupon;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An admin invitation to join a platform coupon for a fee (minimum fee, capacity, closing time).
 */
#[Fillable(['coupon_id', 'title', 'currency', 'min_fee', 'capacity', 'audience', 'closes_at', 'activated_at', 'created_by_user_id'])]
class CouponParticipationInvitation extends Model
{
    use HasStateTransitions, UsesCountryDatabase;

    protected $table = 'coupon_participation_invitations';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ParticipationInvitationStatus::class,
            'min_fee' => 'decimal:4',
            'audience' => 'array',
            'closes_at' => 'immutable_datetime',
            'activated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Coupon, $this>
     */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /**
     * @return HasMany<CouponParticipationRequest, $this>
     */
    public function requests(): HasMany
    {
        return $this->hasMany(CouponParticipationRequest::class);
    }
}
