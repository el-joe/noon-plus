<?php

namespace App\Domains\GiftCards\Models;

use App\Domains\GiftCards\Enums\VoucherStatus;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * An admin-issued voucher code worth promotional Nawy Credits (proposed
 * reading of Spec §6.1 — owner review): each customer redeems it once,
 * up to `max_redemptions`; the credits expire after `credit_expiry_days`.
 */
#[Fillable(['code_hash', 'label', 'status', 'currency', 'value', 'max_redemptions', 'redemptions_count', 'credit_expiry_days', 'expires_at', 'created_by_user_id'])]
#[Hidden(['code_hash'])]
class Voucher extends Model
{
    use HasStateTransitions, UsesCountryDatabase;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => VoucherStatus::class, 'expires_at' => 'immutable_datetime'];
    }
}
