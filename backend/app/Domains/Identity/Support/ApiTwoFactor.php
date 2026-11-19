<?php

namespace App\Domains\Identity\Support;

use App\Domains\Identity\Actions\ConsumeEmailLoginCode;
use App\Domains\Identity\Actions\RequestEmailLoginCode;
use App\Domains\Identity\Exceptions\EmailLoginCodeRejected;
use App\Models\User;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Two-step sign-in for the staff app APIs (ops apps, delivery app) — the
 * same `panel_2fa` email code the panels ask for (WP11 security review:
 * a password alone must not open an account that has two-step sign-in on).
 * Without `two_factor_code` a code is mailed and the login answers 202
 * `two_factor_required`; the token is issued only with a valid code.
 */
class ApiTwoFactor
{
    public function __construct(private RequestEmailLoginCode $requestCode, private ConsumeEmailLoginCode $consumeCode) {}

    /**
     * Null when the login may go on; otherwise the response to return.
     */
    public function check(User $user, ?string $code): ?JsonResponse
    {
        if (! $user->two_factor_enabled) {
            return null;
        }

        try {
            if ($code === null || $code === '') {
                $this->requestCode->handle($user->email, 'panel_2fa');

                return ApiResponse::success(['two_factor_required' => true], status: 202);
            }

            $this->consumeCode->handle($user->email, $code, 'panel_2fa');
        } catch (EmailLoginCodeRejected $rejected) {
            return ApiResponse::error('two_factor_rejected', $rejected->getMessage(), 422);
        }

        return null;
    }
}
