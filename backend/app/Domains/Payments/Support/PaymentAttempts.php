<?php

namespace App\Domains\Payments\Support;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\OrderStatus;
use App\Domains\Orders\Models\Order;
use App\Domains\Payments\Enums\PaymentMethod;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Exceptions\GatewayError;
use App\Domains\Payments\Exceptions\PaymentMethodUnavailable;
use App\Domains\Payments\Exceptions\PaymentRetryRefused;
use App\Domains\Payments\Exceptions\PaymentTransitionRefused;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Models\SavedPaymentCard;
use App\Domains\Payments\Support\Data\GatewayOutcome;
use App\Domains\Payments\Support\Data\PaymentRequest;
use App\Domains\Payments\Support\Gateways\ChargesSavedCards;
use App\Domains\Payments\Support\Gateways\ConvertedCurrencyGateway;
use App\Domains\Payments\Support\Gateways\GatewayFactory;
use App\Domains\Payments\Support\Gateways\PaymentGateway;
use App\Domains\Settings\SettingsService;
use App\Models\User;
use App\Shared\Money\MoneyFactory;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Payment attempts of an order (ADR 6, FR-CHK-3).
 *
 * `start` records a pending attempt and asks the gateway for the next
 * action (redirect / 3DS / client data); a gateway error fails the attempt
 * but keeps the order on hold, so the customer can `retry` with another
 * method until `orders.payment_due_at`. Results are only taken from a
 * server-side `refresh` (verify) or a signed webhook, both through
 * {@see applyOutcome()}; an authorisation is captured at once.
 */
class PaymentAttempts
{
    public function __construct(
        private CountryContext $countryContext,
        private PaymentMethodCatalog $catalog,
        private GatewayFactory $gateways,
        private PaymentStateMachine $machine,
        private OrderPaymentStatus $orderPaymentStatus,
        private SettingsService $settings,
        private MoneyFactory $money,
        private PaymentCurrencyConversion $conversion,
    ) {}

    public function start(Order $order, PaymentMethod $method, User $customer): Payment
    {
        $country = $this->countryContext->current();
        $config = $method->isGateway() ? $this->catalog->config($country, $method) : null;
        $attempt = $order->payments()->count() + 1;

        $payment = DB::connection($order->getConnectionName())->transaction(function () use ($order, $method, $config, $attempt, $customer): Payment {
            $payment = $order->payments()->create([
                'reference' => (string) Str::uuid(),
                'customer_user_id' => $customer->id,
                'method' => $method,
                'mode' => $config?->mode->value,
                'currency' => $order->currency,
                'amount' => $order->grand_total,
                'idempotency_key' => "order:{$order->id}:attempt:{$attempt}",
                'expires_at' => $method === PaymentMethod::Cod ? null : $order->payment_due_at,
            ]);
            $payment->statusHistory()->create(['to_status' => PaymentStatus::Pending, 'actor_type' => ActorType::Customer, 'actor_user_id' => $customer->id, 'source' => 'checkout']);
            $order->forceFill(['payment_method' => $method->value])->save();
            $this->orderPaymentStatus->sync($order);

            return $payment;
        });

        return $this->begin($payment, $this->paymentRequest($payment, $customer, $order->number, __('Nawy order :number', ['number' => $order->number]), (array) $order->shipping_address), $customer);
    }

    /**
     * A payment that is not for an order (gift card purchase, membership
     * invoice — WP4). Card gateways and Nawy Credits only; the payable is
     * told when the payment is captured ({@see PaymentCaptured}).
     */
    public function startForPayable(string $payableType, int $payableId, string $number, Money $amount, PaymentMethod $method, User $customer, string $description, ?string $returnUrl = null): Payment
    {
        if ($method === PaymentMethod::Cod || $method === PaymentMethod::GiftCard) {
            throw new PaymentMethodUnavailable(__('This payment method is not available here.'));
        }

        $payment = $this->newPayablePayment($payableType, $payableId, $amount, $method, $customer, ActorType::Customer, $payableType, $returnUrl);

        return $this->begin($payment, $this->paymentRequest($payment, $customer, $number, $description), $customer);
    }

