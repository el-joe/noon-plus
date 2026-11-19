<?php

namespace App\Domains\Campaigns\Support;

use App\Domains\Campaigns\Enums\ConversionStatus;
use App\Domains\Campaigns\Enums\InvitationSource;
use App\Domains\Campaigns\Enums\ReferralStatus;
use App\Domains\Campaigns\Models\CampaignInvitation;
use App\Domains\Campaigns\Models\MarketerCampaign;
use App\Domains\Campaigns\Models\MarketerConversion;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Finance\Enums\LedgerAccountCode;
use App\Domains\Finance\Enums\WalletBucket;
use App\Domains\Finance\Models\VendorBalanceItem;
use App\Domains\Finance\Support\AccountRef;
use App\Domains\Finance\Support\Posting;
use App\Domains\FlashSales\Models\FlashSaleSubmission;
use App\Domains\FlashSales\Support\FlashSaleMarketerInvites;
use App\Domains\Marketers\Enums\MarketerJobType;
use App\Domains\Marketers\Support\MarketingSettings;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Models\OrderItem;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Returns\Enums\ReturnStatus;
use App\Domains\Returns\Models\ReturnRequest;
use App\Shared\Money\MoneyFactory;
use App\Shared\Notifications\Email\EmailValue;
use App\Shared\Notifications\Email\PartnerEmails;
use App\Shared\StateMachine\StateMachine;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * Conversions (SRS §11.11, lifecycle catalogue §4.3).
 *
 * Record (under the campaign row lock, one per order item × invitation):
 *   basis = line total after the line's share of the sub-order discount, excl. VAT
 *   gross = affiliate %·basis | broker fixed·qty + %·basis | peer promoter %·basis
 *           (× weight for linear attribution; shortened to the budget left)
 *   fee   = min(gross, fee %·gross + fee fixed);  net = gross − fee
 *   bonus = flash-sale bonus % · basis when sold at a flash price
 * Influencer slots earn no per-sale commission (except peer promoters).
 *
 * Approve (03:15): sub-order delivered/completed, every item's return window
 * passed and no open return → Dr payer (store or promo expense) gross,
 * Dr promo expense bonus / Cr marketer pending net+bonus, Cr fee revenue fee;
 * a negative line on the store's statement.
 * Release (03:30, after clearing days): marketer pending → spendable, then
 * clawback recovery.
 * Paid (QC-035): when a withdrawal is processed, released conversions are
 * marked paid oldest first while the marketer's processed withdrawals
 * still cover them (spendable money is pooled, so FIFO is the attribution).
 * Reverse (cancel / return / refund): pending → amounts dropped (budget
 * freed); approved → mirrored back; released → taken from spendable and
 * the shortfall booked to the clawback bucket (clawback_pending); paid
 * conversions reverse the same way as released ones.
 */
class Conversions
{
    public function __construct(
        private StateMachine $machine,
        private CampaignLedger $ledger,
        private MarketingSettings $settings,
        private MoneyFactory $money,
        private CountryContext $countryContext,
        private Clawbacks $clawbacks,
    ) {}

    public static function commissionFee(Money $gross, array $rates, MoneyFactory $money): Money
    {
        $fee = $money->percentageOf($gross, (string) ($rates['fee_percent'] ?? '0'))
            ->plus($money->of((string) ($rates['fee_fixed'] ?? '0'), $gross->getCurrency()->getCurrencyCode()));

        return Money::min(Money::max($fee, Money::zero($gross->getCurrency()->getCurrencyCode())), $gross);
    }

    public function basis(OrderItem $item): Money
    {
        $country = $this->countryContext->current();
        $subOrder = $item->subOrder;
        $line = $this->money->of((string) $item->line_total, $country->currency);
        $subtotal = $this->money->of((string) $subOrder->subtotal, $country->currency);
        $discount = $this->money->of((string) $subOrder->discount_total, $country->currency);

        if ($discount->isPositive() && $subtotal->isPositive()) {
            $line = $line->minus($discount->multipliedBy(BigRational::of((string) $line->getAmount())->dividedBy((string) $subtotal->getAmount()), RoundingMode::HalfUp));
        }

        return $line->multipliedBy(BigRational::of(100)->dividedBy(BigRational::of((string) $country->tax_rate)->plus(100)), RoundingMode::HalfUp);
    }

