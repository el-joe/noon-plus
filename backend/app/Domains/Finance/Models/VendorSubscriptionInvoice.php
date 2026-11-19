<?php

namespace App\Domains\Finance\Models;

use App\Domains\Vendors\Models\Vendor;
use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One billed period of a vendor subscription: pending until paid by card
 * (gateway payable) or deducted from the next payout statement; void when
 * the subscription ends unpaid. Amount includes VAT.
 */
#[Fillable([
    'number', 'vendor_subscription_id', 'vendor_id', 'vendor_subscription_plan_id', 'currency', 'amount', 'vat', 'status',
    'period_start', 'period_end', 'due_at', 'paid_at', 'paid_via', 'reminders_sent', 'last_reminded_at',
    'auto_charge_attempts', 'next_auto_charge_at', 'auto_charge_failure', 'payment_id', 'paid_amount', 'charge_currency', 'charge_amount', 'exchange_rate',
])]
class VendorSubscriptionInvoice extends Model
{
    use UsesCountryDatabase;

    /**
     * `payments.payable_type` of a vendor subscription invoice.
     */
    public const PAYABLE = 'vendor_subscription_invoice';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_VOID = 'void';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_datetime',
            'period_end' => 'immutable_datetime',
            'due_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'last_reminded_at' => 'immutable_datetime',
            'next_auto_charge_at' => 'immutable_datetime',
            'auto_charge_attempts' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<VendorSubscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(VendorSubscription::class, 'vendor_subscription_id');
    }

    /**
     * @return BelongsTo<VendorSubscriptionPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(VendorSubscriptionPlan::class, 'vendor_subscription_plan_id');
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
