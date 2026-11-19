<?php

namespace App\Domains\Payments\Support;

use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Models\Order;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Enums\RefundStatus;
use App\Domains\Payments\Exceptions\GatewayError;
use App\Domains\Payments\Exceptions\PaymentMethodUnavailable;
use App\Domains\Payments\Exceptions\RefundRefused;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Models\PaymentRefund;
use App\Shared\Money\MoneyFactory;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * Refunds of captured payments (SRS §7.5) — the API the returns, cancel and
 * dispute flows call (WP4). Full or partial, never more than captured minus
 * refunded (pending refunds count), idempotent per key: calling again with
 * the same key returns the first refund and never calls the gateway twice.
 * The payment moves to partially_refunded / refunded and the order payment
 * status follows.
 */
class PaymentRefunds
{
    public function __construct(
        private PaymentAttempts $attempts,
        private PaymentStateMachine $machine,
        private MoneyFactory $money,
    ) {}

    /**
     * Refund part of the order's settled payment.
     */
    public function refundOrder(Order $order, Money $amount, string $idempotencyKey, ActorType $actorType, ?int $actorUserId = null, ?string $reason = null): PaymentRefund
    {
        $payment = $order->payments()->whereIn('status', [PaymentStatus::Captured, PaymentStatus::PartiallyRefunded, PaymentStatus::Refunded])->latest('id')->first()
            ?? throw new RefundRefused(__('This order has no captured payment to refund.'));

        return $this->refund($payment, $amount, $idempotencyKey, $actorType, $actorUserId, $reason);
    }

    public function refund(Payment $payment, Money $amount, string $idempotencyKey, ActorType $actorType, ?int $actorUserId = null, ?string $reason = null): PaymentRefund
    {
        $connection = DB::connection($payment->getConnectionName());

        /** @var PaymentRefund $refund */
        $refund = $connection->transaction(function () use ($payment, $amount, $idempotencyKey, $actorType, $actorUserId, $reason): PaymentRefund {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $existing = PaymentRefund::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                if ($existing->payment_id !== $locked->id || ! $this->money->of((string) $existing->amount, $locked->currency)->isEqualTo($amount)) {
                    throw new RefundRefused(__('This refund key was already used for a different refund.'));
                }

                return $existing;
            }

            if (! in_array($locked->status, [PaymentStatus::Captured, PaymentStatus::PartiallyRefunded], true)) {
                throw new RefundRefused(__('Only captured payments can be refunded.'));
            }

            if ($amount->getCurrency()->getCurrencyCode() !== $locked->currency || ! $amount->isPositive()) {
                throw new RefundRefused(__('The refund amount must be positive and in :currency.', ['currency' => $locked->currency]));
            }

            $reserved = $this->money->of((string) $locked->refunded_amount, $locked->currency)->plus($this->pending($locked));
            $refundable = $this->money->of((string) $locked->captured_amount, $locked->currency)->minus($reserved);

            if ($amount->isGreaterThan($refundable)) {
                throw new RefundRefused(__('At most :amount :currency can still be refunded.', ['amount' => (string) $refundable->getAmount(), 'currency' => $locked->currency]));
            }

            // PayPal USD conversion: the charge-currency amount sent to the provider, from the rate stored on the payment.
            $charge = app(PaymentCurrencyConversion::class)->refundCharge($locked, $amount);

            return $locked->refunds()->create([
                'idempotency_key' => $idempotencyKey,
                'amount' => $this->money->toStorage($amount),
                'charge_amount' => $charge === null ? null : $this->money->toStorage($charge),
                'status' => RefundStatus::Pending,
                'reason' => $reason === null ? null : mb_substr($reason, 0, 255),
                'actor_type' => $actorType,
                'actor_user_id' => $actorUserId,
            ]);
        });

        if ($refund->status !== RefundStatus::Pending) {
            return $refund;
        }

        try {
            $result = $this->attempts->gateway($payment)->refund($payment->refresh(), $amount, $idempotencyKey, $reason);
        } catch (GatewayError|PaymentMethodUnavailable $error) {
            $refund->forceFill(['status' => RefundStatus::Failed, 'failure_message' => mb_substr($error->getMessage(), 0, 255)])->save();

            throw new RefundRefused($error->getMessage(), previous: $error);
        }

        return $connection->transaction(function () use ($payment, $refund, $result, $actorType, $actorUserId, $reason): PaymentRefund {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $refund->forceFill(['status' => RefundStatus::Completed, 'gateway_reference' => $result->refundReference, 'completed_at' => now()])->save();

            $refunded = $this->money->of((string) $locked->refunded_amount, $locked->currency)->plus($this->money->of((string) $refund->amount, $locked->currency));
            $isFull = $refunded->isGreaterThanOrEqualTo($this->money->of((string) $locked->captured_amount, $locked->currency));

            $this->machine->transition($locked, $isFull ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded, $actorType, $actorUserId, 'refund', $reason, [
                'refunded_amount' => $this->money->toStorage($refunded),
            ]);

            return $refund->refresh();
        });
    }

    private function pending(Payment $payment): Money
    {
        $sum = $payment->refunds()->where('status', RefundStatus::Pending)->sum('amount');

        return $this->money->of((string) $sum, $payment->currency);
    }
}