    /**
     * Merchant-initiated charge of a saved card (gateway token only) for a
     * payable, e.g. a vendor plan renewal invoice. The provider's answer is
     * applied at once through {@see applyOutcome()} (captured → the payable
     * is told; declined → the attempt fails). A gateway that cannot charge
     * saved cards, a disabled gateway or an expired card fail the attempt
     * without calling the provider.
     */
    public function chargeSavedCard(string $payableType, int $payableId, string $number, Money $amount, SavedPaymentCard $card, User $customer, string $description): Payment
    {
        $method = PaymentMethod::tryFrom($card->gateway) ?? throw new PaymentMethodUnavailable(__('This saved card cannot be charged.'));
        $payment = $this->newPayablePayment($payableType, $payableId, $amount, $method, $customer, ActorType::System, 'auto_charge');
        $fail = function (string $code, string $message) use ($payment): Payment {
            $this->machine->transition($payment, PaymentStatus::Failed, ActorType::System, source: 'auto_charge', reason: $message, attributes: ['failure_code' => $code, 'failure_message' => $message]);

            return $payment->refresh();
        };

        if ($card->isExpired()) {
            return $fail('card_expired', __('The saved card has expired.'));
        }

        try {
            $config = $this->catalog->config($this->countryContext->current(), $method);

            if ($config === null || ! $config->is_enabled) {
                throw new PaymentMethodUnavailable(__(':method is not configured for this country.', ['method' => $method->label()]));
            }

            $this->conversion->apply($payment);
            $gateway = $this->gateway($payment);

            if (! $gateway instanceof ChargesSavedCards) {
                return $fail('not_supported', __(':method cannot charge saved cards.', ['method' => $method->label()]));
            }

            $outcome = $gateway->chargeSavedCard($this->forGateway($this->paymentRequest($payment, $customer, $number, $description), $payment), $card);
        } catch (GatewayError|PaymentMethodUnavailable $error) {
            Log::warning('Saved card charge failed', ['payment' => $payment->reference, 'method' => $method->value, 'error' => $error->getMessage()]);

            return $fail('gateway_unavailable', __('The card could not be charged right now.'));
        }

        $payment->forceFill(['gateway_reference' => $outcome->gatewayReference])->save();
        $card->forceFill(['last_used_at' => now()])->save();

        return $this->applyOutcome($payment, $outcome, 'auto_charge');
    }

    private function newPayablePayment(string $payableType, int $payableId, Money $amount, PaymentMethod $method, User $customer, ActorType $actorType, string $source, ?string $returnUrl = null): Payment
    {
        $config = $method->isGateway() ? $this->catalog->config($this->countryContext->current(), $method) : null;
        $payment = Payment::query()->create([
            'reference' => (string) Str::uuid(),
            'payable_type' => $payableType,
            'payable_id' => $payableId,
            'customer_user_id' => $customer->id,
            'method' => $method,
            'mode' => $config?->mode->value,
            'currency' => $amount->getCurrency()->getCurrencyCode(),
            'amount' => $this->money->toStorage($amount),
            'idempotency_key' => "{$payableType}:{$payableId}:".Str::uuid(),
            'return_url' => $returnUrl,
            'expires_at' => now()->addMinutes((int) $this->settings->get('payment.pending_expiry_minutes', config('nawy.payments.pending_expiry_minutes'))),
        ]);
        $payment->statusHistory()->create(['to_status' => PaymentStatus::Pending, 'actor_type' => $actorType, 'actor_user_id' => $actorType === ActorType::System ? null : $customer->id, 'source' => $source]);

        return $payment;
    }

    /**
     * Ask the provider for the next action; Nawy Credits are taken and
     * captured at once.
     */
    private function begin(Payment $payment, PaymentRequest $request, User $customer): Payment
    {
        $method = $payment->method;

        try {
            // PayPal USD conversion: the provider is asked for the converted amount at the current rate (stored on the payment).
            $this->conversion->apply($payment);
            $request = $this->forGateway($request, $payment);
            $session = $this->gateway($payment)->createPayment($request);
            $payment->forceFill([
                'gateway_reference' => $session->gatewayReference,
                'redirect_url' => $session->redirectUrl,
                'client_data' => $session->clientData === [] ? null : $session->clientData,
            ])->save();
        } catch (GatewayError|PaymentMethodUnavailable $error) {
            Log::warning('Payment could not be started', ['payment' => $payment->reference, 'method' => $method->value, 'error' => $error->getMessage()]);
            $this->machine->transition($payment, PaymentStatus::Failed, ActorType::System, source: 'checkout', reason: $error->getMessage(), attributes: [
                'failure_code' => 'gateway_unavailable',
                'failure_message' => $method === PaymentMethod::Wallet ? $error->getMessage() : __('This payment method is not available right now. Try another one.'),
            ]);

            return $payment->refresh();
        }

        if ($method === PaymentMethod::Wallet) {
            return $this->refresh($payment->refresh(), ActorType::Customer, $customer->id, 'wallet');
        }

        return $payment->refresh();
    }

