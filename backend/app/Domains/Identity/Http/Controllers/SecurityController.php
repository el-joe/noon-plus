<?php

namespace App\Domains\Identity\Http\Controllers;

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

        return ApiResponse::success(['revoked' => true]);
    }

    public function loginHistory(Request $request): JsonResponse
    {
        $events = $request->user()->loginEvents()
            ->latest('created_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get(['channel', 'outcome', 'ip_address', 'user_agent', 'created_at']);

        return ApiResponse::success($events);
    }
}
