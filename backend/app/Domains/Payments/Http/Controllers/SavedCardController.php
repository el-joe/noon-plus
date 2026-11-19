<?php

namespace App\Domains\Payments\Http\Controllers;

use App\Domains\Payments\Models\SavedPaymentCard;
use App\Domains\Payments\Support\SavedCards;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * G-A6 (Figma "40 Cards"): the customer's saved cards in this market —
 * brand, last 4 and expiry only — set the default or remove one.
 */
class SavedCardController extends Controller
{
    public function __construct(private SavedCards $cards) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(SavedPaymentCard::query()->where('customer_user_id', $request->user()->id)
            ->orderByDesc('is_default')->latest('last_used_at')->get()
            ->map(fn (SavedPaymentCard $card): array => $this->present($card))->all());
    }

    public function makeDefault(Request $request, int $card): JsonResponse
    {
        $model = $this->find($request, $card);
        $this->cards->makeDefault($model);

        return ApiResponse::success($this->present($model->refresh()));
    }

    public function destroy(Request $request, int $card): JsonResponse
    {
        $this->cards->forget($this->find($request, $card));

        return ApiResponse::success(['removed' => true]);
    }

    private function find(Request $request, int $card): SavedPaymentCard
    {
        return SavedPaymentCard::query()->where('customer_user_id', $request->user()->id)->findOrFail($card);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(SavedPaymentCard $card): array
    {
        return [
            'id' => $card->id,
            'gateway' => $card->gateway,
            'brand' => $card->brand,
            'last4' => $card->last4,
            'exp_month' => $card->exp_month,
            'exp_year' => $card->exp_year,
            'expired' => $card->isExpired(),
            'is_default' => $card->is_default,
        ];
    }
}
