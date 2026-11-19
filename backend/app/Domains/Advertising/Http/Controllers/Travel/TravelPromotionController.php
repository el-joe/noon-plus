<?php

namespace App\Domains\Advertising\Http\Controllers\Travel;

use App\Domains\Advertising\Enums\TravelPromotionStatus;
use App\Domains\Advertising\Models\TravelPromotion;
use App\Domains\Advertising\Models\TravelPromotionProduct;
use App\Domains\Advertising\Support\TravelPromotions;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Support\PaymentAttempts;
use App\Domains\Travel\Enums\PackageStatus;
use App\Domains\Travel\Models\TravelPackage;
use App\Domains\Vendors\Models\Vendor;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Travel Partner "Promote a trip" (owner decision D14). The agency picks
 * one of its active trips and a promotion option, sees the price (VAT
 * included) and pays Nawy by card on the country gateway; the gateway
 * returns to {@see return()}, which verifies the payment server-side. The
 * list shows each promotion's status and paid window; the receipt is
 * drawn from the payment record (travel bookings never settle, so there is
 * no vendor statement line). Listing trips stays free; nothing else is
 * charged (D11). Every query is scoped to the signed-in agency.
 */
class TravelPromotionController extends Controller
{
    public function __construct(private TravelPromotions $promotions, private CountryContext $countryContext) {}

    public function index(Request $request): View
    {
        return view('panels.travel.promotions', [
            'promotions' => TravelPromotion::query()->where('vendor_id', $this->agency($request)->id)->with(['package', 'product'])->latest('id')->paginate(20),
            'timezone' => $this->countryContext->current()->timezone,
        ]);
    }

    public function create(Request $request): View
    {
        $agency = $this->agency($request);

        return view('panels.travel.promote', [
            'packages' => TravelPackage::query()->where('vendor_id', $agency->id)->where('status', PackageStatus::Active)->orderBy('title_en')->get(),
            'products' => TravelPromotionProduct::query()->where('is_active', true)->whereHas('slot', fn ($slot) => $slot->where('is_active', true))->orderBy('duration_days')->get(),
            'currency' => $this->countryContext->current()->currency,
            'selected' => $request->integer('package') ?: null,
            'vatRate' => (string) $this->countryContext->current()->tax_rate,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'package_id' => ['required', 'integer'],
            'product_id' => ['required', 'integer'],
        ]);
        $agency = $this->agency($request);
        $package = TravelPackage::query()->where('vendor_id', $agency->id)->findOrFail($data['package_id']);
        $product = TravelPromotionProduct::query()->with('slot')->findOrFail($data['product_id']);

        [$promotion, $payment] = $this->promotions->buy($agency, $package, $product, $request->user());

        return $this->toGateway($promotion, $payment);
    }

    /**
     * Pay again for a promotion still waiting for payment.
     */
    public function pay(Request $request, int $promotion): RedirectResponse
    {
        $promotion = $this->own($request, $promotion);

        return $this->toGateway($promotion, $this->promotions->pay($promotion, $request->user()));
    }

    /**
     * Back from the gateway: the result is checked with the gateway (never
     * read from the redirect), then the list opens with a message that says
     * where the promotion stands: active (or starting after a running one),
     * pending payment confirmation, paid but not live, or not paid.
     */
    public function return(Request $request, PaymentAttempts $attempts): RedirectResponse
    {
        $payment = Payment::query()->where('reference', (string) $request->query('payment'))->where('payable_type', TravelPromotion::PAYABLE)->first();
        $promotion = $payment === null ? null : TravelPromotion::query()->where('vendor_id', $this->agency($request)->id)->find($payment->payable_id);

        if ($payment === null || $promotion === null) {
            return redirect()->route('travel_agency.promotions')->withErrors(['action' => __('We could not find this payment.')]);
        }

        if (in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Authorized], true)) {
            $payment = $attempts->refresh($payment, ActorType::Agency, $request->user()->id, 'return');
        }

        $promotion->refresh()->load('package');
        $timezone = $this->countryContext->current()->timezone;
        $trip = (string) $promotion->package?->localizedTitle();
        $back = redirect()->route('travel_agency.promotions');

        if ($promotion->status === TravelPromotionStatus::Active) {
            $until = $promotion->ends_at?->setTimezone($timezone)->format('j M Y, H:i');

            return $promotion->starts_at !== null && $promotion->starts_at->isFuture()
                ? $back->with('status', __('Payment received. Your promotion for :trip is confirmed and starts on :start, after the one running now; it ends on :date.', ['trip' => $trip, 'start' => $promotion->starts_at->setTimezone($timezone)->format('j M Y, H:i'), 'date' => $until]))
                : $back->with('status', __('Payment received. Your promotion for :trip is now active until :date.', ['trip' => $trip, 'date' => $until]));
        }

        if ($promotion->status === TravelPromotionStatus::PendingPayment && in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Authorized, PaymentStatus::UnderReview], true)) {
            return $back->with('status', __('Your promotion for :trip is pending payment confirmation. It goes live as soon as the gateway confirms the payment.', ['trip' => $trip]));
        }

        if (in_array($payment->status, [PaymentStatus::Captured, PaymentStatus::PartiallyRefunded, PaymentStatus::Refunded], true)) {
            return $back->withErrors(['action' => __('Payment received, but your promotion for :trip is not active (:status). Contact Nawy support about a refund.', ['trip' => $trip, 'status' => __(str_replace('_', ' ', ucfirst($promotion->status->value)))])]);
        }

        return $back->withErrors(['action' => __('The payment did not go through, so your promotion for :trip is not active. You can try again.', ['trip' => $trip])
            .($payment->failure_message ? ' '.$payment->failure_message : '')]);
    }

    /**
     * Receipt / tax invoice from the payment record.
     */
    public function receipt(Request $request, int $promotion): View
    {
        $promotion = $this->own($request, $promotion)->load(['package', 'product', 'vendor']);
        $payment = $promotion->payments()->whereIn('status', [PaymentStatus::Captured, PaymentStatus::PartiallyRefunded, PaymentStatus::Refunded])->latest('id')->first();
        abort_if($payment === null, 404);

        return view('panels.travel.promotion-receipt', [
            'promotion' => $promotion,
            'payment' => $payment,
            'country' => $this->countryContext->current(),
        ]);
    }

    private function toGateway(TravelPromotion $promotion, Payment $payment): RedirectResponse
    {
        if ($payment->status === PaymentStatus::Failed) {
            return redirect()->route('travel_agency.promotions')->withErrors(['action' => $payment->failure_message ?: __('The payment did not go through. You can try again.')]);
        }

        if ($payment->redirect_url !== null) {
            return redirect()->away($payment->redirect_url);
        }

        return redirect()->route('travel_agency.promotions.return', ['payment' => $payment->reference]);
    }

    private function own(Request $request, int $id): TravelPromotion
    {
        return TravelPromotion::query()->where('vendor_id', $this->agency($request)->id)->findOrFail($id);
    }

    private function agency(Request $request): Vendor
    {
        return $request->attributes->get('agency');
    }
}
