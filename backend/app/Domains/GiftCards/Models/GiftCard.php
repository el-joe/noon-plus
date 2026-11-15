<?php

namespace App\Domains\GiftCards\Models;

use App\Domains\GiftCards\Enums\GiftCardStatus;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * A Nawy gift card (Spec §6.2, SRS §10). The code is stored only as a keyed
 * hash (lookup) and encrypted (for the scheduled email); it is never
 * logged, serialised or shown again — screens show `masked_code`.
 */
#[Fillable([
    'number', 'code_hash', 'code_encrypted', 'code_last4', 'status', 'source', 'currency', 'amount', 'balance', 'purchaser_user_id',
    'recipient_name', 'recipient_email', 'message', 'send_at', 'delivered_at', 'redeemed_by_user_id', 'redeemed_at', 'expires_at',
])]
#[Hidden(['code_hash', 'code_encrypted'])]
class GiftCard extends Model
{
    use HasStateTransitions, UsesCountryDatabase;

    /**
     * `payments.payable_type` of a gift card purchase.
     */
    public const PAYABLE = 'gift_card';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => GiftCardStatus::class,
            'code_encrypted' => 'encrypted',
            'send_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'redeemed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    public function maskedCode(): string
    {
        return '••••-••••-••••-'.$this->code_last4;
    }
}
