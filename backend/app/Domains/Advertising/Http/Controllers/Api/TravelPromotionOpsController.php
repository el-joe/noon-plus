<?php

namespace App\Domains\Advertising\Http\Controllers\Api;

use App\Domains\Advertising\Enums\TravelPromotionStatus;
use App\Domains\Advertising\Models\TravelPromotion;
use App\Domains\Advertising\Models\TravelPromotionProduct;
use App\Domains\Advertising\Support\TravelPromotions;
use App\Domains\Catalog\Http\Resources\MoneyResource;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Panels\Http\Controllers\Api\Concerns\RefusesTransitions;
use App\Domains\Payments\Enums\PaymentStatus;
use App\Domains\Payments\Models\Payment;
use App\Domains\Payments\Support\PaymentAttempts;
use App\Domains\Travel\Enums\PackageStatus;
use App\Domains\Travel\Models\TravelPackage;
use App\Domains\Vendors\Models\Vendor;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use App\Shared\Localization\Label;
use App\Shared\Money\MoneyFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Travel agency ops app (D14): the agency's paid promotions and the in-app
 * purchase — options (its active trips × the promotion durations on sale,
 * price VAT included), buy (opens the promotion and the card payment; the
 * app pays on `payment.redirect_url` in an in-app browser tab and the
 * gateway returns to the app deep link `nawy.payments.app_return_urls.travel_agency`),
 * pay again, and the state after the return, checked with the gateway
 * server-side (`result`: active | scheduled | pending | failed | paid_inactive).
 * Same rules as the Travel Partner panel ({@see TravelPromotions}).
 */
class TravelPromotionOpsController extends Controller
{
    use RefusesTransitions;

    public function __construct(private TravelPromotions $promotions) {}

    public function index(Request $request): JsonResponse
    {
        $promotions = TravelPromotion::query()->where('vendor_id', $this->agency($request)->id)
            ->with(['package', 'product'])->latest('id')->limit(100)->get();

        return ApiResponse::success($promotions->map(fn (TravelPromotion $promotion): array => $this->present($promotion))->all(), [
            'buy_url' => route('travel_agency.promotions.create'),
            'in_app' => true,
        ]);
    }

    public function options(Request $request, CountryContext $countryContext, MoneyFactory $money): JsonResponse
    {
        $country = $countryContext->current();

        return ApiResponse::success([
            'packages' => TravelPackage::query()->where('vendor_id', $this->agency($request)->id)->where('status', PackageStatus::Active)->orderBy('title_en')->get()
                ->filter(fn (TravelPackage $package): bool => $this->promotions->isPromotable($package))
                ->map(fn (TravelPackage $package): array => ['id' => $package->id, 'title' => $package->localizedTitle()])->values()->all(),
            'products' => TravelPromotionProduct::query()->where('is_active', true)->whereHas('slot', fn ($slot) => $slot->where('is_active', true))->orderBy('duration_days')->get()
                ->map(fn (TravelPromotionProduct $product): array => [
                    'id' => $product->id,
                    'code' => $product->code,
                    'name' => $product->localizedName(),
                    'duration_days' => $product->duration_days,
                    'price' => MoneyResource::of((string) $product->price, $country->currency),
                    'vat' => MoneyResource::of((string) $this->promotions->vatIn($money->of((string) $product->price, $country->currency))->getAmount(), $country->currency),
                ])->all(),
            'vat_rate' => (string) $country->tax_rate,
        ]);
    }

    /**
     * `{package_id, product_id}` → 201 `{promotion, payment}`; the app opens
     * `payment.redirect_url` (null when the attempt failed at once: show
     * `payment.failure_message`).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['package_id' => ['required', 'integer'], 'product_id' => ['required', 'integer']]);
        $agency = $this->agency($request);
        $package = TravelPackage::query()->where('vendor_id', $agency->id)->findOrFail($data['package_id']);
        $product = TravelPromotionProduct::query()->with('slot')->findOrFail($data['product_id']);

        return $this->refusable(function () use ($agency, $package, $product, $request): JsonResponse {
            [$promotion, $payment] = $this->promotions->buy($agency, $package, $product, $request->user(), $this->returnUrl());

            return ApiResponse::success($this->withPayment($promotion, $payment), status: 201);
        });
    }

    public function show(Request $request, int $promotion): JsonResponse
    {
        $promotion = $this->own($request, $promotion);

        return ApiResponse::success($this->withPayment($promotion, $promotion->payments()->reorder()->latest('id')->first()));
    }

    /**
     * Pay again for a promotion still waiting for payment.
     */
    public function pay(Request $request, int $promotion): JsonResponse
    {
        $promotion = $this->own($request, $promotion);

        return $this->refusable(fn (): JsonResponse => ApiResponse::success(
            $this->withPayment($promotion, $this->promotions->pay($promotion, $request->user(), $this->returnUrl())), status: 201,
        ));
    }

