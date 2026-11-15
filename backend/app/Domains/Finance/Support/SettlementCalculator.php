<?php

namespace App\Domains\Finance\Support;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Coupons\Enums\CouponFunding;
use App\Domains\Coupons\Enums\CouponScope;
use App\Domains\Coupons\Enums\CouponType;
use App\Domains\Coupons\Models\CouponRedemption;
use App\Domains\Orders\Models\OrderItem;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Refunds\Enums\RefundStatus;
use App\Domains\Refunds\Models\Refund;
use App\Shared\Money\MoneyFactory;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Brick\Money\Money;

/**
 * Vendor net of one sub-order (SRS §9):
 *
 *   base = goods sold (VAT incl.) − seller-funded coupon − goods refunded
 *   net  = base − category commission − VAT on commission − fees − FBN fees
 *          − shipping subsidy share − campaign fees − sample commissions
 *
 * Commission = Σ line × snapshotted category rate, scaled to the base
 * (refunds reduce it pro rata). Nawy-funded coupons do not reduce the
 * vendor's base. Campaign fees, sample commissions and marketer
 * commissions are not per sub-order: the marketer module (WP5) books them
 * as their own lines on the store's next statement (`vendor_balance_items`
 * campaign_fee / sample_commission / marketer_commission / marketer_reversal),
 * so they stay 0 here and the payout net includes them through
 * {@see PayoutStatement}. Every amount is rounded half-up to the currency.
 */
class SettlementCalculator
{
    public function __construct(
        private MoneyFactory $money,
        private CommissionRates $commissionRates,
        private FinanceSettings $settings,
        private CountryContext $countryContext,
    ) {}

    /**
     * @return array{gross: Money, vendor_discount: Money, refunds: Money, refunds_total: Money, refunds_warranty: Money, base: Money, commission: Money, commission_vat: Money, fees: Money, fbn_fees: Money, subsidy_share: Money, campaign_fees: Money, sample_commissions: Money, net: Money, lines: array<int, array<string, mixed>>}
     */
    public function calculate(SubOrder $subOrder): array
    {
        $country = $this->countryContext->current();
        $currency = $country->currency;
        $zero = $this->money->zero($currency);
        $items = $subOrder->items()->with('listing.variant.product')->get();
        $fallbackRates = $this->commissionRates->forCategories($items->map(fn (OrderItem $item): int => (int) $item->listing->variant->product->category_id)->all());

        $gross = $this->money->of((string) $subOrder->subtotal, $currency);
        $vendorDiscount = Money::min($this->vendorFundedDiscount($subOrder, $currency), $gross);

        $completedRefunds = Refund::query()->where('sub_order_id', $subOrder->id)->where('status', RefundStatus::Completed)->where('after_settlement', false);
        $refundsTotal = $this->money->of((string) (clone $completedRefunds)->sum('amount'), $currency);
        $refundsWarranty = $this->money->of((string) (clone $completedRefunds)->sum('warranty_amount'), $currency);
        $goodsRefunded = $refundsTotal->minus($refundsWarranty);
        $base = Money::max($zero, $gross->minus($vendorDiscount)->minus($goodsRefunded));

        $rawCommission = BigRational::zero();
        $lines = [];

        foreach ($items as $item) {
            $rate = (string) ($item->commission_rate ?? $fallbackRates[(int) $item->listing->variant->product->category_id]);
            $rawCommission = $rawCommission->plus(BigRational::of((string) $item->line_total)->multipliedBy(BigRational::of($rate))->dividedBy(100));
            $lines[] = ['order_item_id' => $item->id, 'sku' => $item->sku, 'title' => $item->title, 'quantity' => $item->quantity, 'line_total' => (string) $this->money->of((string) $item->line_total, $currency)->getAmount(), 'commission_rate' => $rate];
        }

        $commission = $gross->isZero() ? $zero : Money::of(
            $rawCommission->multipliedBy(BigRational::of((string) $base->getAmount()))->dividedBy(BigRational::of((string) $gross->getAmount())),
            $currency,
            roundingMode: RoundingMode::HalfUp,
        );
        $commissionVat = $this->money->percentageOf($commission, (string) $country->tax_rate);
        $fees = $this->money->of((string) $this->settings->get('payout.fixed_fee_per_sub_order', 'fixed_fee_per_sub_order'), $currency);
        $fbnUnits = $items->filter(fn (OrderItem $item): bool => $item->listing->fulfilment === 'fbn')->sum('quantity');
        $fbnFees = $this->money->of((string) $this->settings->get('payout.fbn_fee_per_unit', 'fbn_fee_per_unit'), $currency)->multipliedBy($fbnUnits);
        $subsidyShare = $this->money->of((string) $subOrder->shipping_subsidy_vendor, $currency);
        $campaignFees = $zero;
        $sampleCommissions = $zero;

        $net = $base->minus($commission)->minus($commissionVat)->minus($fees)->minus($fbnFees)->minus($subsidyShare)->minus($campaignFees)->minus($sampleCommissions);

        return [
            'gross' => $gross,
            'vendor_discount' => $vendorDiscount,
            'refunds' => $goodsRefunded,
            'refunds_total' => $refundsTotal,
            'refunds_warranty' => $refundsWarranty,
            'base' => $base,
            'commission' => $commission,
            'commission_vat' => $commissionVat,
            'fees' => $fees,
            'fbn_fees' => $fbnFees,
            'subsidy_share' => $subsidyShare,
            'campaign_fees' => $campaignFees,
            'sample_commissions' => $sampleCommissions,
            'net' => $net,
            'lines' => $lines,
        ];
    }

    /**
     * The vendor-funded goods discount on this seller's items (QC-014): the
     * seller's own coupon, and the seller's share of any vendor-funded Nawy
     * coupon (`vendor_shares`). Free-shipping coupons are not a goods
     * discount. Redemptions from before shares were kept fall back to the
     * seller coupon's whole amount.
     */
    private function vendorFundedDiscount(SubOrder $subOrder, string $currency): Money
    {
        $total = $this->money->zero($currency);
        $redemptions = CouponRedemption::query()->where('order_id', $subOrder->order_id)->with('coupon')->get();

        foreach ($redemptions as $redemption) {
            $coupon = $redemption->coupon;

            if ($coupon === null || $coupon->type === CouponType::FreeShipping) {
                continue;
            }

            $vendorFunded = $redemption->funded_by !== null ? $redemption->funded_by === CouponFunding::Vendor->value : $coupon->scope === CouponScope::Vendor;

            if (! $vendorFunded) {
                continue;
            }

            $shares = $redemption->vendor_shares;
            $share = match (true) {
                is_array($shares) && $shares !== [] => $shares[$subOrder->vendor_id] ?? $shares[(string) $subOrder->vendor_id] ?? null,
                $coupon->scope === CouponScope::Vendor && (int) $coupon->vendor_id === (int) $subOrder->vendor_id => (string) $redemption->discount_amount,
                default => null,
            };

            if ($share !== null) {
                $total = $total->plus($this->money->of((string) $share, $currency));
            }
        }

        return Money::min($total, $this->money->of((string) $subOrder->discount_total, $currency));
    }
}
