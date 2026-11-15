<?php

namespace App\Domains\Advertising\Support;

use App\Domains\Campaigns\Support\CampaignLedger;
use App\Domains\Finance\Enums\LedgerAccountCode;
use App\Domains\Finance\Enums\WalletBucket;
use App\Domains\Finance\Support\AccountRef;
use App\Domains\Finance\Support\Ledger;
use App\Domains\Finance\Support\Posting;
use App\Shared\StateMachine\TransitionRefused;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * How advertisers pay (SRS §10, lifecycle catalogue §4.5–4.9): a vendor is
 * charged on its statement (Dr vendor spendable / Cr ad revenue, a negative
 * statement line — like WP5 campaign fees); a marketer pays from its
 * spendable commission wallet, which must cover the amount. Refunds post
 * the reverse with their own idempotency key.
 */
class AdvertiserBilling
{
    public const VENDOR = 'vendor';

    public const MARKETER = 'marketer';

    public function __construct(private CampaignLedger $campaignLedger, private Ledger $ledger) {}

    public function charge(string $payerType, int $payerId, Money $amount, string $itemType, Model $reference, string $key, string $description): void
    {
        if (! $amount->isPositive()) {
            return;
        }

        if ($payerType === self::VENDOR) {
            $this->campaignLedger->chargeVendor($payerId, $amount, $itemType, $reference, $key, $description, AccountRef::platform(LedgerAccountCode::AdRevenue));

            return;
        }

        $wallet = AccountRef::marketer($payerId, WalletBucket::Spendable);

        if ($this->ledger->balance($wallet, $amount->getCurrency()->getCurrencyCode())->isLessThan($amount)) {
            throw new TransitionRefused(__('Your wallet does not cover :amount.', ['amount' => (string) $amount->getAmount()]));
        }

        $this->ledger->post($key, $itemType, (new Posting)->debit($wallet, $amount)->credit(AccountRef::platform(LedgerAccountCode::AdRevenue), $amount), reference: $reference, description: $description);
    }

    public function refund(string $payerType, int $payerId, Money $amount, string $itemType, Model $reference, string $key, string $description): void
    {
        if (! $amount->isPositive()) {
            return;
        }

        if ($payerType === self::VENDOR) {
            $this->campaignLedger->creditVendor($payerId, $amount, $itemType, $reference, $key, $description, AccountRef::platform(LedgerAccountCode::AdRevenue));

            return;
        }

        $this->ledger->post($key, $itemType, (new Posting)->debit(AccountRef::platform(LedgerAccountCode::AdRevenue), $amount)->credit(AccountRef::marketer($payerId, WalletBucket::Spendable), $amount), reference: $reference, description: $description);
    }
}
