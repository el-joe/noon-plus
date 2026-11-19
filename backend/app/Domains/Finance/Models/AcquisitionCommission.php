<?php

namespace App\Domains\Finance\Models;

use App\Domains\Finance\Enums\AcquisitionCommissionStatus;
use App\Domains\Vendors\Models\Vendor;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One month of acquisition commission for the admin who brought a vendor in
 * (unique per vendor and month).
 */
#[Fillable([
    'number', 'acquirer_user_id', 'vendor_id', 'acquisition_commission_rule_id', 'period_start', 'period_end', 'currency',
    'sales_amount', 'sub_orders_count', 'rate_percent', 'amount', 'status', 'approved_by', 'approved_at', 'paid_by', 'paid_at',
    'bank_reference', 'rejection_reason',
])]
class AcquisitionCommission extends Model
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
            'status' => AcquisitionCommissionStatus::class,
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'approved_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * @return BelongsTo<AcquisitionCommissionRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(AcquisitionCommissionRule::class, 'acquisition_commission_rule_id');
    }
}
