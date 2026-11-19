<?php

namespace App\Domains\Advertising\Models;

use App\Domains\Advertising\Enums\TravelPromotionStatus;
use App\Domains\Advertising\Support\TravelPromotions;
use App\Domains\Payments\Models\Payment;
use App\Domains\Travel\Models\TravelPackage;
use App\Domains\Vendors\Models\Vendor;
use App\Shared\Database\UsesCountryDatabase;
use App\Shared\StateMachine\HasStateTransitions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One agency's paid promotion of one of its travel packages (D14), paid
 * through the payments domain (`payments.payable_type` = {@see PAYABLE}).
 * Status changes only through {@see TravelPromotions}.
 */
#[Fillable(['number', 'vendor_id', 'travel_package_id', 'travel_promotion_product_id', 'ad_slot_id', 'duration_days', 'currency', 'amount', 'bought_by_user_id'])]
class TravelPromotion extends Model
{
    use HasStateTransitions, UsesCountryDatabase;

    /** `payments.payable_type` of a travel promotion. */
    public const PAYABLE = 'travel_promotion';

    protected $table = 'travel_promotions';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TravelPromotionStatus::class,
            'amount' => 'decimal:4',
            'vat_amount' => 'decimal:4',
            'duration_days' => 'integer',
            'impressions' => 'integer',
            'paid_at' => 'immutable_datetime',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<TravelPackage, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(TravelPackage::class, 'travel_package_id');
    }

    /**
     * @return BelongsTo<TravelPromotionProduct, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(TravelPromotionProduct::class, 'travel_promotion_product_id');
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'payable_id')->where('payable_type', self::PAYABLE)->orderBy('id');
    }
}
