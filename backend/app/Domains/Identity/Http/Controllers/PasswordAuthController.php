<?php

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Identity\Actions\ConsumeEmailLoginCode;
use App\Domains\Identity\Actions\RecordLoginEvent;
use App\Domains\Identity\Actions\RequestEmailLoginCode;
use App\Domains\Identity\Actions\SignInCustomer;
use App\Domains\Identity\Enums\LoginMethod;
use App\Domains\Identity\Enums\LoginOutcome;
use App\Domains\Identity\Exceptions\EmailLoginCodeRejected;
use App\Domains\Identity\Notifications\CustomerPasswordResetNotification;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Optional customer password (D9): sign in with email + password, and
 * forgot / reset by an emailed link (storefront) or 6-digit code (apps).
 * The email code stays the default sign-in; an account only has a password
 * once its owner chose one.
 */
class PasswordAuthController extends Controller
{
    public function __construct(private RecordLoginEvent $recordLoginEvent) {}

    public function login(Request $request, SignInCustomer $signIn): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);
        $user = User::query()->where('email', Str::lower(trim($data['email'])))->first();

        if ($user === null || ! $user->hasChosenPassword() || ! Hash::check($data['password'], $user->password)) {
            $this->recordLoginEvent->handle($request, 'api', LoginOutcome::WrongCredentials, $user, $data['email'], LoginMethod::Password);

            return ApiResponse::error('invalid_credentials', __('That email and password do not match. Sign in with an email code if you have not set a password.'), 422);
        }

        if ($user->status !== 'active') {
            $this->recordLoginEvent->handle($request, 'api', LoginOutcome::Rejected, $user, method: LoginMethod::Password);

            return ApiResponse::error('account_suspended', __('This account is suspended. Contact Nawy support.'), 403);
        }

        $token = $signIn->handle($user, $data['device_name']);
        $this->recordLoginEvent->handle($request, 'api', LoginOutcome::Succeeded, $user, method: LoginMethod::Password);

        return ApiResponse::success(['token' => $token, 'user' => SignInCustomer::present($user)]);
    }

    /**
     * Always answers 202, whether or not the email has an account, so the
     * endpoint cannot be used to discover accounts.
     */
    public function forgot(Request $request, RequestEmailLoginCode $codes): JsonResponse
    {
        $email = Str::lower(trim($request->validate(['email' => ['required', 'string', 'email', 'max:255']])['email']));
        $user = User::query()->where('email', $email)->first();

        if ($user !== null) {
            try {
                $code = $codes->issue($email, 'password_reset');
                $token = PasswordBroker::broker()->createToken($user);
                $url = rtrim((string) config('nawy.payments.storefront_url'), '/').'/'.$request->attributes->get('market').'/reset-password?'.http_build_query(['email' => $email, 'token' => $token]);

                $user->notify(new CustomerPasswordResetNotification(
                    $url,
                    $code,
                    (int) config('nawy.customer_auth.email_code_ttl_minutes'),
                    (int) config('auth.passwords.users.expire'),
                ));
            } catch (EmailLoginCodeRejected) {
                // Locked after wrong codes: send nothing, answer the same.
            }
        }

        return ApiResponse::success(['sent' => true], status: 202);
    }

    public function reset(Request $request, ConsumeEmailLoginCode $consumeCode, SignInCustomer $signIn): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'token' => ['required_without:code', 'nullable', 'string', 'max:255'],
            'code' => ['required_without:token', 'nullable', 'string', 'max:12'],
            'password' => ['required', 'string', 'confirmed', 'max:255', Password::min((int) config('nawy.customer_auth.password_min_length'))],
            'device_name' => ['sometimes', 'string', 'max:100'],
        ]);
        $email = Str::lower(trim($data['email']));
        $user = User::query()->where('email', $email)->first();

        if (filled($data['token'] ?? null)) {
            if ($user === null || ! PasswordBroker::broker()->tokenExists($user, $data['token'])) {
                return ApiResponse::error('reset_token_invalid', __('This reset link is invalid or has expired. Ask for a new one.'), 422);
            }
        } else {
            try {
                $consumeCode->handle($email, (string) $data['code'], 'password_reset');
            } catch (EmailLoginCodeRejected $rejected) {
                return ApiResponse::error($rejected->reason, $rejected->getMessage(), $rejected->status());
            }

            if ($user === null) {
                return ApiResponse::error(EmailLoginCodeRejected::INVALID, __('That code is not correct.'), 422);
            }
        }

        $token = DB::transaction(function () use ($user, $data, $signIn): ?string {
            $user->forceFill([
                'password' => Hash::make($data['password']),
                'email_verified_at' => $user->email_verified_at ?? now(),
                'remember_token' => Str::random(60),
            ])->save();
            $user->tokens()->delete();
            PasswordBroker::broker()->deleteToken($user);

            return isset($data['device_name']) ? $signIn->handle($user, $data['device_name']) : null;
        });

        if ($token !== null) {
            $this->recordLoginEvent->handle($request, 'api', LoginOutcome::Succeeded, $user, method: LoginMethod::Password);
        }

        return ApiResponse::success(array_filter([
            'reset' => true,
            'token' => $token,
            'user' => $token !== null ? SignInCustomer::present($user) : null,
        ], fn ($value): bool => $value !== null));
    }

    /**
     * Set a first password, or change it (the current one is then required).
     * Every other signed-in device is signed out.
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'current_password' => [$user->hasChosenPassword() ? 'required' : 'nullable', 'string', 'max:255'],
            'password' => ['required', 'string', 'confirmed', 'max:255', Password::min((int) config('nawy.customer_auth.password_min_length'))],
        ]);

        if ($user->hasChosenPassword() && ! Hash::check((string) $data['current_password'], $user->password)) {
            return ApiResponse::error('validation_failed', __('The current password is not correct.'), 422, ['current_password' => [__('The current password is not correct.')]]);
        }

        $currentTokenId = $user->currentAccessToken()?->getKey();
        $user->forceFill(['password' => Hash::make($data['password'])])->save();
        $user->tokens()->when($currentTokenId !== null, fn ($query) => $query->whereKeyNot($currentTokenId))->delete();

        return ApiResponse::success(['has_password' => true]);
    }
}
