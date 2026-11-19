<?php

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Identity\Models\PushDeviceToken;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * G-A2: register / unregister the device's push token (store only; no
 * push is sent yet). Works for every app token (customer, delivery, ops).
 */
class PushTokenController extends Controller
{
    public function store(Request $request, CountryContext $countryContext): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'min:16', 'max:512'],
            'platform' => ['required', Rule::in(PushDeviceToken::PLATFORMS)],
            'app' => ['nullable', Rule::in(PushDeviceToken::APPS)],
        ]);
        $accessToken = $request->user()->currentAccessToken();

        $device = PushDeviceToken::query()->updateOrCreate(['token_hash' => PushDeviceToken::hashOf($data['token'])], [
            'user_id' => $request->user()->id,
            'personal_access_token_id' => $accessToken instanceof PersonalAccessToken ? $accessToken->id : null,
            'token' => $data['token'],
            'platform' => $data['platform'],
            'app' => $data['app'] ?? 'customer',
            'country_code' => $countryContext->current()->code,
            'locale' => app()->getLocale(),
            'last_seen_at' => now(),
        ]);

        return ApiResponse::success(['registered' => true, 'platform' => $device->platform, 'app' => $device->app], status: $device->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request): JsonResponse
    {
        $token = $request->validate(['token' => ['required', 'string', 'max:512']])['token'];

        PushDeviceToken::query()->where('user_id', $request->user()->id)->where('token_hash', PushDeviceToken::hashOf($token))->delete();

        return ApiResponse::success(['registered' => false]);
    }
}
