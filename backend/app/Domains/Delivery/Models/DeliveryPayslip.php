<?php

namespace App\Domains\Delivery\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An agent's payslip for one pay period: the finished assignments (lines)
 * at the rates of the day it was issued. Issuing credits the agent wallet.
 */
#[Fillable(['number', 'delivery_agent_id', 'carrier_id', 'currency', 'period_start', 'period_end', 'delivered', 'failed', 'per_stop', 'per_failed', 'total', 'issued_at'])]
class DeliveryPayslip extends Model
{
    use UsesCountryDatabase;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'issued_at' => 'immutable_datetime',
            'delivered' => 'integer',
            'failed' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<DeliveryAgent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(DeliveryAgent::class, 'delivery_agent_id');
    }

    /**
     * @return BelongsTo<Carrier, $this>
     */
    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    /**
     * @return HasMany<DeliveryPayslipLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(DeliveryPayslipLine::class)->orderBy('finished_at')->orderBy('id');
    }
}
