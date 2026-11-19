<?php

namespace App\Domains\Cart\Http\Controllers;

use App\Domains\Cart\Exceptions\CartRejected;
use App\Domains\Cart\Http\CartPresenter;
use App\Domains\Cart\Http\Requests\SetCartShippingRequest;
use App\Domains\Cart\Http\Requests\SetCartWarrantyRequest;
use App\Domains\Cart\Models\Cart;
use App\Domains\Cart\Support\CartService;
use App\Domains\Cart\Support\CheckoutCalculator;
use App\Domains\Coupons\Models\Coupon;
use App\Domains\Coupons\Support\CouponValidator;
use App\Domains\Shipping\Enums\ShippingMethod;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Cart for signed-in customers (Sanctum token) and guests (`X-Cart-Token`).
 * Guests receive their token in the `X-Cart-Token` response header.
 */
class CartController extends Controller
{
    public function __construct(
        private CartService $carts,
        private CheckoutCalculator $calculator,
    ) {}

    /**
     * A guest without a (known) cart token gets an empty cart and no new
     * cart row (G-W9); the token is issued on the first write.
     */
    public function show(Request $request): JsonResponse
    {
        if ($this->customerId($request) === null) {
            return $this->respond($this->carts->findGuest($request->header('X-Cart-Token')) ?? new Cart, $request);
        }

        return $this->respond($this->resolveCart($request), $request);
    }

    public function setItem(Request $request): JsonResponse
    {
        $data = $request->validate([
            'listing_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:0', 'max:50'],
        ]);
        $cart = $this->resolveCart($request);

        return $this->attempt($cart, $request, fn () => $this->carts->setQuantity($cart, $data['listing_id'], $data['quantity']));
    }

    public function removeItem(Request $request, int $listingId): JsonResponse
    {
        $cart = $this->resolveCart($request);
        $this->carts->remove($cart, $listingId);

        return $this->respond($cart, $request);
    }

    /**
     * Choose (or clear) the extended warranty plan for one cart line.
     */
    public function setWarranty(SetCartWarrantyRequest $request, int $listingId): JsonResponse
    {
        $cart = $this->resolveCart($request);
        $planId = $request->validated('warranty_plan_id');

        return $this->attempt($cart, $request, fn () => $this->carts->setWarranty($cart, $listingId, $planId === null ? null : (int) $planId));
    }

    /**
     * Measurements for a made-to-order line.
     */
    public function setMeasurements(Request $request, int $listingId): JsonResponse
    {
        $data = $request->validate([
            'measurements' => ['required', 'array'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $cart = $this->resolveCart($request);

        return $this->attempt($cart, $request, fn () => $this->carts->setMeasurements($cart, $listingId, $data['measurements'], $data['note'] ?? null));
    }

    /**
     * Set the delivery city/area and method used for the shipping quote.
     */
    public function setShipping(SetCartShippingRequest $request): JsonResponse
    {
        $cart = $this->resolveCart($request);
        $this->carts->setShipping(
            $cart,
            $request->validated('city'),
            $request->validated('area'),
            ShippingMethod::tryFrom((string) $request->validated('method')) ?? ShippingMethod::Standard,
        );

        return $this->respond($cart, $request);
    }

    public function applyCoupon(Request $request, CouponValidator $validator): JsonResponse
    {
        $code = mb_strtoupper(trim((string) $request->validate(['code' => ['required', 'string', 'max:32']])['code']));
        $cart = $this->resolveCart($request);

        return $this->attempt($cart, $request, function () use ($code, $cart, $validator, $request): void {
            $coupon = Coupon::query()->where('code', $code)->first()
                ?? throw new CartRejected('coupon_not_found', __('That coupon code does not exist.'));

            $validator->assertApplicable($coupon, $cart, $this->customerId($request));
            $cart->coupons()->syncWithoutDetaching([$coupon->id]);
        });
    }

    public function removeCoupon(Request $request, string $code): JsonResponse
    {
        $cart = $this->resolveCart($request);
        $couponId = Coupon::query()->where('code', mb_strtoupper($code))->value('id');

        if ($couponId !== null) {
            $cart->coupons()->detach($couponId);
        }

        return $this->respond($cart, $request);
    }

    /**
     * Move the guest cart from `X-Cart-Token` into the signed-in customer's
     * cart; lines clipped or skipped are listed in `meta.merge_warnings`.
     */
    public function merge(Request $request): JsonResponse
    {
        $customerCart = $this->carts->forCustomer($request->user()->id);
        $guestCart = Cart::query()->where('guest_token', (string) $request->header('X-Cart-Token'))->first();

        $warnings = $guestCart !== null ? $this->carts->merge($guestCart, $customerCart) : [];

        return $this->respond($customerCart, $request, ['merge_warnings' => $warnings]);
    }

    public function resolveCart(Request $request): Cart
    {
        $customerId = $this->customerId($request);

        return $customerId !== null
            ? $this->carts->forCustomer($customerId)
            : $this->carts->forGuest($request->header('X-Cart-Token'), $request->ip());
    }

    private function customerId(Request $request): ?int
    {
        return Auth::guard('sanctum')->user()?->id;
    }

    private function attempt(Cart $cart, Request $request, callable $action): JsonResponse
    {
        try {
            $action();
        } catch (CartRejected $rejected) {
            $error = ApiResponse::error($rejected->errorCode, $rejected->getMessage(), $rejected->status);

            // QA-047: a refused first write leaves no empty guest cart and issues no token.
            if ($cart->wasRecentlyCreated && $cart->customer_user_id === null && ! $cart->items()->exists()) {
                $cart->delete();

                return $error;
            }

            return $cart->guest_token !== null ? $error->header('X-Cart-Token', $cart->guest_token) : $error;
        }

        return $this->respond($cart, $request);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function respond(Cart $cart, Request $request, array $meta = []): JsonResponse
    {
        $response = ApiResponse::success(CartPresenter::present($cart, $this->calculator->quote($cart, $request->query('payment_method'))), $meta);

        return $cart->guest_token !== null ? $response->header('X-Cart-Token', $cart->guest_token) : $response;
    }
}