    public function record(CampaignInvitation $invitation, OrderItem $item, string $attribution, string $weight = '1'): ?MarketerConversion
    {
        return DB::connection($item->getConnectionName())->transaction(function () use ($invitation, $item, $attribution, $weight): ?MarketerConversion {
            $campaign = MarketerCampaign::query()->lockForUpdate()->findOrFail($invitation->campaign_id);
            $existing = MarketerConversion::query()->where('source_type', 'order_item')->where('source_id', $item->id)->where('invitation_id', $invitation->id)->first();

            if ($existing !== null) {
                return $existing;
            }

            $currency = $campaign->currency;
            $basis = $this->basis($item)->multipliedBy($weight, RoundingMode::HalfUp);
            $rates = $invitation->rate_snapshot ?? [];
            $gross = match (true) {
                $invitation->source === InvitationSource::Peer => $this->money->percentageOf($basis, (string) ($rates['affiliate_percent'] ?? '0')),
                $invitation->effective_type === MarketerJobType::Broker->value => $this->money->of((string) ($rates['broker_fixed'] ?? '0'), $currency)->multipliedBy($item->quantity)->multipliedBy($weight, RoundingMode::HalfUp)
                    ->plus($this->money->percentageOf($basis, (string) ($rates['broker_percent'] ?? '0'))),
                $invitation->effective_type === MarketerJobType::Affiliate->value => $this->money->percentageOf($basis, (string) ($rates['affiliate_percent'] ?? '0')),
                default => $this->money->zero($currency),
            };

            if ($campaign->budget !== null) {
                $left = $this->money->of((string) $campaign->budget, $currency)
                    ->minus($this->money->of((string) $campaign->budget_spent, $currency))
                    ->minus($this->money->of((string) $campaign->influencer_fixed_reserved, $currency));
                $gross = Money::min($gross, Money::max($left, $this->money->zero($currency)));
            }

            if (! $gross->isPositive()) {
                return null;
            }

            $fee = self::commissionFee($gross, $rates, $this->money);
            $bonusPercent = (string) $this->settings->get('flash_sale_bonus_percent');
            $bonus = $item->flash_sale_submission_id !== null && bccomp($bonusPercent, '0', 4) > 0 ? $this->money->percentageOf($basis, $bonusPercent) : $this->money->zero($currency);
            $flashEvent = $item->flash_sale_submission_id === null ? null : FlashSaleSubmission::query()->find($item->flash_sale_submission_id)?->event;

            if ($flashEvent !== null) {
                $bonus = app(FlashSaleMarketerInvites::class)->bonusFor($flashEvent, $invitation->marketer_profile_id, $basis, (int) $item->quantity) ?? $bonus;
            }

            $conversion = MarketerConversion::query()->create([
                'campaign_id' => $campaign->id,
                'invitation_id' => $invitation->id,
                'marketer_profile_id' => $invitation->marketer_profile_id,
                'type' => 'product_sale',
                'source_type' => 'order_item',
                'source_id' => $item->id,
                'order_id' => $item->subOrder->order_id,
                'sub_order_id' => $item->sub_order_id,
                'attribution' => $attribution,
                'weight' => $weight,
                'currency' => $currency,
                'basis' => $this->money->toStorage($basis),
                'gross' => $this->money->toStorage($gross),
                'fee' => $this->money->toStorage($fee),
                'net' => $this->money->toStorage($gross->minus($fee)),
                'bonus' => $this->money->toStorage($bonus),
                'status' => ConversionStatus::Pending,
            ]);
            $this->machine->created($conversion, ActorType::System);
            app(PartnerEmails::class)->toMarketer((int) $invitation->marketer_profile_id, 'marketer.conversion', [
                'campaign' => (string) $campaign->title,
                'amount' => EmailValue::money((string) $conversion->net, $currency),
                'order_reference' => (string) $item->subOrder->number,
            ], '/wallet', ['entity' => $conversion]);

            $spent = $this->money->of((string) $campaign->budget_spent, $currency)->plus($gross);
            $campaign->forceFill(['budget_spent' => $this->money->toStorage($spent)])->save();

            if ($campaign->budget !== null && ! app(CampaignStateMachine::class)->remainingBudget($campaign)->isPositive() && ! $campaign->status->isTerminal()) {
                app(CampaignStateMachine::class)->complete($campaign, reason: 'Budget used up');
            }

            return $conversion;
        });
    }