    /**
     * A new attempt with another (or the same) method while the order is on
     * hold. Pending attempts are verified first and then superseded, so an
     * order is never paid twice.
     */
    public function retry(Order $order, string $methodCode, User $customer): Payment
    {
        $country = $this->countryContext->current();
        $maxAttempts = (int) $this->settings->get('payment.retry_max_attempts', config('nawy.payments.retry_max_attempts'));

        if ($order->status === OrderStatus::Cancelled || $order->payment_due_at === null || $order->payment_due_at->isPast()) {
            throw new PaymentRetryRefused('payment_window_closed', __('The time to pay for this order is over.'));
        }

        foreach ($order->payments()->where('status', PaymentStatus::Pending)->get() as $pending) {
            $this->refresh($pending, ActorType::Customer, $customer->id, 'retry');
        }

        if (PaymentStatus::from($this->orderPaymentStatus->derive($order))->isSuccessful()) {
            throw new PaymentRetryRefused('already_paid', __('This order is already paid.'));
        }

        if ($order->payments()->count() >= $maxAttempts) {
            throw new PaymentRetryRefused('too_many_attempts', __('Too many payment attempts for this order.'));
        }

        if ($methodCode === PaymentMethod::Cod->value) {
            throw new PaymentRetryRefused('method_not_allowed', __('Cash on delivery cannot be chosen after the order is placed.'));
        }

        $option = $this->catalog->find($country, $methodCode, $this->money->of((string) $order->grand_total, $order->currency), $customer->id)
            ?? throw new PaymentRetryRefused('payment_method_unavailable', __('This payment method is not available for this order.'));

        foreach ($order->payments()->where('status', PaymentStatus::Pending)->get() as $pending) {
            $this->machine->transition($pending, PaymentStatus::Failed, ActorType::Customer, $customer->id, 'retry', 'Superseded by a new attempt', ['failure_code' => 'superseded']);
        }

        return $this->start($order, $option['method'], $customer);
    }

