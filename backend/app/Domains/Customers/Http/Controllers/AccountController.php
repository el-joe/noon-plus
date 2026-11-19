<?php

namespace App\Domains\Customers\Http\Controllers;

use App\Domains\Identity\Actions\ConsumeEmailLoginCode;
use App\Domains\Identity\Actions\RequestEmailLoginCode;
use App\Domains\Identity\Exceptions\EmailLoginCodeRejected;
use App\Domains\Identity\Support\SecurityEmails;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\PublicUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The signed-in customer's profile (G-W29): read, update name / language /
 * marketing preferences, avatar upload and removal, and an email change
 * confirmed with a code sent to the new address (the account keeps its
 * old email until the code is checked).
 */
class AccountController extends Controller
{
    public const MARKETING_CHANNELS = ['email', 'sms', 'push', 'whatsapp'];

    public const AVATAR_DISK = 'public';

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success($this->present($request->user()));
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'locale' => ['sometimes', Rule::in(['en', 'ar'])],
            'marketing' => ['sometimes', 'array'],
            'marketing.*' => ['boolean'],
        ]);
        $user = $request->user();

        if (isset($data['marketing'])) {
            $unknown = array_diff(array_keys($data['marketing']), self::MARKETING_CHANNELS);

            if ($unknown !== []) {
                return ApiResponse::error('validation_failed', __('Unknown marketing channel.'), 422, ['marketing' => [__('Use email, sms, push or whatsapp.')]]);
            }
        }

        $user->forceFill(array_filter([
            'name' => isset($data['name']) ? trim($data['name']) : null,
            'preferred_locale' => $data['locale'] ?? null,
            'marketing_preferences' => isset($data['marketing'])
                ? array_merge($this->marketing($user), array_map('boolval', $data['marketing']))
                : null,
        ], fn ($value): bool => $value !== null))->save();

        return ApiResponse::success($this->present($user->refresh()));
    }

    public function avatar(Request $request): JsonResponse
    {
        $file = $request->validate(['avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048']])['avatar'];
        $user = $request->user();
        $previous = $user->avatar_path;
        $path = $file->storeAs('avatars/'.$user->id, Str::random(12).'.'.$file->extension(), self::AVATAR_DISK);
        $user->forceFill(['avatar_path' => $path])->save();

        if ($previous !== null) {
            Storage::disk(self::AVATAR_DISK)->delete($previous);
        }

        return ApiResponse::success($this->present($user->refresh()));
    }

    public function removeAvatar(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->avatar_path !== null) {
            Storage::disk(self::AVATAR_DISK)->delete($user->avatar_path);
            $user->forceFill(['avatar_path' => null])->save();
        }

        return ApiResponse::success($this->present($user->refresh()));
    }

    /**
     * Step 1 of an email change: send a code to the new address.
     */
    public function requestEmailChange(Request $request, RequestEmailLoginCode $codes): JsonResponse
    {
        $email = Str::lower(trim($request->validate(['email' => ['required', 'email', 'max:255']])['email']));
        $user = $request->user();

        if ($email === $user->email) {
            return ApiResponse::error('email_unchanged', __('This is already your email.'), 422);
        }

        if (User::query()->where('email', $email)->exists()) {
            return ApiResponse::error('email_taken', __('This email belongs to another Nawy account.'), 422, ['email' => [__('This email belongs to another Nawy account.')]]);
        }

        try {
            $codes->handle($email, 'email_change');
        } catch (EmailLoginCodeRejected $rejected) {
            return ApiResponse::error($rejected->reason, $rejected->getMessage(), $rejected->status());
        }

        return ApiResponse::success(['sent' => true, 'email' => $email], status: 202);
    }

    /**
     * Step 2: check the code and switch the account's email.
     */
    public function confirmEmailChange(Request $request, ConsumeEmailLoginCode $codes): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255'], 'code' => ['required', 'string', 'max:12']]);
        $email = Str::lower(trim($data['email']));
        $user = $request->user();

        try {
            $codes->handle($email, $data['code'], 'email_change');
        } catch (EmailLoginCodeRejected $rejected) {
            return ApiResponse::error($rejected->reason, $rejected->getMessage(), $rejected->status());
        }

        $oldEmail = (string) $user->email;
        $hadPlaceholder = (bool) $user->requires_email;
        $changed = DB::transaction(function () use ($user, $email): bool {
            if (User::query()->where('email', $email)->whereKeyNot($user->id)->lockForUpdate()->exists()) {
                return false;
            }

            // A legacy phone-only account replaces its placeholder address here; email is its login from now on.
            $user->forceFill(['email' => $email, 'email_verified_at' => now(), 'requires_email' => false])->save();

            return true;
        });

        if (! $changed) {
            return ApiResponse::error('email_taken', __('This email belongs to another Nawy account.'), 422);
        }

        // A legacy phone-only account only replaced its placeholder address: nothing changed to tell about.
        if ($oldEmail !== $email && ! $hadPlaceholder) {
            app(SecurityEmails::class)->emailChanged($user, $oldEmail, $email);
        }

        return ApiResponse::success($this->present($user->refresh()));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified' => $user->email_verified_at !== null,
            'requires_email' => (bool) $user->requires_email,
            'phone' => $user->phone,
            'locale' => $user->preferred_locale ?? 'en',
            'avatar_url' => PublicUrl::of($user->avatar_path, self::AVATAR_DISK),
            'marketing' => $this->marketing($user),
            'has_password' => $user->password_set_at !== null,
            'social_accounts' => $user->socialAccounts()->pluck('provider')->values()->all(),
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function marketing(User $user): array
    {
        $saved = (array) ($user->marketing_preferences ?? []);

        return array_combine(self::MARKETING_CHANNELS, array_map(fn (string $channel): bool => (bool) ($saved[$channel] ?? false), self::MARKETING_CHANNELS));
    }
}