    /**
     * ApproveMarketerConversionsJob (daily 03:15).
     */
    public function approveDue(): int
    {
        $approved = 0;

        MarketerConversion::query()->where('status', ConversionStatus::Pending)->where('type', 'product_sale')->orderBy('id')
            ->each(function (MarketerConversion $conversion) use (&$approved): void {
                $subOrder = SubOrder::query()->with('items')->find($conversion->sub_order_id);

                if ($subOrder === null || ! in_array($subOrder->status, [SubOrderStatus::Delivered, SubOrderStatus::Completed], true) || $subOrder->delivered_at === null) {
                    return;
                }

                $default = (int) config('nawy.orders.return_window_days');
                $days = (int) $subOrder->items->max(fn ($item): int => $item->return_window_days ?? $default);

                if ($subOrder->delivered_at->copy()->addDays($days)->isFuture() || $this->hasOpenReturn($subOrder)) {
                    return;
                }

                $this->approve($conversion);
                $approved++;
            });

        return $approved;
    }

    public function approve(MarketerConversion $conversion): MarketerConversion
    {
        return DB::connection($conversion->getConnectionName())->transaction(function () use ($conversion): MarketerConversion {
            $campaign = $conversion->campaign;
            [$gross, $fee, $net, $bonus] = $this->amounts($conversion);
            $posting = (new Posting)
                ->debit($this->ledger->payer($campaign->vendor_id), $gross)
                ->debit(AccountRef::platform(LedgerAccountCode::PromoExpense), $bonus)
                ->credit(AccountRef::marketer($conversion->marketer_profile_id, WalletBucket::Pending), $net->plus($bonus))
                ->credit(AccountRef::platform(LedgerAccountCode::FeeRevenue), $fee);
            $this->ledger->post("conversion:{$conversion->id}:approve", 'marketer_commission', $posting, $conversion, "Commission {$campaign->number}");

            if ($campaign->vendor_id !== null) {
                $this->ledger->balanceItem($campaign->vendor_id, VendorBalanceItem::TYPE_MARKETER_COMMISSION, $conversion, "Marketer commission {$campaign->number}", $gross->negated());
            }

            return $this->machine->move($conversion, ConversionStatus::Approved, ActorType::System, null, null, ['approved_at' => now()]);
        });
    }

    /**
     * ReleaseMarketerPendingCommissionJob (daily 03:30).
     */
    public function releaseDue(): int
    {
        $released = 0;
        $cutoff = now()->subDays($this->settings->int('clearing_days'));

        MarketerConversion::query()->where('status', ConversionStatus::Approved)->where('approved_at', '<=', $cutoff)->orderBy('id')
            ->each(function (MarketerConversion $conversion) use (&$released): void {
                $this->release($conversion);
                $released++;
            });

        return $released;
    }

