<?php

namespace App\Domains\Classifieds\Support;

use App\Domains\Classifieds\Enums\ClassifiedAdStatus;
use App\Domains\Classifieds\Enums\PackageType;
use App\Domains\Classifieds\Models\ClassifiedAd;
use App\Domains\Classifieds\Models\ClassifiedPackage;
use App\Domains\Classifieds\Models\ClassifiedPackagePurchase;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Finance\Enums\LedgerAccountCode;
use App\Domains\Finance\Support\AccountRef;
use App\Domains\Finance\Support\Ledger;
use App\Domains\Finance\Support\Posting;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Payments\Enums\PaymentMethod;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Support\PaymentAttempts;
use App\Domains\Payments\Support\PaymentCaptured;
use App\Domains\Payments\Support\PaymentMethodCatalog;
use App\Models\User;
use App\Shared\Money\MoneyFactory;
use App\Shared\StateMachine\TransitionRefused;
use Brick\Math\RoundingMode;

/**
 * Featured / boost / republish packages for an ad (MP v2 §14b owner
 * upsells), paid through the payments domain like a gift card (card
 * gateways or Nawy Credits). Capture books Dr clearing / Cr ad revenue +
 * VAT and applies the package: featured → `featured_until`, boost →
 * `boosted_until` + bump, republish → bumped now and a new listing period
 * (an expired ad goes live again).
 */
class ClassifiedPackages
{
    public function __construct(
        private CountryContext $countryContext,
        private MoneyFactory $money,
        private Ledger $ledger,
        private ClassifiedAdMachine $ads,
    ) {}

    /**
     * @return array{0: ClassifiedPackagePurchase, 1: Payment}
     */
    public function buy(ClassifiedAd $ad, ClassifiedPackage $package, User $customer, string $methodCode): array
    {
        if (! $package->is_active) {
            throw new TransitionRefused(__('This package is not available.'));
        }

        $allowed = $package->type === PackageType::Republish ? [ClassifiedAdStatus::Active, ClassifiedAdStatus::Expired] : [ClassifiedAdStatus::Active];

        if (! in_array($ad->status, $allowed, true)) {
            throw new TransitionRefused(__('This package needs a live ad.'));
        }

        $country = $this->countryContext->current();
        $amount = $this->money->of((string) $package->price, $country->currency);
        $option = app(PaymentMethodCatalog::class)->find($country, $methodCode, $amount, $customer->id);

        if ($option === null || in_array($option['method'], [PaymentMethod::Cod, PaymentMethod::GiftCard], true)) {
            throw new TransitionRefused(__('This payment method is not available for packages.'));
        }

        $purchase = ClassifiedPackagePurchase::query()->create([
            'number' => 'CP-'.uniqid(),
            'classified_ad_id' => $ad->id,
            'classified_package_id' => $package->id,
            'customer_user_id' => $customer->id,
            'currency' => $country->currency,
            'amount' => $this->money->toStorage($amount),
            'status' => 'pending',
        ]);
        $purchase->forceFill(['number' => 'CP-'.$country->iso2.'-'.str_pad((string) $purchase->id, 7, '0', STR_PAD_LEFT)])->save();
        $payment = app(PaymentAttempts::class)->startForPayable(ClassifiedPackagePurchase::PAYABLE, $purchase->id, $purchase->number, $amount, $option['method'], $customer, $package->name_en.' · '.$ad->number);

        return [$purchase->refresh(), $payment];
    }

    /**
     * Called inside the payment capture ({@see PaymentCaptured}).
     */
    public function paid(Payment $payment): void
    {
        $purchase = ClassifiedPackagePurchase::query()->lockForUpdate()->find($payment->payable_id);

        if ($purchase === null || $purchase->status === 'paid') {
            return;
        }

        $amount = $this->money->of((string) $payment->captured_amount, $payment->currency);
        $rate = (string) $this->countryContext->current()->tax_rate;
        $vat = $amount->multipliedBy($rate, RoundingMode::HalfUp)->dividedBy(bcadd('100', $rate, 2), RoundingMode::HalfUp);
        $posting = (new Posting)->debit(app(PaymentCaptured::class)->clearingAccount($payment->method), $amount)
            ->credit(AccountRef::platform(LedgerAccountCode::AdRevenue), $amount->minus($vat));

        if ($vat->isPositive()) {
            $posting->credit(AccountRef::platform(LedgerAccountCode::VatPayable), $vat);
        }

        $this->ledger->post("classified-package:{$purchase->id}", 'classified_package', $posting, ActorType::Customer, $payment->customer_user_id, $purchase, "Package {$purchase->number}");
        $until = $this->apply($purchase);
        $purchase->forceFill(['status' => 'paid', 'paid_at' => now(), 'applied_until' => $until])->save();
    }

    private function apply(ClassifiedPackagePurchase $purchase): ?\DateTimeInterface
    {
        $ad = $purchase->ad()->firstOrFail();
        $package = $purchase->package;
        $extend = fn ($current) => ($current !== null && $current->isFuture() ? $current : now()->toImmutable())->addDays($package->duration_days);

        return match ($package->type) {
            PackageType::Featured => tap($extend($ad->featured_until), fn ($until) => $ad->forceFill(['featured_until' => $until])->save()),
            PackageType::Boost => tap($extend($ad->boosted_until), fn ($until) => $ad->forceFill(['boosted_until' => $until, 'bumped_at' => now()])->save()),
            PackageType::Republish => $this->ads->relist($ad, ActorType::Customer, $purchase->customer_user_id, 'Republished (package)')->expires_at,
        };
    }
}
