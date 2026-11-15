<?php

namespace App\Domains\Finance\Models;

use App\Domains\Finance\Enums\PayoutSchedule;
use App\Domains\Finance\Enums\PayoutStatus;
use App\Domains\Vendors\Models\Vendor;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A vendor payout statement (SRS §9): the eligible balance items of a
 * period, approved and paid by an admin — never automatically.
 */
#[Fillable([
    'number', 'vendor_id', 'currency', 'status', 'schedule', 'period_start', 'period_end', 'total', 'generated_by_user_id',
    'approved_by_user_id', 'hold_reason', 'failure_reason', 'bank_reference', 'approved_at', 'completed_at',
])]
class VendorPayout extends Model
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
            'status' => PayoutStatus::class,
            'schedule' => PayoutSchedule::class,
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'approved_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
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
     * @return HasMany<VendorBalanceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(VendorBalanceItem::class)->orderBy('id');
    }
}
