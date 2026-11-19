<?php

namespace App\Domains\Delivery\Models;

use App\Domains\Delivery\Enums\AgentWithdrawalStatus;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A delivery agent's wallet withdrawal to a bank account. The bank details
 * are stored encrypted; screens show the IBAN's last four characters only.
 *
 * @property AgentWithdrawalStatus $status
 * @property array{account_holder: string, bank_name: string, iban: string} $payout_details
 */
#[Fillable(['number', 'delivery_agent_id', 'carrier_id', 'currency', 'amount', 'status', 'payout_details', 'reason', 'requested_by_user_id', 'approved_by_user_id', 'approved_at', 'paid_by_user_id', 'paid_at', 'reference'])]
class DeliveryWithdrawal extends Model
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
            'status' => AgentWithdrawalStatus::class,
            'payout_details' => 'encrypted:array',
            'approved_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
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
     * Bank details for screens: holder, bank and the IBAN's last four.
     *
     * @return array{account_holder: string, bank_name: string, iban_last4: string}
     */
    public function maskedPayout(): array
    {
        $details = $this->payout_details ?? [];

        return [
            'account_holder' => (string) ($details['account_holder'] ?? ''),
            'bank_name' => (string) ($details['bank_name'] ?? ''),
            'iban_last4' => substr((string) ($details['iban'] ?? ''), -4),
        ];
    }
}
