<?php

namespace App\Domains\GiftCards\Http\Controllers;

use App\Domains\Catalog\Http\Resources\MoneyResource;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\GiftCards\Models\GiftCard;
use App\Domains\GiftCards\Support\GiftCards;
use App\Domains\GiftCards\Support\Vouchers;
use App\Domains\Payments\Http\Resources\PaymentResource;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use App\Shared\Money\MoneyFactory;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Gift cards and vouchers (account gift cards / vouchers pages): buy a card
 * for someone (paid by card, emailed now or later), list the ones bought
 * (codes masked), redeem a gift card or voucher into Nawy Credits.
 */
class GiftCardController extends Controller
{
    public function __construct(private GiftCards $giftCards, private CountryContext $countryContext, private MoneyFactory $money) {}

    public function index(Request $request): JsonResponse
    {
        $cards = GiftCard::query()->where('purchaser_user_id', $request->user()->id)->latest('id')->paginate(20);

        return ApiResponse::success(collect($cards->items())->map(fn (GiftCard $card): array => $this->present($card))->all(), ['total' => $cards->total()]);
    }

    public function store(Request $request): JsonResponse
    {
        $country = $this->countryContext->current();
        $decimals = $this->money->zero($country->currency)->getCurrency()->getDefaultFractionDigits();
        $data = $request->validate([
            'amount' => ['required', 'regex:/^\d{1,9}(\.\d{1,'.$decimals.'})?$/'],
            'payment_method' => ['required', 'string', 'max:24'],
            'recipient_email' => ['required', 'email', 'max:255'],
            'recipient_name' => ['nullable', 'string', 'max:120'],
            'message' => ['nullable', 'string', 'max:500'],
            'send_at' => ['nullable', 'date', 'after:now', 'before:+1 year'],
            'design' => ['nullable', 'string', Rule::in(self::designKeys())],
        ]);

        [$card, $payment] = $this->giftCards->purchase(
            $request->user(),
            $this->money->of($data['amount'], $country->currency),
            $data['payment_method'],
            $data['recipient_email'],
            $data['recipient_name'] ?? null,
            $data['message'] ?? null,
            isset($data['send_at']) ? CarbonImmutable::parse($data['send_at']) : null,
        );

        $card->forceFill(['design' => $data['design'] ?? self::designKeys()[0]])->save();

        return ApiResponse::success($this->present($card) + ['payment' => new PaymentResource($payment)], status: 201);
    }

    /**
     * The card designs a buyer picks from (G-W30); the website draws them.
     */
    public function designs(): JsonResponse
    {
        return ApiResponse::success(array_map(fn (string $key): array => ['key' => $key, 'name' => __(Str::headline($key))], self::designKeys()));
    }

    /**
     * @return array<int, string>
     */
    public static function designKeys(): array
    {
        return array_values((array) config('nawy.finance.gift_card_designs', ['classic']));
    }

    public function redeem(Request $request): JsonResponse
    {
        $code = $request->validate(['code' => ['required', 'string', 'max:32']])['code'];
        $card = $this->giftCards->redeem($request->user(), $code);

        return ApiResponse::success(['redeemed' => MoneyResource::make((string) $card->amount), 'masked_code' => $card->maskedCode()]);
    }

    public function redeemVoucher(Request $request, Vouchers $vouchers): JsonResponse
    {
        $code = $request->validate(['code' => ['required', 'string', 'max:32']])['code'];
        $credit = $vouchers->redeem($request->user()->id, $code);

        return ApiResponse::success(['promotional' => MoneyResource::make((string) $credit->amount), 'expires_at' => $credit->expires_at->toIso8601String()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(GiftCard $card): array
    {
        return [
            'number' => $card->number,
            'masked_code' => $card->maskedCode(),
            'design' => $card->design ?? 'classic',
            'status' => $card->status->value,
            'amount' => MoneyResource::make((string) $card->amount),
            'recipient_email' => $card->recipient_email,
            'recipient_name' => $card->recipient_name,
            'send_at' => $card->send_at?->toIso8601String(),
            'delivered_at' => $card->delivered_at?->toIso8601String(),
            'expires_at' => $card->expires_at->toIso8601String(),
        ];
    }
}
