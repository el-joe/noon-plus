<?php

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Identity\Actions\RecordLoginEvent;
use App\Domains\Identity\Actions\RequestEmailLoginCode;
use App\Domains\Identity\Actions\VerifyEmailLoginCode;
use App\Domains\Identity\Enums\LoginOutcome;
use App\Domains\Identity\Exceptions\EmailLoginCodeRejected;
use App\Domains\Identity\Http\Requests\RequestEmailCodeRequest;
use App\Domains\Identity\Http\Requests\VerifyEmailCodeRequest;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Passwordless customer sign-in by email code (MASTER_PLAN ADR 5).
 */
class EmailCodeAuthController extends Controller
{
    public function send(RequestEmailCodeRequest $request, RequestEmailLoginCode $requestCode): JsonResponse
    {
        try {
            $requestCode->handle($request->validated('email'));
        } catch (EmailLoginCodeRejected $rejected) {
            return ApiResponse::error($rejected->reason, $rejected->getMessage(), $rejected->status());
        }

        return ApiResponse::success(['sent' => true], status: 202);
    }

    public function verify(VerifyEmailCodeRequest $request, VerifyEmailLoginCode $verifyCode, RecordLoginEvent $recordLoginEvent): JsonResponse
    {
        try {
            $result = $verifyCode->handle(
                $request->validated('email'),
                $request->validated('code'),
                $request->validated('device_name'),
            );
        } catch (EmailLoginCodeRejected $rejected) {
            $recordLoginEvent->handle(
                $request,
                'api',
                $rejected->reason === EmailLoginCodeRejected::LOCKED ? LoginOutcome::Locked : LoginOutcome::WrongCode,
                email: $request->validated('email'),
            );

            return ApiResponse::error($rejected->reason, $rejected->getMessage(), $rejected->status());
        }

        $recordLoginEvent->handle($request, 'api', LoginOutcome::Succeeded, $result['user']);

        return ApiResponse::success([
            'token' => $result['token'],
            'user' => [
                'id' => $result['user']->id,
                'name' => $result['user']->name,
                'email' => $result['user']->email,
            ],
        ]);
    }
}
