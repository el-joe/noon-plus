<?php

namespace App\Domains\GiftCards\Support;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Finance\Enums\LedgerAccountCode;
use App\Domains\Finance\Support\AccountRef;
use App\Domains\Finance\Support\FinanceSettings;
use App\Domains\Finance\Support\Ledger;
use App\Domains\Finance\Support\Posting;
use App\Domains\Finance\Support\Wallets;
use App\Domains\GiftCards\Enums\GiftCardStatus;
use App\Domains\GiftCards\Models\GiftCard;
use App\Domains\GiftCards\Notifications\GiftCardDelivered;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Support\PaymentAttempts;
use App\Domains\Payments\Support\PaymentCaptured;
use App\Domains\Payments\Support\PaymentMethodCatalog;
use App\Models\User;
use App\Shared\Money\MoneyFactory;
use App\Shared\Money\MoneyFormatter;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\TransitionRefused;
use Brick\Money\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Gift cards (Spec §6.2, SRS §10, lifecycle catalogue §4.12).
 *
 * - Buy: amount within `gift_card.custom_amount_min/max`, paid by card
 *   gateway; once captured Dr gateway clearing / Cr gift-card liability and
 *   the card is emailed to the recipient now or at `send_at` (job).
 * - Admin issue: Dr promo expense / Cr gift-card liability.
 * - Redeem: the whole balance becomes spendable Nawy Credits in the card's
 *   country (Dr liability / Cr wallet), once.
 * - Expiry (daily 00:30): unredeemed value is breakage revenue; purchases
 *   never paid within a day are cancelled.
 */
class GiftCards
{
    public function __construct(
        private StateMachine $machine,
        private Ledger $ledger,
        private Wallets $wallets,
        private GiftCodes $codes,
        private MoneyFactory $money,
        private FinanceSettings $settings,
        private CountryContext $countryContext,
    ) {}

    /**
     * @return array{0: GiftCard, 1: Payment}
     */
    public function purchase(User $purchaser, Money $amount, string $methodCode, string $recipientEmail, ?string $recipientName = null, ?string $message = null, ?CarbonInterface $sendAt = null): array
    {
        $this->assertAmountAllowed($amount);
        $country = $this->countryContext->current();
        $option = app(PaymentMethodCatalog::class)->find($country, $methodCode, $amount);

        if ($option === null || ! $option['method']->isGateway()) {
            throw new TransitionRefused(__('Gift cards are paid by card.'));
        }

        $card = $this->createCard(GiftCardStatus::PendingPayment, 'purchase', $amount, $recipientEmail, $recipientName, $message, $sendAt, $purchaser->id)[0];
        $payment = app(PaymentAttempts::class)->startForPayable(GiftCard::PAYABLE, $card->id, $card->number, $amount, $option['method'], $purchaser, __('Nawy gift card :number', ['number' => $card->number]));

        return [$card->refresh(), $payment];
    }

    /**
     * Called inside the payment capture ({@see PaymentCaptured}).
     */
    public function paid(Payment $payment): void
    {
        $card = GiftCard::query()->lockForUpdate()->find($payment->payable_id);

        if ($card === null || $card->status !== GiftCardStatus::PendingPayment) {
            return;
        }

        $amount = $this->money->of((string) $payment->captured_amount, $payment->currency);
        $this->ledger->post("gift-card:{$card->id}:sale", 'gift_card_sale', (new Posting)
            ->debit(app(PaymentCaptured::class)->clearingAccount($payment->method), $amount)
            ->credit(AccountRef::platform(LedgerAccountCode::GiftCardLiability), $amount), ActorType::Customer, $payment->customer_user_id, $card, "Gift card {$card->number} sold");

        $this->activateOrSchedule($card, ActorType::System);
    }

    /**
     * Admin-issued card (promotional cost).
     */
    public function issue(int $adminUserId, Money $amount, string $recipientEmail, ?string $recipientName = null, ?string $message = null): GiftCard
    {
        return DB::connection((new GiftCard)->getConnectionName())->transaction(function () use ($adminUserId, $amount, $recipientEmail, $recipientName, $message): GiftCard {
            [$card] = $this->createCard(GiftCardStatus::PendingPayment, 'admin', $amount, $recipientEmail, $recipientName, $message, null, null);
            $this->ledger->post("gift-card:{$card->id}:issue", 'gift_card_issue', (new Posting)
                ->debit(AccountRef::platform(LedgerAccountCode::PromoExpense), $amount)
                ->credit(AccountRef::platform(LedgerAccountCode::GiftCardLiability), $amount), ActorType::Admin, $adminUserId, $card, "Gift card {$card->number} issued");
            $this->activateOrSchedule($card, ActorType::Admin, $adminUserId);

            return $card->refresh();
        });
    }

