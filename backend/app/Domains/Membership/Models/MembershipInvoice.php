<?php

namespace App\Domains\Membership\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One billed period of a membership (unique per membership and period start).
 */
#[Fillable(['number', 'membership_id', 'membership_plan_id', 'currency', 'amount', 'status', 'period_start', 'period_end', 'paid_at'])]
class MembershipInvoice extends Model
{
    use UsesCountryDatabase;

    /**
     * `payments.payable_type` of a membership invoice.
     */
    public const PAYABLE = 'membership_invoice';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['period_start' => 'immutable_datetime', 'period_end' => 'immutable_datetime', 'paid_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<Membership, $this>
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }
}
