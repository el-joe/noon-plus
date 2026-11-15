<?php

namespace App\Domains\Finance\Models;

use App\Domains\Orders\Models\SubOrder;
use App\Domains\Vendors\Models\Vendor;
use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The vendor settlement of one completed sub-order (SRS §9 net formula),
 * with the commission rates and amounts snapshotted in `lines`.
 */
#[Fillable([
    'sub_order_id', 'vendor_id', 'currency', 'is_cod', 'gross', 'vendor_discount', 'refunds', 'commission', 'commission_vat',
    'fees', 'fbn_fees', 'subsidy_share', 'campaign_fees', 'sample_commissions', 'net', 'lines', 'settled_at',
])]
class SubOrderSettlement extends Model
{
    use UsesCountryDatabase;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_cod' => 'boolean', 'lines' => 'array', 'settled_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<SubOrder, $this>
     */
    public function subOrder(): BelongsTo
    {
        return $this->belongsTo(SubOrder::class);
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
