<?php

namespace App\Domains\Onboarding\Http\Controllers\Api;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Onboarding\Models\AdvertiseInquiry;
use App\Domains\Onboarding\Models\PortalFunnelEvent;
use App\Domains\Onboarding\Support\AdvertiseInquiries;
use App\Domains\Onboarding\Support\JoinOptions;
use App\Domains\Onboarding\Support\PortalFunnel;
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
                'min' => AdvertiseInquiry::budgetRange($band, $currency)[0],
                'max' => AdvertiseInquiry::budgetRange($band, $currency)[1],
                'currency' => $currency,
            ], array_keys(AdvertiseInquiry::BUDGET_BANDS)),
        ]);
    }

    /**
     * Portal funnel tracking (FR-POR-2/8, QC-042): a page view, a wizard step
     * reached or an application submitted. The anonymous visitor token (the
     * website's `X-Visitor-Token`) is stored only as a keyed hash.
     */
    public function trackFunnel(Request $request, PortalFunnel $funnel): JsonResponse
    {
        $event = $request->validate([
            'event' => ['required', Rule::in(PortalFunnelEvent::EVENTS)],
            'page' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9\/_-]+$/'],
            'type' => ['nullable', 'string', 'max:40', 'regex:/^[a-z0-9_-]+$/'],
            'step' => ['nullable', 'string', 'max:40', 'regex:/^[a-z0-9_-]+$/'],
        ]);
        $visitor = $request->header('X-Visitor-Token');
        $funnel->record($event, is_string($visitor) ? mb_substr($visitor, 0, 64) : null, app()->getLocale());

        return ApiResponse::success(['recorded' => true], status: 202);
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
