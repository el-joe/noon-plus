<?php

namespace App\Domains\Advertising\Support;

use App\Domains\Advertising\Enums\SubscriptionStatus;
use App\Domains\Advertising\Models\AdPackage;
use App\Domains\Advertising\Models\AdPackageSubscription;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Finance\Enums\LedgerAccountCode;
use App\Domains\Finance\Enums\WalletBucket;
use App\Domains\Finance\Support\AccountRef;
use App\Domains\Finance\Support\Ledger;
use App\Domains\Finance\Support\Posting;
use App\Domains\Orders\Enums\ActorType;
use App\Shared\Money\MoneyFactory;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\TransitionRefused;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Ad packages (SRS §11.13, lifecycle catalogue §4.9). A marketer pays from
 * the spendable wallet (active at once: Dr wallet / Cr ad revenue + VAT,
 * the price includes VAT) or by bank transfer with a proof (pending until
 * an admin approves: Dr bank clearing / Cr ad revenue + VAT, or rejects).
 * One live subscription per package; `ad-packages:expire` runs hourly.
 */
class AdPackageSubscriptions
{
    public const PROOF_DISK = 'local';

    public function __construct(
        private StateMachine $machine,
        private CountryContext $countryContext,
        private MoneyFactory $money,
        private Ledger $ledger,
    ) {}

    public function subscribe(AdPackage $package, int $marketerProfileId, string $method, ?UploadedFile $proof, int $userId): AdPackageSubscription
    {
        if (! $package->is_active) {
            throw new TransitionRefused(__('This package is not available.'));
        }

        if (! in_array($method, ['wallet', 'bank_transfer'], true) || ($method === 'bank_transfer' && $proof === null)) {
            throw new TransitionRefused(__('Pay from your wallet or upload the bank transfer proof.'));
        }

        $live = AdPackageSubscription::query()->where(['ad_package_id' => $package->id, 'subscriber_type' => 'marketer', 'subscriber_id' => $marketerProfileId])
            ->whereIn('status', [SubscriptionStatus::Pending, SubscriptionStatus::Active])->exists();

        if ($live) {
            throw new TransitionRefused(__('You already have this package.'));
        }

        $amount = $this->money->of((string) $package->price, $this->countryContext->current()->currency);

        return DB::connection($package->getConnectionName())->transaction(function () use ($package, $marketerProfileId, $method, $proof, $userId, $amount): AdPackageSubscription {
            $subscription = new AdPackageSubscription([
                'ad_package_id' => $package->id,
                'subscriber_type' => 'marketer',
                'subscriber_id' => $marketerProfileId,
                'payment_method' => $method,
                'proof_path' => $proof?->store('ad-packages/proofs', self::PROOF_DISK),
                'currency' => $amount->getCurrency()->getCurrencyCode(),
                'amount' => $this->money->toStorage($amount),
                'vat_amount' => $this->money->toStorage($this->vat($amount)),
            ]);
            $subscription->forceFill(['status' => SubscriptionStatus::Pending])->save();
            $this->machine->created($subscription, ActorType::Marketer, $userId);

            if ($method === 'wallet') {
                $wallet = AccountRef::marketer($marketerProfileId, WalletBucket::Spendable);

                if ($this->ledger->balance($wallet, $amount->getCurrency()->getCurrencyCode())->isLessThan($amount)) {
                    throw new TransitionRefused(__('Your wallet does not cover :amount.', ['amount' => (string) $amount->getAmount()]));
                }

                $this->activate($subscription, $wallet, ActorType::Marketer, $userId);
            }

            return $subscription->refresh();
        });
    }

    public function approve(AdPackageSubscription $subscription, int $adminUserId): AdPackageSubscription
    {
        if ($subscription->status !== SubscriptionStatus::Pending) {
            throw new TransitionRefused(__('This subscription was already handled.'));
        }

        return $this->activate($subscription, AccountRef::platform(LedgerAccountCode::BankClearing), ActorType::Admin, $adminUserId);
    }

    public function reject(AdPackageSubscription $subscription, int $adminUserId, string $reason): AdPackageSubscription
    {
        return $this->machine->move($subscription, SubscriptionStatus::Rejected, ActorType::Admin, $adminUserId, $reason, ['rejection_reason' => mb_substr($reason, 0, 255)]);
    }

    public function expireDue(): int
    {
        $count = 0;

        AdPackageSubscription::query()->where('status', SubscriptionStatus::Active)->where('expires_at', '<=', now())->each(function (AdPackageSubscription $subscription) use (&$count): void {
            $this->machine->move($subscription, SubscriptionStatus::Expired, ActorType::System);
            $count++;
        });

        return $count;
    }

    /**
     * Features a marketer holds through active packages (e.g. featured_profile, extra_listings).
     *
     * @return array<int, string>
     */
    public function features(int $marketerProfileId): array
    {
        return AdPackageSubscription::query()->where(['subscriber_type' => 'marketer', 'subscriber_id' => $marketerProfileId, 'status' => SubscriptionStatus::Active])
            ->with('package')->get()->flatMap(fn (AdPackageSubscription $subscription): array => (array) ($subscription->package->features ?? []))->unique()->values()->all();
    }

    private function activate(AdPackageSubscription $subscription, AccountRef $debit, ActorType $actor, int $userId): AdPackageSubscription
    {
        $amount = $this->money->of((string) $subscription->amount, $subscription->currency);
        $vat = $this->money->of((string) $subscription->vat_amount, $subscription->currency);
        $posting = (new Posting)->debit($debit, $amount)->credit(AccountRef::platform(LedgerAccountCode::AdRevenue), $amount->minus($vat))->credit(AccountRef::platform(LedgerAccountCode::VatPayable), $vat);
        $this->ledger->post("ad-package:{$subscription->id}:activate", 'ad_package', $posting, $actor, $userId, $subscription, 'Ad package');

        return $this->machine->move($subscription, SubscriptionStatus::Active, $actor, $userId, null, [
            'starts_at' => now(),
            'expires_at' => now()->addDays($subscription->package->duration_days),
        ]);
    }

    private function vat(Money $gross): Money
    {
        $rate = (string) $this->countryContext->current()->tax_rate;

        return $gross->multipliedBy($rate, RoundingMode::HalfUp)->dividedBy(bcadd('100', $rate, 2), RoundingMode::HalfUp);
    }
}
