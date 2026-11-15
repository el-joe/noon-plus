<?php

namespace App\Domains\Finance\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One amount on a vendor's next statement: a sub-order settlement (eligible
 * once COD is remitted), a refund clawback after settlement (negative), or a
 * marketer item (WP5): campaign fee, sample commission, marketer commission
 * and its reversal.
 * Picked up once by a payout.
 */
#[Fillable(['vendor_id', 'type', 'reference_type', 'reference_id', 'description', 'amount', 'is_eligible', 'vendor_payout_id'])]
class VendorBalanceItem extends Model
{
    use UsesCountryDatabase;

    public const TYPE_SETTLEMENT = 'settlement';

    public const TYPE_CLAWBACK = 'clawback';

    /** Marketer campaign fee charged at go-live (negative) — WP5. */
    public const TYPE_CAMPAIGN_FEE = 'campaign_fee';

    /** Campaign fee refunded under `campaign_fee_refund_policy` (positive). */
    public const TYPE_CAMPAIGN_FEE_REFUND = 'campaign_fee_refund';

    /** Platform commission on a shipped promotion sample (negative). */
    public const TYPE_SAMPLE_COMMISSION = 'sample_commission';

    /** Marketer commission paid by the store (negative), incl. influencer fixed fees. */
    public const TYPE_MARKETER_COMMISSION = 'marketer_commission';

    /** Marketer commission given back after a cancel/return (positive). */
    public const TYPE_MARKETER_REVERSAL = 'marketer_reversal';

    /** Ad spend of a self-serve campaign for one day (negative) — WP6. */
    public const TYPE_AD_SPEND = 'ad_spend';

    /** Paid ad slot booking (negative) and its refund (positive) — WP6. */
    public const TYPE_AD_BOOKING = 'ad_booking';

    public const TYPE_AD_BOOKING_REFUND = 'ad_booking_refund';

    /** Coupon participation fee (negative) and its refund (positive) — WP6. */
    public const TYPE_COUPON_PARTICIPATION = 'coupon_participation';

    public const TYPE_COUPON_PARTICIPATION_REFUND = 'coupon_participation_refund';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_eligible' => 'boolean'];
    }

    /**
     * @return BelongsTo<VendorPayout, $this>
     */
    public function payout(): BelongsTo
    {
        return $this->belongsTo(VendorPayout::class, 'vendor_payout_id');
    }
}
