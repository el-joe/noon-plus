<?php

namespace App\Domains\Payments\Support;

use App\Domains\Advertising\Models\TravelPromotion;
use App\Domains\Advertising\Support\TravelPromotions;
use App\Domains\Classifieds\Models\ClassifiedPackagePurchase;
use App\Domains\Classifieds\Support\ClassifiedPackages;
use App\Domains\Finance\Enums\LedgerAccountCode;
use App\Domains\Finance\Models\VendorSubscriptionInvoice;
use App\Domains\Finance\Support\AccountRef;
use App\Domains\Finance\Support\Ledger;
use App\Domains\Finance\Support\Posting;
use App\Domains\Finance\Support\VendorSubscriptions;
use App\Domains\GiftCards\Models\GiftCard;
use App\Domains\GiftCards\Support\GiftCards;
use App\Domains\Membership\Models\MembershipInvoice;
use App\Domains\Membership\Support\Memberships;
use App\Domains\Payments\Enums\PaymentMethod;
use App\Domains\Payments\Models\Payment;
use App\Shared\Money\MoneyFactory;

/**
 * What happens when a payment is captured (inside the payment transition):
 * an order payment moves the money from the provider clearing account into
 * the order liability until the sub-orders settle (COD is booked per
 * delivered sub-order instead); a gift card, membership, classified package
 * or travel promotion (D14) payment is handed to its module.
 */
class PaymentCaptured
{
    public function __construct(private Ledger $ledger, private MoneyFactory $money) {}

    public function handle(Payment $payment): void
    {
        $amount = $this->money->of((string) $payment->captured_amount, $payment->currency);

        if ($payment->order_id !== null) {
            if ($payment->method === PaymentMethod::Cod || ! $amount->isPositive()) {
                return;
            }

            $this->ledger->post("payment:{$payment->id}:capture", 'order_payment', (new Posting)
                ->debit($this->clearingAccount($payment->method), $amount)
                ->credit(AccountRef::platform(LedgerAccountCode::OrderLiability), $amount), reference: $payment, description: 'Order payment captured');

            return;
        }

        match ($payment->payable_type) {
            GiftCard::PAYABLE => app(GiftCards::class)->paid($payment),
            MembershipInvoice::PAYABLE => app(Memberships::class)->invoicePaid($payment),
            ClassifiedPackagePurchase::PAYABLE => app(ClassifiedPackages::class)->paid($payment),
            TravelPromotion::PAYABLE => app(TravelPromotions::class)->paid($payment),
            VendorSubscriptionInvoice::PAYABLE => app(VendorSubscriptions::class)->invoicePaid($payment),
            default => null,
        };
    }

    public function clearingAccount(PaymentMethod $method): AccountRef
    {
        return $method === PaymentMethod::Wallet ? AccountRef::platform(LedgerAccountCode::WalletClearing) : AccountRef::gateway($method);
    }
}
