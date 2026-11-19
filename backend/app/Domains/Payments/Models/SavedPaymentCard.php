<?php

namespace App\Domains\Payments\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * A card the customer chose to keep at a gateway (G-A6): only the
 * gateway's token (encrypted) and display details — never card data.
 */
#[Fillable(['customer_user_id', 'gateway', 'gateway_token', 'token_hash', 'gateway_customer_ref', 'brand', 'last4', 'exp_month', 'exp_year', 'is_default', 'last_used_at'])]
#[Hidden(['gateway_token', 'token_hash', 'gateway_customer_ref'])]
class SavedPaymentCard extends Model
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
            'gateway_token' => 'encrypted',
            'is_default' => 'boolean',
            'last_used_at' => 'immutable_datetime',
        ];
    }

    public function isExpired(): bool
    {
        if ($this->exp_year === null || $this->exp_month === null) {
            return false;
        }

        return now()->startOfMonth()->greaterThan(now()->setDate((int) $this->exp_year, (int) $this->exp_month, 1)->startOfMonth());
    }
}
