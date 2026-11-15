<?php

namespace App\Domains\Warranty\Models;

use App\Domains\Orders\Models\OrderItem;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Warranty\Enums\WarrantyClaimStatus;
use App\Domains\Warranty\Enums\WarrantyCoverage;
use App\Domains\Warranty\Enums\WarrantyOutcome;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A warranty claim on one order item with a three-party thread (customer,
 * seller, admin) — SRS §7.7.
 */
#[Fillable([
    'number', 'order_item_id', 'sub_order_id', 'vendor_id', 'warranty_purchase_id', 'customer_user_id', 'coverage', 'status',
    'description', 'outcome', 'rejection_reason', 'refund_id',
])]
class WarrantyClaim extends Model
{
    use HasStateTransitions, UsesCountryDatabase;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => WarrantyClaimStatus::class, 'coverage' => WarrantyCoverage::class, 'outcome' => WarrantyOutcome::class];
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /**
     * @return BelongsTo<SubOrder, $this>
     */
    public function subOrder(): BelongsTo
    {
        return $this->belongsTo(SubOrder::class);
    }

    /**
     * @return HasMany<WarrantyClaimMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(WarrantyClaimMessage::class)->orderBy('id');
    }
}