    public function release(MarketerConversion $conversion): MarketerConversion
    {
        return DB::connection($conversion->getConnectionName())->transaction(function () use ($conversion): MarketerConversion {
            [, , $net, $bonus] = $this->amounts($conversion);
            $total = $net->plus($bonus);
            $this->ledger->post("conversion:{$conversion->id}:release", 'marketer_commission_release', (new Posting)
                ->debit(AccountRef::marketer($conversion->marketer_profile_id, WalletBucket::Pending), $total)
                ->credit(AccountRef::marketer($conversion->marketer_profile_id, WalletBucket::Spendable), $total), $conversion, 'Commission cleared');
            $this->machine->move($conversion, ConversionStatus::Released, ActorType::System, null, null, ['released_at' => now()]);
            $this->clawbacks->recover($conversion->marketer_profile_id, $conversion->currency);

            return $conversion;
        });
    }

    /**
     * Released → paid (QC-035), oldest first, while the marketer's processed
     * withdrawals in this currency cover them. Returns how many moved.
     */
    public function markPaid(int $marketerProfileId, string $currency): int
    {
        $withdrawn = (string) DB::connection((new MarketerConversion)->getConnectionName())->table('marketer_withdrawals')
            ->where('marketer_profile_id', $marketerProfileId)->where('currency', $currency)->where('status', 'processed')->sum('amount');
        $alreadyPaid = (string) MarketerConversion::query()->where('marketer_profile_id', $marketerProfileId)->where('currency', $currency)
            ->whereNotNull('paid_at')->selectRaw('COALESCE(SUM(net + bonus), 0) as total')->value('total');
        $covered = bcsub($withdrawn, $alreadyPaid, 4);
        $paid = 0;

        foreach (MarketerConversion::query()->where('marketer_profile_id', $marketerProfileId)->where('currency', $currency)
            ->where('status', ConversionStatus::Released)->orderBy('released_at')->orderBy('id')->get() as $conversion) {
            $total = bcadd((string) $conversion->net, (string) $conversion->bonus, 4);

            if (bccomp($total, $covered, 4) > 0) {
                break;
            }

            $this->machine->move($conversion, ConversionStatus::Paid, ActorType::System, null, 'Paid out in a withdrawal', ['paid_at' => now()]);
            $covered = bcsub($covered, $total, 4);
            $paid++;
        }

        return $paid;
    }

    /**
     * Cancel or return of an order item. `$fraction` < 1 (a partial
     * return) only reduces a still-pending conversion; anything already
     * approved is reversed whole.
     */
    public function reverseForOrderItem(int $orderItemId, string $fraction = '1', ?string $reason = null): int
    {
        $count = 0;

        foreach (MarketerConversion::query()->where('source_type', 'order_item')->where('source_id', $orderItemId)->whereIn('status', [ConversionStatus::Pending, ConversionStatus::Approved, ConversionStatus::Released, ConversionStatus::Paid])->get() as $conversion) {
            $this->reverse($conversion, $fraction, $reason);
            $count++;
        }

        return $count;
    }

    public function reverseForSubOrder(SubOrder $subOrder, ?string $reason = null): int
    {
        return $subOrder->items()->pluck('id')->sum(fn (int $itemId): int => $this->reverseForOrderItem($itemId, '1', $reason));
    }

