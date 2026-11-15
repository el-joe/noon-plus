<?php

namespace App\Domains\Finance\Support;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Finance\Enums\LedgerAccountCode;
use App\Domains\Finance\Enums\WalletBucket;
use App\Domains\Finance\Models\CodCollection;
use App\Domains\Finance\Models\SubOrderSettlement;
use App\Domains\Finance\Models\VendorBalanceItem;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Payments\Enums\PaymentMethod;
use App\Shared\Money\MoneyFactory;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * Settles a sub-order when it completes (SRS §7.1: completion unlocks the
 * vendor payout). Idempotent per sub-order. The customer money held in the
 * order liability is split:
 *
 *   Dr order liability          (what is still held for this sub-order)
 *   Cr vendor payable           net (pending bucket while COD is not remitted)
 *   Cr commission revenue       commission
 *   Cr VAT payable              VAT on commission + VAT in shipping, warranty and COD fee
 *   Cr shipping / warranty / COD fee / fee revenue (net of VAT)
 *   Cr shipping subsidy expense the vendor's subsidy share (recovered)
 *   Dr promo expense            the balancing Nawy-funded discount
 */
class Settlements
{
    public function __construct(
        private SettlementCalculator $calculator,
        private Ledger $ledger,
        private MoneyFactory $money,
        private CountryContext $countryContext,
    ) {}

    public function settle(SubOrder $subOrder): SubOrderSettlement
    {
        return DB::connection($subOrder->getConnectionName())->transaction(function () use ($subOrder): SubOrderSettlement {
            $existing = SubOrderSettlement::query()->where('sub_order_id', $subOrder->id)->first();

            if ($existing !== null) {
                return $existing;
            }

            $country = $this->countryContext->current();
            $currency = $country->currency;
            $order = $subOrder->order()->firstOrFail();
            $isCod = $order->payment_method === PaymentMethod::Cod->value;
            $result = $this->calculator->calculate($subOrder);
            $amount = fn (Money $money): string => $this->money->toStorage($money);
            $codFee = $this->money->of((string) (CodCollection::query()->where('sub_order_id', $subOrder->id)->value('cod_fee') ?? '0'), $currency);
            $shipping = $this->money->of((string) $subOrder->shipping_total, $currency);
            $warranty = Money::max($this->money->zero($currency), $this->money->of((string) $subOrder->warranty_total, $currency)->minus($result['refunds_warranty']));

            $held = $this->money->of((string) $subOrder->total, $currency)
                ->plus($this->money->of((string) $subOrder->warranty_total, $currency))
                ->plus($codFee)
                ->minus($result['refunds_total']);

            $settlement = SubOrderSettlement::query()->create([
                'sub_order_id' => $subOrder->id,
                'vendor_id' => $subOrder->vendor_id,
                'currency' => $currency,
                'is_cod' => $isCod,
                'gross' => $amount($result['gross']),
                'vendor_discount' => $amount($result['vendor_discount']),
                'refunds' => $amount($result['refunds']),
                'commission' => $amount($result['commission']),
                'commission_vat' => $amount($result['commission_vat']),
                'fees' => $amount($result['fees']),
                'fbn_fees' => $amount($result['fbn_fees']),
                'subsidy_share' => $amount($result['subsidy_share']),
                'campaign_fees' => $amount($result['campaign_fees']),
                'sample_commissions' => $amount($result['sample_commissions']),
                'net' => $amount($result['net']),
                'lines' => $result['lines'],
                'settled_at' => now(),
            ]);

            $eligible = ! $isCod || $subOrder->cod_remittance_confirmed;
            [$shippingNet, $shippingVat] = $this->splitVat($shipping, (string) $country->tax_rate);
            [$warrantyNet, $warrantyVat] = $this->splitVat($warranty, (string) $country->tax_rate);
            [$codFeeNet, $codFeeVat] = $this->splitVat($codFee, (string) $country->tax_rate);

            $credits = [
                [AccountRef::vendor($subOrder->vendor_id, $eligible ? WalletBucket::Spendable : WalletBucket::Pending), $result['net']],
                [AccountRef::platform(LedgerAccountCode::CommissionRevenue), $result['commission']],
                [AccountRef::platform(LedgerAccountCode::VatPayable), $result['commission_vat']->plus($shippingVat)->plus($warrantyVat)->plus($codFeeVat)],
                [AccountRef::platform(LedgerAccountCode::ShippingRevenue), $shippingNet],
                [AccountRef::platform(LedgerAccountCode::WarrantyRevenue), $warrantyNet],
                [AccountRef::platform(LedgerAccountCode::CodFeeRevenue), $codFeeNet],
                [AccountRef::platform(LedgerAccountCode::FeeRevenue), $result['fees']->plus($result['fbn_fees'])],
                [AccountRef::platform(LedgerAccountCode::ShippingSubsidyExpense), $result['subsidy_share']],
            ];

            $posting = (new Posting)->debit(AccountRef::platform(LedgerAccountCode::OrderLiability), $held);
            $creditTotal = $this->money->zero($currency);

            foreach ($credits as [$account, $credit]) {
                $posting->credit($account, $credit);
                $creditTotal = $creditTotal->plus($credit);
            }

            $posting->debit(AccountRef::platform(LedgerAccountCode::PromoExpense), $creditTotal->minus($held));

            if (count($posting->legs()) >= 2) {
                $this->ledger->post("settlement:{$subOrder->id}", 'sub_order_settlement', $posting, reference: $settlement, description: "Settlement {$subOrder->number}");
            }

            VendorBalanceItem::query()->create([
                'vendor_id' => $subOrder->vendor_id,
                'type' => VendorBalanceItem::TYPE_SETTLEMENT,
                'reference_type' => 'sub_order_settlement',
                'reference_id' => $settlement->id,
                'description' => "Sub-order {$subOrder->number}",
                'amount' => $amount($result['net']),
                'is_eligible' => $eligible,
            ]);

            return $settlement;
        });
    }

    /**
     * Split a VAT-inclusive amount into net and VAT.
     *
     * @return array{0: Money, 1: Money}
     */
    private function splitVat(Money $gross, string $rate): array
    {
        $vat = $gross->multipliedBy($rate, RoundingMode::HalfUp)->dividedBy(bcadd('100', $rate, 2), RoundingMode::HalfUp);

        return [$gross->minus($vat), $vat];
    }
}
