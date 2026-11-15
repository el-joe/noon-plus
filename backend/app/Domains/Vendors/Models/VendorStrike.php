<?php

namespace App\Domains\Vendors\Models;

use App\Domains\Vendors\Support\SellerScore;
use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A seller-score strike (SRS §4.3 Performance, §7.1): e.g. the seller
 * cancelled a sub-order. Append-only; the score is derived from recent rows
 * by {@see SellerScore}.
 */
#[Fillable(['vendor_id', 'sub_order_id', 'reason', 'note', 'points', 'actor_user_id'])]
class VendorStrike extends Model
{
    use UsesCountryDatabase;

    public const UPDATED_AT = null;

    public const REASON_SELLER_CANCELLATION = 'seller_cancellation';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Seller strikes are append-only.'));
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
