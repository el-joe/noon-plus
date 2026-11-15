<?php

namespace App\Domains\Membership\Http\Controllers;

use App\Domains\Catalog\Http\Resources\MoneyResource;
use App\Domains\Membership\Models\Membership;
use App\Domains\Membership\Models\MembershipPlan;
use App\Domains\Membership\Support\Memberships;
use App\Domains\Payments\Http\Resources\PaymentResource;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Nawy One (app 99:6173, account Nawy One page): plans of the country,
 * the customer's membership, subscribe and cancel auto-renew.
 */
class MembershipController extends Controller
{
    public function __construct(private Memberships $memberships) {}

    public function plans(): JsonResponse
    {
        return ApiResponse::success(MembershipPlan::query()->where('is_active', true)->orderBy('price')->get()->map(fn (MembershipPlan $plan): array => [
            'code' => $plan->code,
            'name' => $plan->name(),
            'interval' => $plan->interval->value,
            'price' => MoneyResource::make((string) $plan->price),
            'trial_days' => $plan->trial_days,
            'free_shipping' => $plan->free_shipping,
        ])->all());
    }

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success($this->present($this->memberships->current($request->user()->id)));
    }

    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate(['plan' => ['required', 'string', 'max:32'], 'payment_method' => ['required', 'string', 'max:24']]);
        $plan = MembershipPlan::query()->where('code', $data['plan'])->firstOrFail();
        [$membership, $payment] = $this->memberships->subscribe($request->user(), $plan, $data['payment_method']);

        return ApiResponse::success($this->present($membership->load('plan')) + ['payment' => $payment === null ? null : new PaymentResource($payment)], status: 201);
    }

    public function cancel(Request $request): JsonResponse
    {
        return ApiResponse::success($this->present($this->memberships->cancel($request->user()->id)->load('plan')));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(?Membership $membership): array
    {
        return [
            'member' => $membership?->givesBenefits() ?? false,
            'status' => $membership?->status->value,
            'plan' => $membership?->plan?->code,
            'current_period_end' => $membership?->current_period_end?->toIso8601String(),
            'auto_renew' => $membership?->auto_renew,
            'benefits' => ['free_shipping' => (bool) ($membership?->givesBenefits() && $membership->plan->free_shipping)],
        ];
    }
}
