<?php

namespace App\Domains\Refunds\Support;

use App\Domains\Campaigns\Support\Conversions;
use App\Domains\Finance\Enums\LedgerAccountCode;
use App\Domains\Finance\Enums\WalletBucket;
use App\Domains\Finance\Models\CodCollection;
use App\Domains\Finance\Models\SubOrderSettlement;
use App\Domains\Finance\Models\VendorBalanceItem;
use App\Domains\Finance\Support\AccountRef;
use App\Domains\Finance\Support\FinanceSettings;
use App\Domains\Finance\Support\Ledger;
use App\Domains\Finance\Support\Posting;
use App\Domains\Finance\Support\Wallets;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\Models\SubOrder;
use App\Domains\Payments\Enums\PaymentMethod;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Exceptions\RefundRefused;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Support\PaymentCaptured;
use App\Domains\Payments\Support\PaymentRefunds;
use App\Domains\Refunds\Enums\RefundDestination;
use App\Domains\Refunds\Enums\RefundSource;
use App\Domains\Refunds\Enums\RefundStatus;
use App\Domains\Refunds\Models\Refund;
use App\Domains\Returns\Models\ReturnItem;
use App\Shared\Money\MoneyFactory;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\TransitionRefused;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * The refund machine (SRS §7.5, lifecycle catalogue §2.6) and its money.
 *
 * - A refund is at most what the customer paid for the order minus every
 *   refund not rejected (checked under an order row lock); the idempotency
 *   key (e.g. `return:12`) makes a second request return the first refund,
 *   so nothing is ever refunded twice.
 * - Processing: card/BNPL to the original method goes through
 *   {@see PaymentRefunds} (gateway, idempotent per refund); COD, Nawy
 *   Credits and refunds the customer chose as credits are booked to the
 *   wallet at once (promotional credits come back as promotional).
 * - Ledger: Dr order liability (or, once the sub-order is settled, the
 *   vendor payable — a clawback on the next statement — or the warranty
 *   claims expense for extended plans) / Cr provider clearing or wallet.
 */
class Refunds
{
    public function __construct(
        private StateMachine $machine,
        private PaymentRefunds $paymentRefunds,
        private Ledger $ledger,
        private Wallets $wallets,
        private MoneyFactory $money,
        private FinanceSettings $settings,
    ) {}

