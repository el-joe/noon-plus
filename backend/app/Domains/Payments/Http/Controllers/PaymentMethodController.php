<?php

namespace App\Domains\Payments\Http\Controllers;

use App\Domains\Catalog\Http\Resources\MoneyResource;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Payments\Support\PaymentCurrencyConversion;
use App\Domains\Payments\Support\PaymentMethodCatalog;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use App\Shared\Money\MoneyFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Checkout payment step (Figma web 25:605, app 98:1762): the methods that
 * can pay `amount` in this market, recommended first, COD with its fee.
 */
class PaymentMethodController extends Controller
{
    public function index(Request $request, CountryContext $countryContext, PaymentMethodCatalog $catalog, MoneyFactory $money, PaymentCurrencyConversion $conversion): JsonResponse
    {
        $country = $countryContext->current();
        $decimals = $money->zero($country->currency)->getCurrency()->getDefaultFractionDigits();
        $request->validate(['amount' => ['required', 'regex:/^\d{1,13}(\.\d{1,'.$decimals.'})?$/']], [
            'amount.regex' => __('The amount must be a positive number with at most :decimals decimals.', ['decimals' => $decimals]),
        ]);
        $amount = $money->of((string) $request->query('amount'), $country->currency);

        $methods = $catalog->available($country, $amount, $request->user('sanctum')?->id)->map(fn (array $option): array => [
            'code' => $option['method']->value,
            'type' => $option['method']->type(),
            'name' => $option['method']->label(),
            'description' => $option['method']->description(),
            'recommended' => $option['recommended'],
            'fee' => $option['fee'] === null ? null : MoneyResource::make((string) $option['fee']->getAmount()),
            // PayPal USD conversion: what the provider will charge ({currency, amount, rate}); null = charged in the market currency.
            'charge' => $conversion->quote($option['method'], $amount),
        ]);

        return ApiResponse::success($methods->values(), ['currency' => $country->currency, 'amount' => (string) $amount->getAmount()]);
    }
}
