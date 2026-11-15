<?php

namespace App\Domains\Finance\Models;

use App\Domains\Finance\Enums\PromotionalCreditStatus;
use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One grant of promotional Nawy Credits with its own expiry (MASTER_PLAN
 * §10). The sum of `remaining` of live grants equals the customer's
 * promotional wallet bucket.
 */
#[Fillable(['customer_user_id', 'currency', 'amount', 'remaining', 'status', 'source', 'reference', 'expires_at'])]
class PromotionalCredit extends Model
{
    use UsesCountryDatabase;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => PromotionalCreditStatus::class, 'expires_at' => 'immutable_datetime'];
    }
}