    public function request(
        Order $order,
        ?SubOrder $subOrder,
        Money $amount,
        RefundSource $source,
        ?int $sourceId,
        RefundDestination $destination,
        string $idempotencyKey,
        ActorType $actorType,
        ?int $actorUserId = null,
        ?string $reason = null,
        ?Money $warrantyAmount = null,
    ): Refund {
        return DB::connection($order->getConnectionName())->transaction(function () use ($order, $subOrder, $amount, $source, $sourceId, $destination, $idempotencyKey, $actorType, $actorUserId, $reason, $warrantyAmount): Refund {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            $existing = Refund::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return $existing;
            }

            if ($amount->getCurrency()->getCurrencyCode() !== $locked->currency || ! $amount->isPositive()) {
                throw new TransitionRefused(__('The refund amount must be positive and in :currency.', ['currency' => $locked->currency]));
            }

            $refundable = $this->refundable($locked);

            if ($amount->isGreaterThan($refundable)) {
                throw new TransitionRefused(__('At most :amount :currency can still be refunded on this order.', ['amount' => (string) $refundable->getAmount(), 'currency' => $locked->currency]));
            }

            $refund = Refund::query()->create([
                'number' => 'PENDING-'.uniqid(),
                'order_id' => $locked->id,
                'sub_order_id' => $subOrder?->id,
                'customer_user_id' => $locked->customer_user_id,
                'source_type' => $source,
                'source_id' => $sourceId,
                'currency' => $locked->currency,
                'amount' => $this->money->toStorage($amount),
                'warranty_amount' => $this->money->toStorage($warrantyAmount ?? $this->money->zero($locked->currency)),
                'destination' => $this->effectiveDestination($locked, $destination),
                'status' => RefundStatus::Pending,
                'idempotency_key' => $idempotencyKey,
                'reason' => $reason === null ? null : mb_substr($reason, 0, 255),
            ]);
            $refund->forceFill(['number' => 'RF-'.$locked->number.'-'.$refund->id])->save();
            $this->machine->created($refund, $actorType, $actorUserId, $reason);

            return $refund;
        });
    }

    /**
     * Request, approve (system auto rule) and process in one go — used by
     * inspected returns, cancellations and resolved disputes.
     */
    public function issue(
        Order $order,
        ?SubOrder $subOrder,
        Money $amount,
        RefundSource $source,
        ?int $sourceId,
        RefundDestination $destination,
        string $idempotencyKey,
        ActorType $actorType,
        ?int $actorUserId = null,
        ?string $reason = null,
        ?Money $warrantyAmount = null,
    ): Refund {
        $refund = $this->request($order, $subOrder, $amount, $source, $sourceId, $destination, $idempotencyKey, $actorType, $actorUserId, $reason, $warrantyAmount);

        if ($refund->status === RefundStatus::Pending) {
            $this->approve($refund, ActorType::System, null, 'Auto-approved ('.$source->value.')');
        }

        return in_array($refund->status, [RefundStatus::Approved, RefundStatus::Failed], true)
            ? $this->process($refund, $actorType, $actorUserId)
            : $refund;
    }

    public function approve(Refund $refund, ActorType $actorType, ?int $actorUserId = null, ?string $reason = null): Refund
    {
        return $this->machine->move($refund, RefundStatus::Approved, $actorType, $actorUserId, $reason);
    }

    public function reject(Refund $refund, ActorType $actorType, ?int $actorUserId, string $reason): Refund
    {
        return $this->machine->move($refund, RefundStatus::Rejected, $actorType, $actorUserId, $reason);
    }

    /**
     * approved | failed → processing → completed | failed.
     */
    public function process(Refund $refund, ActorType $actorType = ActorType::System, ?int $actorUserId = null): Refund
    {
        $this->machine->move($refund, RefundStatus::Processing, $actorType, $actorUserId);
        $order = $refund->order()->firstOrFail();
        $payment = $this->settledPayment($order);
        $amount = $this->money->of((string) $refund->amount, $refund->currency);
        $paymentRefundId = null;

        $attempt = $refund->transitions()->where('to_status', RefundStatus::Processing->value)->count();

        try {
            if ($payment !== null && ($refund->destination === RefundDestination::Original || ! $payment->method->isGateway())) {
                $paymentRefundId = $this->paymentRefunds->refund($payment, $amount, "refund:{$refund->id}:{$attempt}", ActorType::System, null, $refund->reason)->id;
            }
        } catch (RefundRefused $refused) {
            return $this->machine->move($refund, RefundStatus::Failed, ActorType::System, null, $refused->getMessage(), ['failure_message' => mb_substr($refused->getMessage(), 0, 255)]);
        }

        return DB::connection($refund->getConnectionName())->transaction(function () use ($refund, $order, $payment, $amount, $paymentRefundId): Refund {
            $payment?->refresh();
            $settlement = $refund->sub_order_id === null ? null : SubOrderSettlement::query()->where('sub_order_id', $refund->sub_order_id)->first();
            $debit = match (true) {
                $settlement === null => AccountRef::platform(LedgerAccountCode::OrderLiability),
                $refund->source_type === RefundSource::WarrantyClaim => AccountRef::platform(LedgerAccountCode::WarrantyClaimsExpense),
                default => AccountRef::vendor($settlement->vendor_id),
            };

            $posting = (new Posting)->debit($debit, $amount);

            if ($refund->destination === RefundDestination::Original && $payment !== null && $payment->method->isGateway()) {
                $posting->credit(app(PaymentCaptured::class)->clearingAccount($payment->method), $amount);
            } else {
                $promotional = $this->promotionalShare($payment, $amount);
                $posting->credit(AccountRef::customerWallet($order->customer_user_id, WalletBucket::Spendable), $amount->minus($promotional));

                if ($promotional->isPositive()) {
                    $this->wallets->grantPromotional($order->customer_user_id, $promotional, "refund:{$refund->id}:promotional", 'refund', counter: $debit);
                    $posting = (new Posting)->debit($debit, $amount->minus($promotional))->credit(AccountRef::customerWallet($order->customer_user_id), $amount->minus($promotional));
                }
            }

            if (count($posting->legs()) > 0) {
                $this->ledger->post("refund:{$refund->id}", 'refund', $posting, reference: $refund, description: "Refund {$refund->number}");
            }

            if ($settlement !== null && $refund->source_type !== RefundSource::WarrantyClaim) {
                VendorBalanceItem::query()->firstOrCreate(
                    ['type' => VendorBalanceItem::TYPE_CLAWBACK, 'reference_type' => 'refund', 'reference_id' => $refund->id],
                    ['vendor_id' => $settlement->vendor_id, 'description' => "Refund {$refund->number} after settlement", 'amount' => $this->money->toStorage($amount->negated()), 'is_eligible' => true],
                );
            }

            if ($refund->source_type === RefundSource::Return && $refund->source_id !== null) {
                $this->reverseMarketerConversions((int) $refund->source_id);
            }

            return $this->machine->move($refund, RefundStatus::Completed, ActorType::System, null, null, [
                'payment_refund_id' => $paymentRefundId,
                'after_settlement' => $settlement !== null,
                'processed_at' => now(),
                'failure_message' => null,
            ]);
        });
    }

    /**
     * Returned units take the marketer commission back (WP5, FR-ORD-4):
     * the share of each line that came back.
     */
    private function reverseMarketerConversions(int $returnRequestId): void
    {
        foreach (ReturnItem::query()->where('return_request_id', $returnRequestId)->with('orderItem:id,quantity')->get() as $returnItem) {
            $quantity = max(1, (int) $returnItem->orderItem?->quantity);
            $fraction = bcdiv((string) min($returnItem->quantity, $quantity), (string) $quantity, 4);
            app(Conversions::class)->reverseForOrderItem((int) $returnItem->order_item_id, $fraction, 'Returned');
        }
    }

    /**
     * What the customer paid for the order and has not been refunded yet
     * (refunds not rejected count, so a failed one keeps its share for a retry).
     */
    public function refundable(Order $order): Money
    {
        $reserved = Refund::query()->where('order_id', $order->id)->where('status', '!=', RefundStatus::Rejected)->sum('amount');

        return Money::max($this->money->zero($order->currency), $this->paid($order)->minus($this->money->of((string) $reserved, $order->currency)));
    }

    /**
     * Captured amount of the settled payment, or the COD cash collected so far.
     */
    public function paid(Order $order): Money
    {
        $payment = $this->settledPayment($order);

        if ($payment !== null) {
            return $this->money->of((string) $payment->captured_amount, $order->currency);
        }

        if ($order->payment_method === PaymentMethod::Cod->value) {
            return $this->money->of((string) CodCollection::query()->where('order_id', $order->id)->sum('amount'), $order->currency);
        }

        return $this->money->zero($order->currency);
    }

    private function settledPayment(Order $order): ?Payment
    {
        return $order->payments()->whereIn('status', [PaymentStatus::Captured, PaymentStatus::PartiallyRefunded, PaymentStatus::Refunded])->latest('id')->first();
    }

    /**
     * COD (setting `refund.cod_to_wallet`) and Nawy Credits payments are
     * refunded as credits whatever the customer asked.
     */
    private function effectiveDestination(Order $order, RefundDestination $requested): RefundDestination
    {
        if ($order->payment_method === PaymentMethod::Wallet->value) {
            return RefundDestination::Original;
        }

        if ($order->payment_method === PaymentMethod::Cod->value && (bool) $this->settings->get('refund.cod_to_wallet', 'refund_cod_to_wallet')) {
            return RefundDestination::Wallet;
        }

        return $requested;
    }

    /**
     * For a payment made with Nawy Credits: the part of this refund that was
     * paid with promotional credits (refunds give spendable credits back first).
     */
    private function promotionalShare(?Payment $payment, Money $amount): Money
    {
        $zero = $this->money->zero($amount->getCurrency()->getCurrencyCode());

        if ($payment === null || $payment->method !== PaymentMethod::Wallet || ! isset($payment->client_data['wallet'])) {
            return $zero;
        }

        $currency = $payment->currency;
        $paidSpendable = $this->money->of((string) $payment->client_data['wallet']['spendable'], $currency);
        $alreadyRefunded = $this->money->of((string) $payment->refunded_amount, $currency)->minus($amount);
        $spendableLeft = Money::max($zero, $paidSpendable->minus(Money::max($zero, $alreadyRefunded)));

        return Money::max($zero, $amount->minus(Money::min($amount, $spendableLeft)));
    }
}
