<?php

namespace App\Domains\Payments\Support;

use App\Domains\Orders\Enums\ActorType;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Exceptions\PaymentTransitionRefused;
use App\Domains\Payments\Models\Payment;
use App\Shared\Notifications\Email\Listeners\CommerceLifecycleEmails;
use Illuminate\Support\Facades\DB;

/**
 * The only way a payment changes status (lifecycle catalogue appendix A).
 * The row is re-read under a lock, so two notifications arriving together
 * cannot both apply; backward moves are refused (risk T9). Every change
 * writes a history row with actor and source and re-derives the order
 * payment status. A capture is booked in the ledger in the same
 * transaction ({@see PaymentCaptured}).
 */
class PaymentStateMachine
{
    public function __construct(private OrderPaymentStatus $orderPaymentStatus) {}

    /**
     * @param  array<string, mixed>  $attributes  extra columns to set with the move (amounts, timestamps, failure)
     */
    public function transition(
        Payment $payment,
        PaymentStatus $to,
        ActorType $actorType,
        ?int $actorUserId = null,
        ?string $source = null,
        ?string $reason = null,
        array $attributes = [],
    ): Payment {
        return DB::connection($payment->getConnectionName())->transaction(function () use ($payment, $to, $actorType, $actorUserId, $source, $reason, $attributes): Payment {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $from = $locked->status;

            if (! in_array($to, $from->allowedNext(), true)) {
                throw new PaymentTransitionRefused("A payment cannot move from {$from->value} to {$to->value}.");
            }

            $locked->forceFill(['status' => $to] + $attributes + match ($to) {
                PaymentStatus::Authorized => ['authorized_at' => now()],
                PaymentStatus::Captured => ['captured_at' => now()],
                default => [],
            })->save();

            $locked->statusHistory()->create([
                'from_status' => $from,
                'to_status' => $to,
                'actor_type' => $actorType,
                'actor_user_id' => $actorUserId,
                'source' => $source,
                'reason' => $reason === null ? null : mb_substr($reason, 0, 255),
            ]);

            if ($locked->order_id !== null) {
                $this->orderPaymentStatus->sync($locked->order()->firstOrFail());
            }

            if ($to === PaymentStatus::Captured) {
                app(PaymentCaptured::class)->handle($locked);
            }

            // Receipt, failure, review and invoice emails (sent once the move commits).
            app(CommerceLifecycleEmails::class)->paymentMoved($locked, $from, $to);

            $payment->setRawAttributes($locked->getAttributes(), true);

            return $payment;
        });
    }
}
