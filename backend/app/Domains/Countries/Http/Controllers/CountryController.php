<?php

namespace App\Domains\Countries\Http\Controllers;

use App\Domains\Countries\Http\Resources\CountryResource;
use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Support\CountryContext;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CountryController extends Controller
{
    /**
     * Active countries for the country & language selector.
     */
    public function index(): JsonResponse
    {
        return ApiResponse::success(CountryResource::collection(Country::query()->active()->get()));
    }

    /**
     * Context of the current market (e.g. /api/v1/uae-en/context).
     */
    public function context(Request $request, CountryContext $countryContext): JsonResponse
    {
        return ApiResponse::success(new CountryResource($countryContext->current()), [
            'market' => $request->attributes->get('market'),
            'locale' => app()->getLocale(),
        ]);
    }
}
