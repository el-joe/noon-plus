<?php

namespace App\Domains\Identity\Support\Social;

use RuntimeException;

/**
 * A social sign-in was refused (D9). `reason` is the API error code.
 */
class SocialSignInFailed extends RuntimeException
{
    public const INVALID_TOKEN = 'social_token_invalid';

    public const EMAIL_UNVERIFIED = 'social_email_unverified';

    public const NOT_CONFIGURED = 'social_provider_unavailable';

    public const INVALID_STATE = 'social_state_invalid';

    public const ACCOUNT_SUSPENDED = 'account_suspended';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return match ($this->reason) {
            self::NOT_CONFIGURED => 503,
            self::ACCOUNT_SUSPENDED => 403,
            default => 422,
        };
    }

    public static function invalidToken(): self
    {
        return new self(self::INVALID_TOKEN, __('We could not verify your sign-in with this provider. Try again.'));
    }
}
