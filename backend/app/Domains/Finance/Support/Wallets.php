<?php

namespace App\Domains\Finance\Support;

use App\Domains\Finance\Enums\LedgerAccountCode;
use App\Domains\Finance\Enums\PromotionalCreditStatus;
use App\Domains\Finance\Enums\WalletBucket;
use App\Domains\Finance\Exceptions\InsufficientFunds;
use App\Domains\Finance\Models\LedgerAccount;
use App\Domains\Finance\Models\LedgerEntry;
use App\Domains\Finance\Models\PromotionalCredit;
use App\Domains\Orders\Enums\ActorType;
use App\Models\User;
use App\Shared\Money\MoneyFactory;
use App\Shared\Notifications\Email\EmailLinks;
use App\Shared\Notifications\Email\Emails;
use App\Shared\Notifications\Email\EmailValue;
use Brick\Money\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Nawy Credits — the customer wallet per country (ADR 4, MASTER_PLAN §10).
 * Balances are ledger accounts per bucket; every movement is a ledger
 * transaction. Payments use promotional credits first (soonest expiry
 * first), then spendable credits; frozen credits cannot be spent.
 */
class Wallets
{
    public function __construct(
        private Ledger $ledger,
        private MoneyFactory $money,
        private FinanceSettings $settings,
        private Emails $emails,
    ) {}

    /**
     * @return array<string, Money> bucket => balance (spendable, promotional, frozen)
     */
    public function balances(int $customerUserId, string $currency): array
    {
        $balances = [];

        foreach ([WalletBucket::Spendable, WalletBucket::Promotional, WalletBucket::Frozen] as $bucket) {
            $balances[$bucket->value] = $this->ledger->balance(AccountRef::customerWallet($customerUserId, $bucket), $currency);
        }

        return $balances;
    }

    /**
     * What the customer can pay with now (spendable + promotional).
     */
    public function available(int $customerUserId, string $currency): Money
    {
        $balances = $this->balances($customerUserId, $currency);

        return $balances['spendable']->plus($balances['promotional']);
    }

    /**
     * Take a payment from the wallet into `wallet_clearing` (captured later
     * like any gateway payment). Idempotent per key.
     *
     * @return array{promotional: Money, spendable: Money}
     *
     * @throws InsufficientFunds
     */
    public function pay(int $customerUserId, Money $amount, string $idempotencyKey, ?Model $reference = null): array
    {
        $currency = $amount->getCurrency()->getCurrencyCode();

        return DB::connection((new PromotionalCredit)->getConnectionName())->transaction(function () use ($customerUserId, $amount, $idempotencyKey, $reference, $currency): array {
            $previous = $this->previousSplit($idempotencyKey, $currency);

            if ($previous !== null) {
                return $previous;
            }

            if ($this->available($customerUserId, $currency)->isLessThan($amount)) {
                throw new InsufficientFunds(__('Your Nawy Credits balance is not enough for this payment.'));
            }

            $remaining = $amount;
            $promotional = $this->money->zero($currency);
            $grants = $this->liveGrants($customerUserId, $currency)->where('expires_at', '>', now())->orderBy('expires_at')->orderBy('id')->lockForUpdate()->get();

            foreach ($grants as $grant) {
                if ($remaining->isZero()) {
                    break;
                }

                $take = Money::min($remaining, $this->money->of((string) $grant->remaining, $currency));
                $left = $this->money->of((string) $grant->remaining, $currency)->minus($take);
                $grant->forceFill([
                    'remaining' => $this->money->toStorage($left),
                    'status' => $left->isZero() ? PromotionalCreditStatus::Used : PromotionalCreditStatus::PartiallyUsed,
                ])->save();
                $promotional = $promotional->plus($take);
                $remaining = $remaining->minus($take);
            }

            $this->ledger->post($idempotencyKey, 'wallet_payment', (new Posting)
                ->debit(AccountRef::customerWallet($customerUserId, WalletBucket::Promotional), $promotional)
                ->debit(AccountRef::customerWallet($customerUserId, WalletBucket::Spendable), $remaining)
                ->credit(AccountRef::platform(LedgerAccountCode::WalletClearing), $amount), ActorType::Customer, $customerUserId, $reference, 'Paid with Nawy Credits');

            return ['promotional' => $promotional, 'spendable' => $remaining];
        });
    }

