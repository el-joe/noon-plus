<?php

namespace App\Domains\Finance\Http\Controllers\Seller;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Finance\Enums\SubscriptionPaymentPreference;
use App\Domains\Finance\Models\VendorSubscription;
use App\Domains\Finance\Models\VendorSubscriptionInvoice;
use App\Domains\Finance\Models\VendorSubscriptionPlan;
use App\Domains\Finance\Support\SubscriptionInvoiceDocument;
use App\Domains\Finance\Support\VendorSubscriptions;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Support\PaymentAttempts;
use App\Domains\Payments\Support\PaymentMethodCatalog;
use App\Domains\Vendors\Support\VendorWorkspace;
use App\Http\Controllers\Controller;
use App\Shared\Money\MoneyFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Seller Center → Subscription (FR-FIN-7, SRS §4.3 "Subscription"): the
 * store's plan and status, the plans of the country, invoices (PDF), paying
 * an open invoice by card or from the next payout, the payment preference,
 * plan change (next period; at once during the trial) and cancel at period
 * end (resume before it ends).
 */
class SellerSubscriptionController extends Controller
{
    public function __construct(
        private VendorWorkspace $workspace,
        private CountryContext $countryContext,
        private VendorSubscriptions $subscriptions,
    ) {}

    public function show(Request $request, PaymentMethodCatalog $catalog, MoneyFactory $money): View|RedirectResponse
    {
        $vendor = $this->workspace->vendor();

        // BT-VEN-009: the gateway result is shown once — flashed, then the page reloads without `?payment=`
        // so later actions (which return to the previous URL) do not show it again.
        if ($request->query->has('payment')) {
            return redirect()->to($request->fullUrlWithoutQuery(['payment', 'status']))->with('payment_result', $this->paymentResult($vendor->id));
        }

        $country = $this->countryContext->current();
        $current = $this->subscriptions->current($vendor->id);
        $invoices = VendorSubscriptionInvoice::query()->where('vendor_id', $vendor->id)->with('plan')->latest('id')->paginate(12);
        $open = $invoices->first(fn (VendorSubscriptionInvoice $invoice): bool => $invoice->isPending());

        return view('panels.partner.subscription', [
            'vendor' => $vendor,
            'country' => $country,
            'subscription' => $current?->load('transitions'),
            'latest' => $current ?? VendorSubscription::query()->where('vendor_id', $vendor->id)->with('plan')->latest('id')->first(),
            'plans' => VendorSubscriptionPlan::query()->where('is_active', true)->orderBy('sort_order')->orderBy('price')->get(),
            'invoices' => $invoices,
            'cardMethods' => $open === null ? collect() : $catalog->available($country, $money->of((string) $open->amount, $open->currency))
                ->filter(fn (array $option): bool => $option['method']->isGateway())->map(fn (array $option): string => $option['method']->value)->values(),
            'restricted' => $this->subscriptions->isRestricted($vendor->id),
            'limit' => $this->subscriptions->listingLimit($vendor->id),
            'graceDays' => $this->subscriptions->graceDays(),
            'savedCards' => $this->subscriptions->cardsFor((int) request()->user()->id),
            'paymentResult' => $request->session()->get('payment_result'),
        ]);
    }

    /**
     * Back from the card gateway (`?payment={reference}`): the outcome of the store's own invoice payment,
     * read from the payment itself (browser test BT-VEN-005: a decline used to come back without a word).
     *
     * @return array{tone: string, message: string}|null
     */
    private function paymentResult(int $vendorId): ?array
    {
        $reference = request()->query('payment');

        if (! is_string($reference) || $reference === '') {
            return null;
        }

        $payment = Payment::query()->where('reference', $reference)->where('payable_type', VendorSubscriptionInvoice::PAYABLE)->first();

        if ($payment === null || ! VendorSubscriptionInvoice::query()->whereKey($payment->payable_id)->where('vendor_id', $vendorId)->exists()) {
            return null;
        }

        return match ($payment->status) {
            PaymentStatus::Captured, PaymentStatus::Authorized => ['tone' => 'success', 'message' => __('Invoice paid.')],
            PaymentStatus::Failed, PaymentStatus::Expired, PaymentStatus::Voided => ['tone' => 'danger', 'message' => $payment->failure_message ?? __('The payment did not go through.')],
            default => ['tone' => 'info', 'message' => __('The payment is not confirmed yet.')],
        };
    }

