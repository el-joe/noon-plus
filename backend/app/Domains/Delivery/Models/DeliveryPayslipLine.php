<?php

namespace App\Domains\Delivery\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One finished assignment on a payslip (delivered or failed attempt).
 */
#[Fillable(['delivery_payslip_id', 'delivery_assignment_id', 'outcome', 'finished_at', 'amount'])]
class DeliveryPayslipLine extends Model
{
    use UsesCountryDatabase;

    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['finished_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<DeliveryAssignment, $this>
     */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(DeliveryAssignment::class, 'delivery_assignment_id');
    }
}