    /**
     * Admin void (wave 2): a card nobody redeemed is cancelled. An
     * admin-issued card gives its promotional value back (Dr gift-card
     * liability / Cr promo expense); a purchase that was never paid has no
     * money to move. A paid customer purchase is refunded through its
     * payment instead, so it cannot be voided here.
     */
    public function void(GiftCard $card, int $adminUserId, string $reason): GiftCard
    {
        return DB::connection($card->getConnectionName())->transaction(function () use ($card, $adminUserId, $reason): GiftCard {
            $locked = GiftCard::query()->lockForUpdate()->findOrFail($card->id);
            $paidPurchase = $locked->source === 'purchase' && $locked->status !== GiftCardStatus::PendingPayment;

            if ($paidPurchase || ! in_array($locked->status, [GiftCardStatus::PendingPayment, GiftCardStatus::Scheduled, GiftCardStatus::Active], true)) {
                throw new TransitionRefused($paidPurchase
                    ? __('A paid gift card is refunded through its payment, not voided.')
                    : __('Only a card that has not been redeemed, expired or cancelled can be voided.'));
            }

            $balance = $this->money->of((string) $locked->balance, $locked->currency);

            if ($locked->source === 'admin' && $balance->isPositive()) {
                $this->ledger->post("gift-card:{$locked->id}:void", 'gift_card_void', (new Posting)
                    ->debit(AccountRef::platform(LedgerAccountCode::GiftCardLiability), $balance)
                    ->credit(AccountRef::platform(LedgerAccountCode::PromoExpense), $balance), ActorType::Admin, $adminUserId, $locked, "Gift card {$locked->number} voided");
            }

            $this->machine->move($locked, GiftCardStatus::Cancelled, ActorType::Admin, $adminUserId, $reason, ['balance' => '0']);
            $card->setRawAttributes($locked->getAttributes(), true);

            return $card;
        });
    }

    /**
     * Gift-card scheduled email delivery job.
     */
    public function deliverScheduled(): int
    {
        $delivered = 0;

        GiftCard::query()->where('status', GiftCardStatus::Scheduled)->where('send_at', '<=', now())->each(function (GiftCard $card) use (&$delivered): void {
            $this->machine->move($card, GiftCardStatus::Active, ActorType::System, null, 'Scheduled delivery');
            $this->deliver($card);
            $delivered++;
        });

        return $delivered;
    }

    /**
     * Redeem the whole balance into spendable Nawy Credits.
     */
    public function redeem(User $customer, string $code): GiftCard
    {
        $country = $this->countryContext->current();

        return DB::connection((new GiftCard)->getConnectionName())->transaction(function () use ($customer, $code, $country): GiftCard {
            $card = GiftCard::query()->where('code_hash', $this->codes->hash($code))->lockForUpdate()->first();

            if ($card === null || $card->currency !== $country->currency) {
                throw new TransitionRefused(__('This gift card code is not valid in this country.'));
            }

            if ($card->status !== GiftCardStatus::Active || $card->expires_at->isPast()) {
                throw new TransitionRefused(match ($card->status) {
                    GiftCardStatus::Redeemed => __('This gift card was already redeemed.'),
                    GiftCardStatus::Scheduled, GiftCardStatus::PendingPayment => __('This gift card is not active yet.'),
                    default => __('This gift card can no longer be used.'),
                });
            }

            $balance = $this->money->of((string) $card->balance, $card->currency);
            $this->wallets->credit($customer->id, $balance, "gift-card:{$card->id}:redeem", 'gift_card_redeem',
                AccountRef::platform(LedgerAccountCode::GiftCardLiability), $card, ActorType::Customer, $customer->id, "Gift card {$card->number} redeemed");

            return $this->machine->move($card, GiftCardStatus::Redeemed, ActorType::Customer, $customer->id, null, [
                'balance' => '0',
                'redeemed_by_user_id' => $customer->id,
                'redeemed_at' => now(),
            ]);
        });
    }

