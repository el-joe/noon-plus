<?php

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Identity\Models\PushDeviceToken;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Security & devices (account): signed-in devices and recent sign-ins.
 */
class SecurityController extends Controller
{
    public function devices(Request $request): JsonResponse
    {
        $currentTokenId = $request->user()->currentAccessToken()?->getKey();

        $devices = $request->user()->tokens()
            ->orderByDesc('last_used_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (PersonalAccessToken $token): array => [
                'id' => $token->id,
                'name' => $token->name,
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'created_at' => $token->created_at?->toIso8601String(),
                'is_current' => $token->id === $currentTokenId,
            ]);

        return ApiResponse::success($devices);
    }

    public function revokeDevice(Request $request, int $tokenId): JsonResponse
    {
        $deleted = $request->user()->tokens()->whereKey($tokenId)->delete();

        if ($deleted === 0) {
            return ApiResponse::error('device_not_found', __('That device is not signed in to your account.'), 404);
        }

        PushDeviceToken::query()->where('user_id', $request->user()->id)->where('personal_access_token_id', $tokenId)->delete();

        return ApiResponse::success(['revoked' => true]);
    }

    /**
     * Sign out (G-W7): revoke the token that made this request.
     */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            // G-A2: a signed-out device stops receiving push.
            PushDeviceToken::query()->where('personal_access_token_id', $token->id)->delete();
            $token->delete();
        }

        return ApiResponse::success(['signed_out' => true]);
    }

    public function loginHistory(Request $request): JsonResponse
    {
        $events = $request->user()->loginEvents()
            ->latest('created_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get(['channel', 'method', 'outcome', 'ip_address', 'user_agent', 'created_at']);

        return ApiResponse::success($events);
    }
}