    /**
     * Add spendable credits against a counter account (refund, gift card,
     * goodwill…). Idempotent per key.
     */
    public function credit(int $customerUserId, Money $amount, string $idempotencyKey, string $type, AccountRef $counter, ?Model $reference = null, ActorType $actorType = ActorType::System, ?int $actorUserId = null, ?string $description = null): void
    {
        $this->ledger->post($idempotencyKey, $type, (new Posting)
            ->debit($counter, $amount)
            ->credit(AccountRef::customerWallet($customerUserId), $amount), $actorType, $actorUserId, $reference, $description);
        $this->emailCredited($customerUserId, $amount, $idempotencyKey, $type);
    }

    /**
     * Grant promotional credits that expire (default
     * `promotional_credit.default_expiry_days`, 90). Idempotent per key.
     */
    public function grantPromotional(int $customerUserId, Money $amount, string $idempotencyKey, string $source, ?CarbonInterface $expiresAt = null, ?AccountRef $counter = null, ActorType $actorType = ActorType::System, ?int $actorUserId = null): PromotionalCredit
    {
        $currency = $amount->getCurrency()->getCurrencyCode();

        return DB::connection((new PromotionalCredit)->getConnectionName())->transaction(function () use ($customerUserId, $amount, $idempotencyKey, $source, $expiresAt, $counter, $actorType, $actorUserId, $currency): PromotionalCredit {
            $existing = PromotionalCredit::query()->where('reference', $idempotencyKey)->first();

            if ($existing !== null) {
                return $existing;
            }

            $grant = PromotionalCredit::query()->create([
                'customer_user_id' => $customerUserId,
                'currency' => $currency,
                'amount' => $this->money->toStorage($amount),
                'remaining' => $this->money->toStorage($amount),
                'status' => PromotionalCreditStatus::Granted,
                'source' => $source,
                'reference' => $idempotencyKey,
                'expires_at' => $expiresAt ?? now()->addDays($this->defaultExpiryDays()),
            ]);

            $this->ledger->post($idempotencyKey, 'promotional_grant', (new Posting)
                ->debit($counter ?? AccountRef::platform(LedgerAccountCode::PromoExpense), $amount)
                ->credit(AccountRef::customerWallet($customerUserId, WalletBucket::Promotional), $amount), $actorType, $actorUserId, $grant, "Promotional credits ({$source})");

            // A refund's promotional share is part of the refund email (`refund.issued`).
            if ($source !== 'refund') {
                $this->emailCredited($customerUserId, $amount, $idempotencyKey, $source, $grant->expires_at);
            }

            return $grant;
        });
    }

    /**
     * Promotion expiry job: unused promotional credits past `expires_at`
     * leave the wallet (the promo expense is reversed). Returns how many grants expired.
     */
    public function expirePromotional(): int
    {
        $expired = 0;

        PromotionalCredit::query()
            ->whereIn('status', [PromotionalCreditStatus::Granted, PromotionalCreditStatus::PartiallyUsed])
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->each(function (PromotionalCredit $grant) use (&$expired): void {
                DB::connection($grant->getConnectionName())->transaction(function () use ($grant, &$expired): void {
                    $locked = PromotionalCredit::query()->lockForUpdate()->findOrFail($grant->id);

                    if (! in_array($locked->status, [PromotionalCreditStatus::Granted, PromotionalCreditStatus::PartiallyUsed], true)) {
                        return;
                    }

                    $remaining = $this->money->of((string) $locked->remaining, $locked->currency);
                    $this->ledger->post("promo-expire:{$locked->id}", 'promotional_expiry', (new Posting)
                        ->debit(AccountRef::customerWallet($locked->customer_user_id, WalletBucket::Promotional), $remaining)
                        ->credit(AccountRef::platform(LedgerAccountCode::PromoExpense), $remaining), reference: $locked, description: 'Promotional credits expired');
                    $locked->forceFill(['status' => PromotionalCreditStatus::Expired, 'remaining' => '0'])->save();
                    $expired++;

                    if ($remaining->isPositive()) {
                        $this->emails->send('wallet.credit_expired', $locked->customer_user_id, [
                            'amount' => EmailValue::money((string) $remaining->getAmount(), $locked->currency),
                            'balance' => EmailValue::money((string) $this->available($locked->customer_user_id, $locked->currency)->getAmount(), $locked->currency),
                        ], ['entity' => $locked, 'url' => $this->walletLink($locked->customer_user_id)]);
                    }
                });
            });

        return $expired;
    }

