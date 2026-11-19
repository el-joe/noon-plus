<?php

namespace App\Domains\Finance\Models;

use App\Domains\Finance\Enums\SubscriptionPaymentPreference;
use App\Domains\Finance\Enums\VendorSubscriptionStatus;
use App\Domains\Vendors\Models\Vendor;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A vendor's plan subscription in one country (lifecycle catalogue §3.6).
 * A vendor has at most one open (trialing / active / past_due) subscription.
 */
#[Fillable([
    'vendor_id', 'vendor_subscription_plan_id', 'pending_plan_id', 'status', 'payment_preference', 'auto_renew', 'saved_payment_card_id', 'current_period_start',
    'current_period_end', 'trial_ends_at', 'cancel_at_period_end', 'past_due_since', 'ended_at',
])]
class VendorSubscription extends Model
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
            'status' => VendorSubscriptionStatus::class,
            'payment_preference' => SubscriptionPaymentPreference::class,
            'cancel_at_period_end' => 'boolean',
            'auto_renew' => 'boolean',
            'current_period_start' => 'immutable_datetime',
            'current_period_end' => 'immutable_datetime',
            'trial_ends_at' => 'immutable_datetime',
            'past_due_since' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<VendorSubscriptionPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(VendorSubscriptionPlan::class, 'vendor_subscription_plan_id');
    }

    /**
     * @return BelongsTo<VendorSubscriptionPlan, $this>
     */
    public function pendingPlan(): BelongsTo
    {
        return $this->belongsTo(VendorSubscriptionPlan::class, 'pending_plan_id');
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * @return HasMany<VendorSubscriptionInvoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(VendorSubscriptionInvoice::class)->orderByDesc('id');
    }
}
