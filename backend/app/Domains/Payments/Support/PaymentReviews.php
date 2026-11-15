<?php

namespace App\Domains\Payments\Support;

use App\Domains\Orders\Enums\ActorType;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Enums\RefundStatus;
use App\Domains\Payments\Exceptions\GatewayError;
use App\Domains\Payments\Exceptions\PaymentMethodUnavailable;
use App\Domains\Payments\Models\Payment;
use App\Shared\Money\MoneyFactory;
use App\Shared\StateMachine\TransitionRefused;

/**
 * Payments held `under_review` (QA-009: the gateway captured another amount
 * than the attempt). An admin resolves each one through the payment state
 * machine after reconciliation:
 *
 * - accept: the captured amount stands (e.g. a partial capture) → captured;
 * - mark_paid: reconciliation shows the full amount arrived → captured for
 *   the attempt amount (a reference is required);
 * - refund: the captured money goes back through the gateway → refunded;
 * - fail: nothing usable arrived → failed.
 *
 * A capture books the ledger as usual ({@see PaymentCaptured}); a refund
 * from review books nothing because nothing was booked while held.
 */
class PaymentReviews
{
    public const ACTIONS = ['accept', 'mark_paid', 'refund', 'fail'];

    public function __construct(private PaymentStateMachine $machine, private PaymentAttempts $attempts, private MoneyFactory $money) {}

    public function resolve(Payment $payment, string $action, int $adminUserId, string $note): Payment
    {
        if ($payment->status !== PaymentStatus::UnderReview) {
            throw new TransitionRefused(__('This payment is not under review.'));
        }

        $reason = mb_substr(match ($action) {
            'accept' => 'Accepted captured amount: ',
            'mark_paid' => 'Marked paid after reconciliation: ',
            'refund' => 'Refunded from review: ',
            default => 'Failed after review: ',
        }.$note, 0, 255);

        return match ($action) {
            'accept' => $this->machine->transition($payment, PaymentStatus::Captured, ActorType::Admin, $adminUserId, 'admin_review', $reason),
            'mark_paid' => $this->machine->transition($payment, PaymentStatus::Captured, ActorType::Admin, $adminUserId, 'admin_review', $reason, [
                'captured_amount' => $payment->amount,
                'failure_code' => null,
                'failure_message' => null,
            ]),
            'refund' => $this->refund($payment, $adminUserId, $reason),
            'fail' => $this->machine->transition($payment, PaymentStatus::Failed, ActorType::Admin, $adminUserId, 'admin_review', $reason, [
                'failure_code' => 'admin_review',
                'failure_message' => mb_substr($note, 0, 255),
            ]),
            default => throw new TransitionRefused(__('Unknown review action.')),
        };
    }

    private function refund(Payment $payment, int $adminUserId, string $reason): Payment
    {
        $amount = $this->money->of((string) ($payment->captured_amount ?? '0'), $payment->currency);

        if (! $amount->isPositive()) {
            throw new TransitionRefused(__('Nothing was captured, so there is nothing to refund. Fail the payment instead.'));
        }

        $key = "review:{$payment->id}";
        $refund = $payment->refunds()->firstOrCreate(['idempotency_key' => $key], [
            'amount' => $this->money->toStorage($amount),
            'status' => RefundStatus::Pending,
            'reason' => $reason,
            'actor_type' => ActorType::Admin,
            'actor_user_id' => $adminUserId,
        ]);

        if ($refund->status !== RefundStatus::Completed) {
            try {
                $result = $this->attempts->gateway($payment)->refund($payment, $amount, $key, $reason);
            } catch (GatewayError|PaymentMethodUnavailable $error) {
                $refund->forceFill(['status' => RefundStatus::Failed, 'failure_message' => mb_substr($error->getMessage(), 0, 255)])->save();

                throw new TransitionRefused(__('The gateway refused the refund: :message', ['message' => $error->getMessage()]));
            }

            $refund->forceFill(['status' => RefundStatus::Completed, 'gateway_reference' => $result->refundReference, 'completed_at' => now(), 'failure_message' => null])->save();
        }

        return $this->machine->transition($payment, PaymentStatus::Refunded, ActorType::Admin, $adminUserId, 'admin_review', $reason, [
            'refunded_amount' => $this->money->toStorage($amount),
        ]);
    }
}