    /**
     * Ask the gateway for the state of a pending (or authorised) attempt and
     * apply it. Gateway errors leave the attempt unchanged.
     */
    public function refresh(Payment $payment, ActorType $actorType = ActorType::System, ?int $actorUserId = null, string $source = 'verify'): Payment
    {
        if (! in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Authorized], true) || $payment->gateway_reference === null && $payment->method->isGateway()) {
            return $payment;
        }

        try {
            $outcome = $this->gateway($payment)->verify($payment);
        } catch (GatewayError|PaymentMethodUnavailable $error) {
            Log::warning('Payment verification failed', ['payment' => $payment->reference, 'error' => $error->getMessage()]);

            return $payment;
        }

        return $this->applyOutcome($payment, $outcome, $source, $actorType, $actorUserId);
    }

    /**
     * Apply what the provider reported. Same or backward states are ignored
     * (duplicates, out-of-order notifications); an authorisation is captured
     * right away for the full amount.
     */
    public function applyOutcome(Payment $payment, GatewayOutcome $outcome, string $source, ActorType $actorType = ActorType::System, ?int $actorUserId = null): Payment
    {
        $payment->refresh();

        if ($payment->charge_currency !== null && $outcome->amount !== null) {
            // The provider reports the charge currency (USD); money on Nawy stays in the country currency.
            $outcome = new GatewayOutcome($outcome->status, $outcome->gatewayReference, $this->conversion->toLocal($payment, $outcome->amount),
                $outcome->failureCode, $outcome->failureMessage, $outcome->card);
        }

        if ($outcome->status === PaymentStatus::Pending || $outcome->status === $payment->status) {
            return $payment;
        }

        try {
            match ($outcome->status) {
                PaymentStatus::Authorized => $this->machine->transition($payment, PaymentStatus::Authorized, $actorType, $actorUserId, $source),
                PaymentStatus::Captured => $this->amountMatches($payment, $outcome)
                    ? $this->machine->transition($payment, PaymentStatus::Captured, $actorType, $actorUserId, $source, attributes: [
                        'captured_amount' => $this->capturedAmount($payment, $outcome),
                    ])
                    : $this->machine->transition($payment, PaymentStatus::UnderReview, $actorType, $actorUserId, $source, __('The gateway captured a different amount.'), [
                        'captured_amount' => $this->capturedAmount($payment, $outcome),
                        'failure_code' => 'amount_mismatch',
                        'failure_message' => __('The amount captured by the gateway differs from the order amount; the payment is held for review.'),
                    ]),
                PaymentStatus::Failed, PaymentStatus::Expired => $this->machine->transition($payment, $outcome->status, $actorType, $actorUserId, $source, $outcome->failureMessage, [
                    'failure_code' => $outcome->failureCode ?? $outcome->status->value,
                    'failure_message' => $outcome->failureMessage ?? ($outcome->status === PaymentStatus::Expired ? __('The payment session expired.') : __('The payment did not go through.')),
                ]),
                default => throw new PaymentTransitionRefused("Gateways do not report {$outcome->status->value}."),
            };
        } catch (PaymentTransitionRefused $refused) {
            if ($outcome->status === PaymentStatus::Captured && $payment->status === PaymentStatus::Expired) {
                Log::warning('Late capture on an expired payment — refund needed', ['payment' => $payment->reference]);
            }

            return $payment;
        }

        if ($payment->status === PaymentStatus::Authorized) {
            $this->captureAuthorized($payment, $source);
        }

        if ($outcome->card !== null && $outcome->status->isSuccessful() && $payment->customer_user_id !== null) {
            app(SavedCards::class)->remember((int) $payment->customer_user_id, $payment->method->value, $outcome->card);
        }

        return $payment->refresh();
    }

    public function gateway(Payment $payment): PaymentGateway
    {
        $config = $payment->method->isGateway() ? $this->catalog->config($this->countryContext->current(), $payment->method) : null;
        $gateway = $this->gateways->make($payment->method, $config);

        return $payment->charge_currency === null ? $gateway : new ConvertedCurrencyGateway($gateway, $this->conversion);
    }

    /**
     * The request with the amount the provider is charged (converted for PayPal USD).
     */
    private function forGateway(PaymentRequest $request, Payment $payment): PaymentRequest
    {
        if ($payment->charge_currency === null) {
            return $request;
        }

        return new PaymentRequest($request->reference, $request->orderNumber, $this->conversion->gatewayAmount($payment), $request->description,
            $request->customerEmail, $request->customerName, $request->customerPhone, $request->countryIso2, $request->locale,
            $request->returnUrl, $request->cancelUrl, $request->notifyUrl, $request->idempotencyKey, $request->shippingAddress);
    }

    private function captureAuthorized(Payment $payment, string $source): void
    {
        try {
            $outcome = $this->gateway($payment)->capture($payment, $this->money->of((string) $payment->amount, $payment->currency));
        } catch (GatewayError $error) {
            Log::warning('Capture failed; the payment stays authorised', ['payment' => $payment->reference, 'error' => $error->getMessage()]);

            return;
        }

        if ($outcome->status === PaymentStatus::Captured) {
            $this->applyOutcome($payment, $outcome, $source.':capture');
        }
    }

    /**
     * Whether the provider captured exactly the attempt amount (no amount
     * reported = the attempt amount).
     */
    private function amountMatches(Payment $payment, GatewayOutcome $outcome): bool
    {
        return $outcome->amount === null
            || $this->money->of($outcome->amount, $payment->currency)->isEqualTo($this->money->of((string) $payment->amount, $payment->currency));
    }

    /**
     * The captured amount as the provider reports it (defaults to the
     * attempt amount); a mismatch is logged for reconciliation.
     */
    private function capturedAmount(Payment $payment, GatewayOutcome $outcome): string
    {
        $expected = $this->money->of((string) $payment->amount, $payment->currency);

        if ($outcome->amount === null) {
            return $this->money->toStorage($expected);
        }

        $reported = $this->money->of($outcome->amount, $payment->currency);

        if (! $reported->isEqualTo($expected)) {
            Log::warning('Gateway captured a different amount', ['payment' => $payment->reference, 'expected' => (string) $expected->getAmount(), 'reported' => (string) $reported->getAmount()]);
        }

        return $this->money->toStorage($reported);
    }

    /**
     * @param  array<string, mixed>  $shippingAddress
     */
    private function paymentRequest(Payment $payment, User $customer, string $number, string $description, array $shippingAddress = []): PaymentRequest
    {
        $country = $this->countryContext->current();
        $market = $country->marketSlug(in_array(app()->getLocale(), $country->locales, true) ? app()->getLocale() : $country->default_locale);
        $parameters = ['market' => $market, 'reference' => $payment->reference];

        return new PaymentRequest(
            reference: $payment->reference,
            orderNumber: $number,
            amount: $this->money->of((string) $payment->amount, $payment->currency),
            description: $description,
            customerEmail: (string) $customer->email,
            customerName: (string) ($shippingAddress['name'] ?? $customer->name),
            customerPhone: $shippingAddress['phone'] ?? null,
            countryIso2: $country->iso2,
            locale: app()->getLocale(),
            returnUrl: route('api.v1.payments.return', $parameters),
            cancelUrl: route('api.v1.payments.cancel', $parameters),
            notifyUrl: route('api.v1.payments.webhook', ['market' => $market, 'gateway' => $payment->method->value]),
            idempotencyKey: $payment->idempotency_key,
            shippingAddress: $shippingAddress,
        );
    }
}