    /**
     * Daily 00:30: expire unredeemed cards (breakage) and cancel purchases
     * that were never paid within a day.
     */
    public function expire(): int
    {
        $expired = 0;

        GiftCard::query()->where('status', GiftCardStatus::Active)->where('expires_at', '<=', now())->each(function (GiftCard $card) use (&$expired): void {
            DB::connection($card->getConnectionName())->transaction(function () use ($card): void {
                $balance = $this->money->of((string) $card->balance, $card->currency);
                $this->ledger->post("gift-card:{$card->id}:expire", 'gift_card_breakage', (new Posting)
                    ->debit(AccountRef::platform(LedgerAccountCode::GiftCardLiability), $balance)
                    ->credit(AccountRef::platform(LedgerAccountCode::BreakageRevenue), $balance), reference: $card, description: "Gift card {$card->number} expired");
                $this->machine->move($card, GiftCardStatus::Expired, ActorType::System, null, null, ['balance' => '0']);
            });
            $expired++;
        });

        GiftCard::query()->where('status', GiftCardStatus::PendingPayment)->where('created_at', '<=', now()->subDay())->each(
            fn (GiftCard $card) => $this->machine->move($card, GiftCardStatus::Cancelled, ActorType::System, null, 'Not paid'),
        );

        return $expired;
    }

    /**
     * @return array{0: GiftCard, 1: string}
     */
    private function createCard(GiftCardStatus $status, string $source, Money $amount, string $recipientEmail, ?string $recipientName, ?string $message, ?CarbonInterface $sendAt, ?int $purchaserUserId): array
    {
        $code = $this->codes->generate();
        $card = GiftCard::query()->create([
            'number' => 'PENDING-'.uniqid(),
            'code_hash' => $this->codes->hash($code),
            'code_encrypted' => $code,
            'code_last4' => substr($this->codes->normalize($code), -4),
            'status' => $status,
            'source' => $source,
            'currency' => $amount->getCurrency()->getCurrencyCode(),
            'amount' => $this->money->toStorage($amount),
            'balance' => $this->money->toStorage($amount),
            'purchaser_user_id' => $purchaserUserId,
            'recipient_name' => $recipientName,
            'recipient_email' => $recipientEmail,
            'message' => $message,
            'send_at' => $sendAt,
            'expires_at' => now()->addMonthsNoOverflow((int) $this->settings->get('gift_card.default_expiry_months', 'gift_card_expiry_months')),
        ]);
        $card->forceFill(['number' => sprintf('GC-%s-%06d', $this->countryContext->current()->iso2, $card->id)])->save();
        $this->machine->created($card, $purchaserUserId === null ? ActorType::Admin : ActorType::Customer, $purchaserUserId);

        return [$card, $code];
    }

    private function activateOrSchedule(GiftCard $card, ActorType $actorType, ?int $actorUserId = null): void
    {
        if ($card->send_at !== null && $card->send_at->isFuture()) {
            $this->machine->move($card, GiftCardStatus::Scheduled, $actorType, $actorUserId);

            return;
        }

        $this->machine->move($card, GiftCardStatus::Active, $actorType, $actorUserId);
        $this->deliver($card);
    }

    private function deliver(GiftCard $card): void
    {
        $sender = $card->purchaser_user_id === null ? 'Nawy' : User::query()->whereKey($card->purchaser_user_id)->value('name');

        Notification::route('mail', $card->recipient_email)->notify(new GiftCardDelivered(
            $card->code_encrypted,
            app(MoneyFormatter::class)->format((string) $card->amount, $card->currency),
            $sender,
            $card->message,
            $card->expires_at->toDateString(),
        ));
        $card->forceFill(['delivered_at' => now()])->save();
    }

    private function assertAmountAllowed(Money $amount): void
    {
        $currency = $amount->getCurrency()->getCurrencyCode();
        $min = $this->money->of((string) ($this->settings->get('gift_card.custom_amount_min', "gift_card_limits.{$currency}.0") ?? '1'), $currency);
        $max = $this->money->of((string) ($this->settings->get('gift_card.custom_amount_max', "gift_card_limits.{$currency}.1") ?? '100000'), $currency);

        if ($amount->isLessThan($min) || $amount->isGreaterThan($max)) {
            throw new TransitionRefused(__('A gift card can be :min to :max.', ['min' => (string) $min->getAmount(), 'max' => (string) $max->getAmount()]));
        }
    }
}
