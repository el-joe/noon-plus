<?php

namespace App\Domains\Membership\Models;

use App\Domains\Membership\Enums\MembershipStatus;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer's Nawy One membership in one country (one row per customer).
 */
#[Fillable(['customer_user_id', 'membership_plan_id', 'status', 'current_period_start', 'current_period_end', 'auto_renew', 'past_due_since'])]
class Membership extends Model
{
    use HasStateTransitions, UsesCountryDatabase;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MembershipStatus::class,
            'auto_renew' => 'boolean',
            'current_period_start' => 'immutable_datetime',
            'current_period_end' => 'immutable_datetime',
            'past_due_since' => 'immutable_datetime',
        ];
    }

    /**
     * Benefits apply while trialing or active inside the paid period.
     */
    public function givesBenefits(): bool
    {
        return in_array($this->status, [MembershipStatus::Trialing, MembershipStatus::Active], true)
            && $this->current_period_end !== null && $this->current_period_end->isFuture();
    }

    /**
     * @return BelongsTo<MembershipPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'membership_plan_id');
    }

    /**
     * @return HasMany<MembershipInvoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(MembershipInvoice::class)->orderBy('id');
    }
}
