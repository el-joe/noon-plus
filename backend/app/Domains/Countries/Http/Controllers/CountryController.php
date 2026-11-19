<?php

namespace App\Domains\Countries\Http\Controllers;

use App\Domains\Countries\Http\Resources\CountryResource;
use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Countries\Support\DomainMap;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\PublicCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CountryController extends Controller
{
    private const CACHE_SECONDS = 3600;

    /**
     * Active countries for the country & language selector (cached per
     * locale, forgotten when a country changes).
     */
    public function index(Request $request): JsonResponse
    {
        $countries = PublicCache::remember(PublicCache::COUNTRIES, 'index:'.app()->getLocale(), self::CACHE_SECONDS,
            fn (): array => CountryResource::collection(Country::query()->active()->get())->resolve($request));

        return ApiResponse::success($countries);
    }

    /**
     * Context of the current market (e.g. /api/v1/uae-en/context).
     */
    public function context(Request $request, CountryContext $countryContext): JsonResponse
    {
        $country = $countryContext->current();
        $data = PublicCache::remember(PublicCache::COUNTRIES, 'context:'.$country->code.':'.app()->getLocale(), self::CACHE_SECONDS,
            fn (): array => (new CountryResource($country))->resolve($request));

        return ApiResponse::success($data, [
            'market' => $request->attributes->get('market'),
            'locale' => app()->getLocale(),
            // QC-044: the market's primary storefront host (canonical / hreflang links), null = the default website host.
            'storefront_host' => app(DomainMap::class)->primaryStorefront($country, app()->getLocale()),
        ]);
    }
}