    public function subscribe(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', 'integer'],
            'payment_preference' => ['required', Rule::enum(SubscriptionPaymentPreference::class)],
        ]);
        $this->subscriptions->subscribe($this->workspace->vendor(), VendorSubscriptionPlan::query()->where('is_active', true)->findOrFail($data['plan_id']),
            SubscriptionPaymentPreference::from($data['payment_preference']), ActorType::Vendor, $request->user()->id);

        return back()->with('status', __('You are subscribed.'));
    }

    public function changePlan(Request $request): RedirectResponse
    {
        $planId = $request->validate(['plan_id' => ['required', 'integer']])['plan_id'];
        $this->subscriptions->changePlan($this->own(), VendorSubscriptionPlan::query()->where('is_active', true)->findOrFail($planId));

        return back()->with('status', __('Plan change saved.'));
    }

    public function preference(Request $request): RedirectResponse
    {
        $preference = $request->validate(['payment_preference' => ['required', Rule::enum(SubscriptionPaymentPreference::class)]])['payment_preference'];
        $this->subscriptions->setPreference($this->own(), SubscriptionPaymentPreference::from($preference));

        return back()->with('status', __('Payment preference saved.'));
    }

    /**
     * Auto-renew on (with one of the signed-in user's saved cards) or off.
     */
    public function autoRenew(Request $request): RedirectResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'saved_payment_card_id' => ['nullable', 'integer']]);
        $enabled = (bool) $data['enabled'];
        $card = $enabled ? $this->subscriptions->cardsFor($request->user()->id)->firstWhere('id', (int) ($data['saved_payment_card_id'] ?? 0)) : null;
        $this->subscriptions->setAutoRenew($this->own(), $enabled, $card);

        return back()->with('status', $enabled ? __('Auto-renew is on. Invoices are charged to your card ending :last4.', ['last4' => $card?->last4]) : __('Auto-renew is off. Pay each invoice from this page.'));
    }

    public function cancel(Request $request): RedirectResponse
    {
        $this->subscriptions->cancel($this->own(), ActorType::Vendor, $request->user()->id, 'Cancelled by the seller');

        return back()->with('status', __('Your subscription will end as requested.'));
    }

    public function resume(): RedirectResponse
    {
        $this->subscriptions->resume($this->own());

        return back()->with('status', __('Your subscription will continue.'));
    }

    public function pay(Request $request, int $invoice): RedirectResponse
    {
        $method = $request->validate(['method' => ['required', 'string', 'max:20']])['method'];
        $invoice = $this->invoice($invoice);

        if ($method === 'payout') {
            $this->subscriptions->deductFromPayout($invoice, ActorType::Vendor, $request->user()->id);

            return back()->with('status', __('Invoice will be deducted from the next payout.'));
        }

        $payment = $this->subscriptions->payByCard($invoice, $request->user(), $method);

        if ($payment->status === PaymentStatus::Failed) {
            return back()->withErrors(['action' => $payment->failure_message ?? __('The payment did not go through.')]);
        }

        return $payment->redirect_url !== null ? redirect()->away($payment->redirect_url) : back();
    }

    /**
     * Ask the gateway again about the invoice's latest card payment (after the redirect back).
     */
    public function verify(int $invoice, PaymentAttempts $attempts): RedirectResponse
    {
        $invoice = $this->invoice($invoice);
        $payment = Payment::query()->where('payable_type', VendorSubscriptionInvoice::PAYABLE)->where('payable_id', $invoice->id)->latest('id')->first();

        if ($payment !== null) {
            $attempts->refresh($payment, ActorType::Vendor, request()->user()->id);
        }

        return back()->with('status', $invoice->refresh()->isPending() ? __('The payment is not confirmed yet.') : __('Invoice paid.'));
    }

    public function pdf(int $invoice, SubscriptionInvoiceDocument $document): Response
    {
        return $document->pdf($this->invoice($invoice));
    }

    private function own(): VendorSubscription
    {
        return $this->subscriptions->current($this->workspace->vendor()->id) ?? abort(404);
    }

    private function invoice(int $id): VendorSubscriptionInvoice
    {
        return VendorSubscriptionInvoice::query()->where('vendor_id', $this->workspace->vendor()->id)->findOrFail($id);
    }
}
