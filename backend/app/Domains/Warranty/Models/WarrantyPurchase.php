<?php

namespace App\Domains\Warranty\Models;

use App\Domains\Warranty\Enums\WarrantyPurchaseStatus;
use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An extended warranty bought with an order item (SRS §7.7).
 */
#[Fillable([
    'order_id', 'order_item_id', 'warranty_plan_id', 'customer_user_id', 'status',
    'quantity', 'unit_price', 'total', 'duration_months', 'starts_at', 'expires_at',
])]
class WarrantyPurchase extends Model
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
            'status' => WarrantyPurchaseStatus::class,
            'unit_price' => 'decimal:4',
            'total' => 'decimal:4',
            'starts_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<WarrantyPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(WarrantyPlan::class, 'warranty_plan_id');
    }
}
