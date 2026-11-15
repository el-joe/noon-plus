<?php

namespace App\Domains\Payments\Http\Controllers;

use App\Domains\Catalog\Http\Resources\MoneyResource;
use App\Domains\Classifieds\Models\ClassifiedPackagePurchase;
use App\Domains\GiftCards\Models\GiftCard;
use App\Domains\Membership\Models\MembershipInvoice;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Http\Resources\OrderResource;
use App\Domains\Orders\Models\Order;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Exceptions\PaymentRetryRefused;
use App\Domains\Payments\Http\Resources\PaymentResource;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Support\PaymentAttempts;
use App\Domains\Payments\Support\PaymentStateMachine;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Customer side of a payment: the gateway return/cancel URLs (browser
 * redirects — the result is verified with the gateway, never read from the
 * redirect), status refresh for the 3DS waiting screen (app 98:1882) and a
 * retry with another method after a failure (web 68:797, app 98:1896).
 */
class PaymentController extends Controller
{
    public function __construct(private PaymentAttempts $attempts) {}

    public function return(Request $request, string $reference): RedirectResponse
    {
        $payment = $this->attempts->refresh($this->payment($reference), source: 'return');

        return $this->toStorefront($request, $payment);
    }

    public function cancel(Request $request, string $reference, PaymentStateMachine $machine): RedirectResponse
    {
        $payment = $this->attempts->refresh($this->payment($reference), source: 'cancel');

        if ($payment->status === PaymentStatus::Pending) {
            $machine->transition($payment, PaymentStatus::Failed, ActorType::Customer, $payment->customer_user_id, 'cancel', 'Cancelled on the payment page', [
                'failure_code' => 'cancelled',
                'failure_message' => __('You cancelled the payment.'),
            ]);
        }

        return $this->toStorefront($request, $payment->refresh());
    }

    public function verify(Request $request, string $number): JsonResponse
    {
        $order = $this->customerOrder($request, $number);

        foreach ($order->payments->whereIn('status', [PaymentStatus::Pending, PaymentStatus::Authorized]) as $payment) {
            $this->attempts->refresh($payment, ActorType::Customer, $request->user()->id);
        }

        return ApiResponse::success(new OrderResource($this->customerOrder($request, $number)));
    }

    /**
     * A payment of the signed-in customer (G-W31), e.g. the gift-card or
     * Nawy One return page: the payment and what it pays for.
     */
    public function show(Request $request, string $reference): JsonResponse
    {
        return ApiResponse::success($this->presentPayment($this->customerPayment($request, $reference)));
    }

    /**
     * Mirror of `POST orders/{number}/payments/verify` for non-order
     * payments (G-W31): ask the gateway, apply the result (gift card issued,
     * membership activated, package applied), return the fresh state.
     */
    public function verifyPayment(Request $request, string $reference): JsonResponse
    {
        $payment = $this->customerPayment($request, $reference);

        if (in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Authorized], true)) {
            $this->attempts->refresh($payment, ActorType::Customer, $request->user()->id);
        }

        return ApiResponse::success($this->presentPayment($payment->refresh()));
    }

    public function retry(Request $request, string $number): JsonResponse
    {
        $request->validate(['payment_method' => ['required', 'string', 'max:24']]);
        $order = $this->customerOrder($request, $number);

        try {
            $this->attempts->retry($order, (string) $request->input('payment_method'), $request->user());
        } catch (PaymentRetryRefused $refused) {
            return ApiResponse::error($refused->errorCode, $refused->getMessage(), $refused->errorCode === 'payment_method_unavailable' ? 422 : 409);
        }

        return ApiResponse::success(new OrderResource($this->customerOrder($request, $number)), status: 201);
    }

    private function customerPayment(Request $request, string $reference): Payment
    {
        return Payment::query()->where('reference', $reference)->where('customer_user_id', $request->user()->id)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentPayment(Payment $payment): array
    {
        $purpose = $payment->order_id !== null ? 'order' : (string) $payment->payable_type;
        $subject = match ($purpose) {
            'order' => ($order = $payment->order()->first()) === null ? null : ['number' => $order->number, 'status' => $order->status->value, 'payment_status' => $order->payment_status],
            GiftCard::PAYABLE => ($card = GiftCard::query()->find($payment->payable_id)) === null ? null : [
                'number' => $card->number, 'status' => $card->status->value, 'masked_code' => $card->maskedCode(),
                'amount' => MoneyResource::of((string) $card->amount, $card->currency), 'recipient_email' => $card->recipient_email,
            ],
            MembershipInvoice::PAYABLE => ($invoice = MembershipInvoice::query()->with('membership.plan')->find($payment->payable_id)) === null ? null : [
                'number' => $invoice->number, 'status' => $invoice->status,
                'amount' => MoneyResource::of((string) $invoice->amount, $invoice->currency),
                'membership' => $invoice->membership === null ? null : ['status' => $invoice->membership->status->value, 'plan' => $invoice->membership->plan?->code],
            ],
            ClassifiedPackagePurchase::PAYABLE => ($purchase = ClassifiedPackagePurchase::query()->find($payment->payable_id)) === null ? null : [
                'number' => $purchase->number, 'status' => $purchase->status, 'amount' => MoneyResource::of((string) $purchase->amount, $purchase->currency),
                'applied_until' => $purchase->applied_until === null ? null : (string) $purchase->applied_until,
            ],
            default => null,
        };

        return ['payment' => (new PaymentResource($payment))->resolve(), 'purpose' => $purpose, 'subject' => $subject];
    }

    private function payment(string $reference): Payment
    {
        return Payment::query()->where('reference', $reference)->firstOrFail();
    }

    private function customerOrder(Request $request, string $number): Order
    {
        return Order::query()
            ->where('number', $number)
            ->where('customer_user_id', $request->user()->id)
            ->with('subOrders.vendor', 'subOrders.items', 'payments')
            ->firstOrFail();
    }

    /**
     * Back to the storefront result page; it reads the order from the API.
     */
    private function toStorefront(Request $request, Payment $payment): RedirectResponse
    {
        $base = rtrim((string) config('nawy.payments.storefront_url'), '/').'/'.$request->attributes->get('market');

        if ($payment->order_id === null) {
            $query = http_build_query(['payment' => $payment->reference, 'kind' => (string) $payment->payable_type, 'status' => $payment->status->value]);

            return redirect()->away($base.'/checkout/payment?'.$query);
        }

        $order = $payment->order()->firstOrFail();
        $query = http_build_query(['order' => $order->number, 'payment' => $payment->reference, 'status' => $payment->status->value]);

        return redirect()->away($base.'/checkout/payment?'.$query);
    }
}
