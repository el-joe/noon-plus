<?php

namespace App\Domains\Finance\Support;

use App\Domains\Finance\Models\SubOrderSettlement;
use App\Domains\Finance\Models\VendorBalanceItem;
use App\Domains\Finance\Models\VendorPayout;
use App\Shared\Money\MoneyFactory;
use Brick\Money\Money;
use Illuminate\Support\Collection;

/**
 * The statement lines of a payout (seller statement 100:17344, admin batch
 * 98:4242): gross sales, seller coupons, refunds, Nawy fees (commission,
 * VAT on it, fixed and FBN fees), FBN storage and removal fees, subsidy share, campaign fees, sample
 * commissions, marketer commissions (net of reversals), clawbacks and the
 * net — from the settlements and the WP5 balance items on it.
 */
class PayoutStatement
{
    public function __construct(private MoneyFactory $money) {}

    /**
     * @return array{lines: array<int, array{label: string, basis: string, amount: Money}>, gross: Money, fees: Money, adjustments: Money, net: Money, settlements: Collection<int, SubOrderSettlement>, orders: int}
     */
    public function summarize(VendorPayout $payout): array
    {
        $currency = $payout->currency;
        $items = $payout->items()->get();
        $settlements = SubOrderSettlement::query()
            ->whereIn('id', $items->where('type', VendorBalanceItem::TYPE_SETTLEMENT)->pluck('reference_id'))
            ->with('subOrder:id,number')
            ->orderBy('id')
            ->get();
        $sum = fn (string $column): Money => $this->money->of((string) $settlements->sum($column), $currency);
        $clawbacks = $this->money->of((string) $items->where('type', VendorBalanceItem::TYPE_CLAWBACK)->sum('amount'), $currency);
        $itemSum = fn (string ...$types): Money => $this->money->of((string) $items->whereIn('type', $types)->sum('amount'), $currency);
        $campaignFees = $sum('campaign_fees')->negated()->plus($itemSum(VendorBalanceItem::TYPE_CAMPAIGN_FEE, VendorBalanceItem::TYPE_CAMPAIGN_FEE_REFUND));
        $sampleCommissions = $sum('sample_commissions')->negated()->plus($itemSum(VendorBalanceItem::TYPE_SAMPLE_COMMISSION));
        $marketerCommissions = $itemSum(VendorBalanceItem::TYPE_MARKETER_COMMISSION, VendorBalanceItem::TYPE_MARKETER_REVERSAL);
        $fbnStorage = $itemSum(VendorBalanceItem::TYPE_FBN_STORAGE);
        $fbnRemoval = $itemSum(VendorBalanceItem::TYPE_FBN_REMOVAL);
        $subscription = $itemSum(VendorBalanceItem::TYPE_SUBSCRIPTION);
        $advertising = $itemSum(VendorBalanceItem::TYPE_AD_SPEND, VendorBalanceItem::TYPE_AD_BOOKING, VendorBalanceItem::TYPE_AD_BOOKING_REFUND, VendorBalanceItem::TYPE_COUPON_PARTICIPATION, VendorBalanceItem::TYPE_COUPON_PARTICIPATION_REFUND);

        $gross = $sum('gross');
        $fees = $sum('commission')->plus($sum('commission_vat'))->plus($sum('fees'))->plus($sum('fbn_fees'));
        $adjustments = $sum('vendor_discount')->plus($sum('refunds'))->plus($sum('subsidy_share'))->negated()
            ->plus($campaignFees)->plus($sampleCommissions)->plus($marketerCommissions)->plus($advertising)->plus($clawbacks)
            ->plus($fbnStorage)->plus($fbnRemoval)->plus($subscription);

        $lines = [
            ['label' => __('Product sales'), 'basis' => __('Gross'), 'amount' => $gross],
            ['label' => __('Seller coupons'), 'basis' => __('Coupon'), 'amount' => $sum('vendor_discount')->negated()],
            ['label' => __('Customer returns'), 'basis' => __('Refund'), 'amount' => $sum('refunds')->negated()],
            ['label' => __('Referral fee'), 'basis' => __('Per category'), 'amount' => $sum('commission')->negated()],
            ['label' => __('VAT on Nawy fees'), 'basis' => __('VAT'), 'amount' => $sum('commission_vat')->negated()],
            ['label' => __('Order fees'), 'basis' => __('Per order'), 'amount' => $sum('fees')->negated()],
            ['label' => __('FBN fulfilment fee'), 'basis' => __('Per unit'), 'amount' => $sum('fbn_fees')->negated()],
            ['label' => __('FBN storage fees'), 'basis' => __('Monthly storage'), 'amount' => $fbnStorage],
            ['label' => __('FBN removal fees'), 'basis' => __('Removal orders'), 'amount' => $fbnRemoval],
            ['label' => __('Delivery subsidy share'), 'basis' => __('Exceptional zones'), 'amount' => $sum('subsidy_share')->negated()],
            ['label' => __('Marketer campaign fees'), 'basis' => __('Fee quote'), 'amount' => $campaignFees],
            ['label' => __('Sample commissions'), 'basis' => __('Samples'), 'amount' => $sampleCommissions],
            ['label' => __('Marketer commissions'), 'basis' => __('Campaigns'), 'amount' => $marketerCommissions],
            ['label' => __('Advertising & coupon participation'), 'basis' => __('Ads'), 'amount' => $advertising],
            ['label' => __('Subscription'), 'basis' => __('Plan invoices'), 'amount' => $subscription],
            ['label' => __('Refunds after settlement'), 'basis' => __('Clawback'), 'amount' => $clawbacks],
        ];

        return [
            'lines' => array_values(array_filter($lines, fn (array $line): bool => ! $line['amount']->isZero() || $line['basis'] === __('Gross'))),
            'gross' => $gross,
            'fees' => $fees,
            'adjustments' => $adjustments,
            'net' => $this->money->of((string) $payout->total, $currency),
            'settlements' => $settlements,
            'orders' => $settlements->count(),
        ];
    }
}
