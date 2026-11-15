<?php

namespace App\Domains\Returns\Models;

use App\Domains\Orders\Models\OrderItem;
use App\Domains\Returns\Enums\InspectionOutcome;
use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Units of one order item in a return, with what the customer paid for them
 * (after the order's discounts), the warranty plan share, the inspection
 * outcome, the restock decision and the refund.
 */
#[Fillable(['return_request_id', 'order_item_id', 'quantity', 'paid_amount', 'warranty_amount', 'outcome', 'restock', 'refund_amount'])]
class ReturnItem extends Model
{
    use UsesCountryDatabase;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['outcome' => InspectionOutcome::class, 'restock' => 'boolean'];
    }

    /**
     * @return BelongsTo<ReturnRequest, $this>
     */
    public function returnRequest(): BelongsTo
    {
        return $this->belongsTo(ReturnRequest::class);
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