    /**
     * Admin freeze: spendable credits move to the frozen bucket (and back).
     */
    public function freeze(int $customerUserId, string $currency, int $adminUserId, bool $freeze = true): void
    {
        $from = $freeze ? WalletBucket::Spendable : WalletBucket::Frozen;
        $to = $freeze ? WalletBucket::Frozen : WalletBucket::Spendable;
        $amount = $this->ledger->balance(AccountRef::customerWallet($customerUserId, $from), $currency);

        if (! $amount->isPositive()) {
            return;
        }

        $this->ledger->post(($freeze ? 'wallet-freeze:' : 'wallet-unfreeze:').$customerUserId.':'.now()->getTimestampMs(), $freeze ? 'wallet_freeze' : 'wallet_unfreeze', (new Posting)
            ->debit(AccountRef::customerWallet($customerUserId, $from), $amount)
            ->credit(AccountRef::customerWallet($customerUserId, $to), $amount), ActorType::Admin, $adminUserId);
    }

    /**
     * Wallet movements of the customer, newest first.
     *
     * @return Builder<LedgerEntry>
     */
    public function history(int $customerUserId, string $currency): Builder
    {
        return LedgerEntry::query()
            ->whereIn('ledger_account_id', LedgerAccount::query()
                ->where('code', LedgerAccountCode::CustomerWallet)
                ->where('owner_type', LedgerAccount::OWNER_CUSTOMER)
                ->where('owner_id', $customerUserId)
                ->where('currency', $currency)
                ->select('id'))
            ->with(['account', 'transaction'])
            ->latest('id');
    }

    /**
     * @return Builder<PromotionalCredit>
     */
    private function liveGrants(int $customerUserId, string $currency): Builder
    {
        return PromotionalCredit::query()
            ->where('customer_user_id', $customerUserId)
            ->where('currency', $currency)
            ->whereIn('status', [PromotionalCreditStatus::Granted, PromotionalCreditStatus::PartiallyUsed]);
    }

    /**
     * @return array{promotional: Money, spendable: Money}|null
     */
    private function previousSplit(string $idempotencyKey, string $currency): ?array
    {
        $entries = LedgerEntry::query()
            ->whereHas('transaction', fn ($query) => $query->where('idempotency_key', $idempotencyKey))
            ->where('direction', 'debit')
            ->with('account')
            ->get();

        if ($entries->isEmpty()) {
            return null;
        }

        $part = fn (WalletBucket $bucket): Money => $this->money->of((string) ($entries->first(fn (LedgerEntry $entry): bool => $entry->account->bucket === $bucket->value)?->amount ?? '0'), $currency);

        return ['promotional' => $part(WalletBucket::Promotional), 'spendable' => $part(WalletBucket::Spendable)];
    }

    private function defaultExpiryDays(): int
    {
        return (int) $this->settings->get('promotional_credit.default_expiry_days', 'promotional_credit_expiry_days');
    }

    /**
     * `wallet.credited` to the customer (once per wallet movement key).
     */
    private function emailCredited(int $customerUserId, Money $amount, string $idempotencyKey, string $source, ?CarbonInterface $expiresAt = null): void
    {
        if (! $amount->isPositive()) {
            return;
        }

        $currency = $amount->getCurrency()->getCurrencyCode();

        $this->emails->send('wallet.credited', $customerUserId, [
            'amount' => EmailValue::money((string) $amount->getAmount(), $currency),
            'source' => EmailValue::t(match ($source) {
                'gift_card_redeem' => 'gift card redeemed',
                'voucher' => 'voucher',
                'carrier_claim' => 'delivery claim',
                'refund' => 'refund',
                default => 'credit from Nawy',
            }),
            'balance' => EmailValue::money((string) $this->available($customerUserId, $currency)->getAmount(), $currency),
            'expires' => $expiresAt === null ? '' : EmailValue::t(' It expires on :date.', ['date' => EmailValue::date($expiresAt)]),
        ], ['key' => 'wallet:'.$idempotencyKey, 'url' => $this->walletLink($customerUserId)]);
    }

    private function walletLink(int $customerUserId): string
    {
        $locale = User::query()->whereKey($customerUserId)->value('preferred_locale');

        return app(EmailLinks::class)->storefront('account/credits', in_array($locale, ['en', 'ar'], true) ? $locale : (string) config('app.locale'));
    }
}