    public function reverse(MarketerConversion $conversion, string $fraction = '1', ?string $reason = null): MarketerConversion
    {
        return DB::connection($conversion->getConnectionName())->transaction(function () use ($conversion, $fraction, $reason): MarketerConversion {
            $campaign = MarketerCampaign::query()->lockForUpdate()->findOrFail($conversion->campaign_id);
            $conversion->refresh();
            $currency = $conversion->currency;
            [$gross, $fee, $net, $bonus] = $this->amounts($conversion);
            $partial = $conversion->status === ConversionStatus::Pending && bccomp($fraction, '1', 4) < 0;

            if ($partial) {
                $keep = bcsub('1', $fraction, 4);
                $newGross = $gross->multipliedBy($keep, RoundingMode::HalfUp);
                $newFee = $fee->multipliedBy($keep, RoundingMode::HalfUp);
                $conversion->forceFill([
                    'gross' => $this->money->toStorage($newGross),
                    'fee' => $this->money->toStorage($newFee),
                    'net' => $this->money->toStorage($newGross->minus($newFee)),
                    'bonus' => $this->money->toStorage($bonus->multipliedBy($keep, RoundingMode::HalfUp)),
                    'reversed_gross' => $this->money->toStorage($this->money->of((string) $conversion->reversed_gross, $currency)->plus($gross->minus($newGross))),
                ])->save();
                $this->freeBudget($campaign, $gross->minus($newGross));

                return $conversion;
            }

            $attributes = ['reversed_at' => now(), 'reversed_gross' => $this->money->toStorage($gross)];
            $to = ConversionStatus::Reversed;

            if ($conversion->status !== ConversionStatus::Pending) {
                $total = $net->plus($bonus);
                $posting = (new Posting)->credit($this->ledger->payer($campaign->vendor_id), $gross)
                    ->credit(AccountRef::platform(LedgerAccountCode::PromoExpense), $bonus)
                    ->debit(AccountRef::platform(LedgerAccountCode::FeeRevenue), $fee);

                if ($conversion->status === ConversionStatus::Approved) {
                    $posting->debit(AccountRef::marketer($conversion->marketer_profile_id, WalletBucket::Pending), $total);
                } else {
                    $spendable = $this->ledger->balance(AccountRef::marketer($conversion->marketer_profile_id, WalletBucket::Spendable), $currency);
                    $take = Money::min($total, Money::max($spendable, $this->money->zero($currency)));
                    $shortfall = $total->minus($take);
                    $posting->debit(AccountRef::marketer($conversion->marketer_profile_id, WalletBucket::Spendable), $take);

                    if ($shortfall->isPositive()) {
                        if ((bool) $this->settings->get('clawback_enabled')) {
                            $posting->debit(AccountRef::marketer($conversion->marketer_profile_id, WalletBucket::Clawback), $shortfall);
                            $to = ConversionStatus::ClawbackPending;
                            $attributes['clawback_amount'] = $this->money->toStorage($shortfall);
                        } else {
                            $posting->debit(AccountRef::platform(LedgerAccountCode::GoodwillExpense), $shortfall);
                        }
                    }
                }

                $this->ledger->post("conversion:{$conversion->id}:reverse", 'marketer_commission_reversal', $posting, $conversion, "Commission reversed {$campaign->number}");

                if ($campaign->vendor_id !== null) {
                    $this->ledger->balanceItem($campaign->vendor_id, VendorBalanceItem::TYPE_MARKETER_REVERSAL, $conversion, "Marketer commission reversed {$campaign->number}", $gross);
                }
            }

            $this->machine->move($conversion, $to, ActorType::System, null, $reason, $attributes);
            $this->freeBudget($campaign, $gross);

            return $conversion;
        });
    }

    /**
     * Referral status re-used by the attribution service.
     */
    public function isAttributable(ReferralStatus $status): bool
    {
        return in_array($status, [ReferralStatus::Active, ReferralStatus::Grace], true);
    }

    /**
     * @return array{0: Money, 1: Money, 2: Money, 3: Money}
     */
    private function amounts(MarketerConversion $conversion): array
    {
        $currency = $conversion->currency;

        return [
            $this->money->of((string) $conversion->gross, $currency),
            $this->money->of((string) $conversion->fee, $currency),
            $this->money->of((string) $conversion->net, $currency),
            $this->money->of((string) $conversion->bonus, $currency),
        ];
    }

    private function freeBudget(MarketerCampaign $campaign, Money $gross): void
    {
        $spent = $this->money->of((string) $campaign->budget_spent, $campaign->currency)->minus($gross);
        $campaign->forceFill(['budget_spent' => $this->money->toStorage(Money::max($spent, $this->money->zero($campaign->currency)))])->save();
    }

    private function hasOpenReturn(SubOrder $subOrder): bool
    {
        return ReturnRequest::query()->where('sub_order_id', $subOrder->id)
            ->whereIn('status', ReturnStatus::openValues())
            ->exists();
    }
}