    /**
     * After the deep-link return (or when the app comes back to the
     * foreground): ask the gateway about the latest attempt — never trust the
     * return URL — and answer the promotion's state.
     */
    public function verify(Request $request, int $promotion, PaymentAttempts $attempts): JsonResponse
    {
        $promotion = $this->own($request, $promotion);
        $reference = $request->validate(['payment' => ['nullable', 'string', 'max:64']])['payment'] ?? null;
        $payment = $promotion->payments()->reorder()->when($reference, fn ($query, string $value) => $query->where('reference', $value))->latest('id')->first();

        if ($payment !== null && in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Authorized], true)) {
            $payment = $attempts->refresh($payment, ActorType::Agency, $request->user()->id, 'app_return');
        }

        return ApiResponse::success($this->withPayment($promotion->refresh(), $payment));
    }

    /**
     * @return array<string, mixed>
     */
    private function withPayment(TravelPromotion $promotion, ?Payment $payment): array
    {
        $promotion->loadMissing(['package', 'product']);

        return [
            'promotion' => $this->present($promotion),
            'payment' => $payment === null ? null : [
                'reference' => $payment->reference,
                'status' => $payment->status->value,
                'method' => $payment->method->value,
                'redirect_url' => $payment->status === PaymentStatus::Pending ? $payment->redirect_url : null,
                'failure_message' => $payment->failure_message,
            ],
            'result' => $this->result($promotion, $payment),
        ];
    }

    /**
     * What the app shows: active (live now), scheduled (paid, starts after
     * the running one), pending (waiting for the gateway), failed (not paid —
     * try again), paid_inactive (paid but closed — contact support), closed.
     */
    private function result(TravelPromotion $promotion, ?Payment $payment): string
    {
        return match (true) {
            $promotion->status === TravelPromotionStatus::Active => $promotion->starts_at !== null && $promotion->starts_at->isFuture() ? 'scheduled' : 'active',
            $promotion->status === TravelPromotionStatus::PendingPayment && $payment !== null
                && in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Authorized, PaymentStatus::UnderReview], true) => 'pending',
            $promotion->status === TravelPromotionStatus::PendingPayment => 'failed',
            $payment !== null && in_array($payment->status, [PaymentStatus::Captured, PaymentStatus::PartiallyRefunded, PaymentStatus::Refunded], true)
                && $promotion->status !== TravelPromotionStatus::Expired => 'paid_inactive',
            default => 'closed',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function present(TravelPromotion $promotion): array
    {
        return [
            'id' => $promotion->id,
            'number' => $promotion->number,
            'status' => $promotion->status->value,
            'status_label' => Label::of($promotion->status),
            'package' => ['id' => $promotion->travel_package_id, 'title' => $promotion->package?->localizedTitle()],
            'product' => ['code' => $promotion->product?->code, 'name' => $promotion->product?->localizedName(), 'duration_days' => $promotion->duration_days],
            'amount' => MoneyResource::of((string) $promotion->amount, $promotion->currency),
            'starts_at' => $promotion->starts_at?->toIso8601String(),
            'ends_at' => $promotion->ends_at?->toIso8601String(),
            'created_at' => $promotion->created_at?->toIso8601String(),
            'can_pay' => $promotion->status === TravelPromotionStatus::PendingPayment,
        ];
    }

    private function returnUrl(): ?string
    {
        $url = (string) config('nawy.payments.app_return_urls.travel_agency', '');

        return $url === '' ? null : $url;
    }

    private function own(Request $request, int $id): TravelPromotion
    {
        return TravelPromotion::query()->where('vendor_id', $this->agency($request)->id)->findOrFail($id);
    }

    private function agency(Request $request): Vendor
    {
        return $request->attributes->get('ops_workspace');
    }
}
