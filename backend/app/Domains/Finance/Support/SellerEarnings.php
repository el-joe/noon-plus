<?php

namespace App\Domains\Finance\Support;

use App\Domains\Countries\Models\Country;
use App\Domains\Finance\Enums\PayoutStatus;
use App\Domains\Finance\Enums\WalletBucket;
use App\Domains\Finance\Models\SubOrderSettlement;
use App\Domains\Finance\Models\VendorBalanceItem;
use App\Domains\Finance\Models\VendorPayout;
use App\Domains\Vendors\Models\Vendor;
use App\Shared\Money\MoneyFactory;
use Brick\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Seller Center → Earnings: the store's wallet buckets from the ledger
 * (pending = settled but COD not remitted, spendable = next statement,
 * reserved = on a statement being paid) plus everything paid out, and the
 * period's sales from the sub-order settlements (settled in the period, in
 * country time) and the balance items raised in it.
 *
 * Per period and per month: net = gross − seller coupons − refunds −
 * commission (with VAT) − fees − marketing & ads, where refunds include
 * clawbacks after settlement, fees are order, FBN (fulfilment, storage,
 * removal) and subsidy-share fees,
 * and marketing is marketer campaign fees, sample and marketer commissions,
 * ad spend, ad bookings and coupon participation (net of their refunds).
 */
class SellerEarnings
{
    /** @var array<int, string> */
    public const MARKETING_TYPES = [
        VendorBalanceItem::TYPE_CAMPAIGN_FEE, VendorBalanceItem::TYPE_CAMPAIGN_FEE_REFUND, VendorBalanceItem::TYPE_SAMPLE_COMMISSION,
        VendorBalanceItem::TYPE_MARKETER_COMMISSION, VendorBalanceItem::TYPE_MARKETER_REVERSAL, VendorBalanceItem::TYPE_AD_SPEND,
        VendorBalanceItem::TYPE_AD_BOOKING, VendorBalanceItem::TYPE_AD_BOOKING_REFUND, VendorBalanceItem::TYPE_COUPON_PARTICIPATION,
        VendorBalanceItem::TYPE_COUPON_PARTICIPATION_REFUND,
    ];

    /** @var array<int, string> */
    public const COLUMNS = ['gross', 'coupons', 'refunds', 'commission', 'fees', 'marketing', 'net'];

    public function __construct(private Ledger $ledger, private MoneyFactory $money) {}

    /**
     * @return array{pending: Money, spendable: Money, reserved: Money, paid_out: Money, paid_out_count: int}
     */
    public function balances(Vendor $vendor, string $currency): array
    {
        $paid = VendorPayout::query()->where('vendor_id', $vendor->id)->where('status', PayoutStatus::Completed)->where('currency', $currency);

        return [
            'pending' => $this->ledger->balance(AccountRef::vendor($vendor->id, WalletBucket::Pending), $currency),
            'spendable' => $this->ledger->balance(AccountRef::vendor($vendor->id, WalletBucket::Spendable), $currency),
            'reserved' => $this->ledger->balance(AccountRef::vendor($vendor->id, WalletBucket::Reserved), $currency),
            'paid_out' => $this->money->of((string) (clone $paid)->sum('total'), $currency),
            'paid_out_count' => (clone $paid)->count(),
        ];
    }

    /**
     * Totals for the period and one row per country-time month in it (newest first).
     *
     * @return array{totals: array<string, Money|int>, months: array<int, array<string, Money|int|string>>}
     */
    public function period(Vendor $vendor, Country $country, CarbonImmutable $fromDay, CarbonImmutable $toDay): array
    {
        $currency = $country->currency;
        $start = CarbonImmutable::parse($fromDay->toDateString().' 00:00:00', $country->timezone)->utc();
        $end = CarbonImmutable::parse($toDay->toDateString().' 23:59:59', $country->timezone)->utc();
        $month = fn ($moment): string => CarbonImmutable::instance($moment)->setTimezone($country->timezone)->format('Y-m');

        $months = [];

        for ($cursor = $fromDay->startOfMonth(); $cursor->lessThanOrEqualTo($toDay); $cursor = $cursor->addMonth()) {
            $months[$cursor->format('Y-m')] = $this->emptyRow($currency) + ['month' => $cursor->format('Y-m'), 'label' => $cursor->translatedFormat('F Y')];
        }

        $settlements = SubOrderSettlement::query()->where('vendor_id', $vendor->id)->whereBetween('settled_at', [$start, $end])
            ->get(['gross', 'vendor_discount', 'refunds', 'commission', 'commission_vat', 'fees', 'fbn_fees', 'subsidy_share', 'campaign_fees', 'sample_commissions', 'settled_at']);

        foreach ($settlements as $settlement) {
            $key = $month($settlement->settled_at);
            $of = fn (string ...$columns): Money => array_reduce($columns, fn (Money $sum, string $column): Money => $sum->plus($this->money->of((string) $settlement->{$column}, $currency)), $this->money->zero($currency));
            $months[$key]['orders']++;
            $months[$key]['gross'] = $months[$key]['gross']->plus($of('gross'));
            $months[$key]['coupons'] = $months[$key]['coupons']->plus($of('vendor_discount'));
            $months[$key]['refunds'] = $months[$key]['refunds']->plus($of('refunds'));
            $months[$key]['commission'] = $months[$key]['commission']->plus($of('commission', 'commission_vat'));
            $months[$key]['fees'] = $months[$key]['fees']->plus($of('fees', 'fbn_fees', 'subsidy_share'));
            $months[$key]['marketing'] = $months[$key]['marketing']->plus($of('campaign_fees', 'sample_commissions'));
        }

        $items = VendorBalanceItem::query()->where('vendor_id', $vendor->id)->whereBetween('created_at', [$start, $end])
            ->whereIn('type', [VendorBalanceItem::TYPE_CLAWBACK, ...self::MARKETING_TYPES, ...VendorBalanceItem::FBN_TYPES])->get(['type', 'amount', 'created_at']);

        foreach ($items as $item) {
            $key = $month($item->created_at);
            // Balance items are signed for the seller (charges negative); the columns hold deductions.
            $deduction = $this->money->of((string) $item->amount, $currency)->negated();
            $column = match (true) {
                $item->type === VendorBalanceItem::TYPE_CLAWBACK => 'refunds',
                in_array($item->type, VendorBalanceItem::FBN_TYPES, true) => 'fees',
                default => 'marketing',
            };
            $months[$key][$column] = $months[$key][$column]->plus($deduction);
        }

        $totals = $this->emptyRow($currency);

        foreach ($months as $key => $row) {
            $row['net'] = $row['gross']->minus($row['coupons'])->minus($row['refunds'])->minus($row['commission'])->minus($row['fees'])->minus($row['marketing']);
            $months[$key] = $row;
            $totals['orders'] += $row['orders'];

            foreach (self::COLUMNS as $column) {
                $totals[$column] = $totals[$column]->plus($row[$column]);
            }
        }

        krsort($months);

        return ['totals' => $totals, 'months' => array_values($months)];
    }

    /**
     * @return array<string, Money|int>
     */
    private function emptyRow(string $currency): array
    {
        $zero = $this->money->zero($currency);

        return ['orders' => 0] + array_fill_keys(self::COLUMNS, $zero);
    }
}
