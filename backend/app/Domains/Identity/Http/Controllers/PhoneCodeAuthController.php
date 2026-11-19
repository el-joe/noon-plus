<?php

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Identity\Actions\PhoneLoginCodes;
use App\Domains\Identity\Actions\RecordLoginEvent;
use App\Domains\Identity\Actions\SignInCustomer;
use App\Domains\Identity\Enums\LoginMethod;
use App\Domains\Identity\Enums\LoginOutcome;
use App\Domains\Identity\Exceptions\EmailLoginCodeRejected;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * First sign-in of a legacy phone-only customer by a code sent by SMS
 * (data-migration-plan §5.2). The answer to `send` is the same whether or
 * not the number has such an account. A signed-in account with
 * `requires_email` must add and verify an email before using the rest of
 * the API (`POST me/email`, `POST me/email/verify`).
 */
class PhoneCodeAuthController extends Controller
{
    public function send(Request $request, PhoneLoginCodes $codes): JsonResponse
    {
        $phone = $this->phone($request);

        if ($phone === null) {
            return ApiResponse::error('validation_failed', __('Enter a valid phone number.'), 422, ['phone' => [__('Enter a valid phone number.')]]);
        }

        try {
            $codes->send($phone, app()->getLocale());
        } catch (EmailLoginCodeRejected $rejected) {
            return ApiResponse::error($rejected->reason, $rejected->getMessage(), $rejected->status());
        }

        return ApiResponse::success(['sent' => true], status: 202);
    }

    public function verify(Request $request, PhoneLoginCodes $codes, RecordLoginEvent $recordLoginEvent): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'digits:'.config('nawy.customer_auth.email_code_length')],
            'device_name' => ['required', 'string', 'max:100'],
        ]);
        $phone = $this->phone($request);

        if ($phone === null) {
            return ApiResponse::error('validation_failed', __('Enter a valid phone number.'), 422, ['phone' => [__('Enter a valid phone number.')]]);
        }

        try {
            $result = $codes->verify($phone, $data['code'], $data['device_name']);
        } catch (EmailLoginCodeRejected $rejected) {
            $recordLoginEvent->handle($request, 'api', $rejected->reason === EmailLoginCodeRejected::LOCKED ? LoginOutcome::Locked : LoginOutcome::WrongCode, method: LoginMethod::PhoneCode);

            return ApiResponse::error($rejected->reason, $rejected->getMessage(), $rejected->status());
        }

        $recordLoginEvent->handle($request, 'api', LoginOutcome::Succeeded, $result['user'], method: LoginMethod::PhoneCode);

        return ApiResponse::success([
            'token' => $result['token'],
            'user' => SignInCustomer::present($result['user']),
        ]);
    }

    private function phone(Request $request): ?string
    {
        $phone = $request->validate(['phone' => ['required', 'string', 'max:32']])['phone'];

        return PhoneLoginCodes::normalize($phone);
    }
}
