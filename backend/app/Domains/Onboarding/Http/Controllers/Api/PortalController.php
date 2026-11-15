<?php

namespace App\Domains\Onboarding\Http\Controllers\Api;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Onboarding\Models\AdvertiseInquiry;
use App\Domains\Onboarding\Support\AdvertiseInquiries;
use App\Domains\Onboarding\Support\JoinOptions;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use App\Shared\Validation\ContactDetails;
use App\Shared\Validation\CountryPhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Public portal API (PORTAL_PAGES §2, §4): the `/join` chooser options of
 * the market's country and the advertise request form.
 */
class PortalController extends Controller
{
    public function joinOptions(JoinOptions $options, CountryContext $countryContext): JsonResponse
    {
        return ApiResponse::success($options->forCountry($countryContext->current()));
    }

    /**
     * Choices for the advertise form (G-W28): advertiser types, interests
     * and monthly budget bands in the market's currency.
     */
    public function advertiseOptions(CountryContext $countryContext): JsonResponse
    {
        $currency = $countryContext->current()->currency;

        return ApiResponse::success([
            'advertiser_types' => AdvertiseInquiry::ADVERTISER_TYPES,
            'interests' => AdvertiseInquiry::INTERESTS,
            'budget_bands' => array_map(fn (string $band): array => [
                'key' => $band,
                'label' => AdvertiseInquiry::budgetLabel($band, $currency),
                'min' => AdvertiseInquiry::BUDGET_BANDS[$band][0],
                'max' => AdvertiseInquiry::BUDGET_BANDS[$band][1],
                'currency' => $currency,
            ], array_keys(AdvertiseInquiry::BUDGET_BANDS)),
        ]);
    }

    public function advertiseRequest(Request $request, AdvertiseInquiries $inquiries): JsonResponse
    {
        $data = $request->validate(ContactDetails::rules($request) + [
            'company' => ['nullable', 'string', 'max:160'],
            'advertiser_type' => ['required', Rule::in(AdvertiseInquiry::ADVERTISER_TYPES)],
            'interest' => ['required', Rule::in(AdvertiseInquiry::INTERESTS)],
            'monthly_budget' => ['nullable', 'string', 'max:40'],
            'monthly_budget_band' => ['nullable', Rule::in(array_keys(AdvertiseInquiry::BUDGET_BANDS))],
            'message' => ['nullable', 'string', 'max:1000'],
            'consent' => ['accepted'],
        ]);
        $data['phone'] = CountryPhone::normalize($data['phone']);
        $data['monthly_budget'] = $data['monthly_budget_band'] ?? $data['monthly_budget'] ?? null;
        unset($data['monthly_budget_band']);
        $inquiry = $inquiries->create($data, app()->getLocale(), $request->user('sanctum')?->id);

        return ApiResponse::success(['number' => $inquiry->number, 'status' => $inquiry->status->value], status: 201);
    }
}
