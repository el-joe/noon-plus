<?php

namespace App\Domains\Identity\Support\Social;

/**
 * Who the provider says signed in (D9), after server-side verification.
 */
final readonly class SocialIdentity
{
    public function __construct(
        public string $provider,
        public string $subject,
        public ?string $email,
        public bool $emailVerified,
        public ?string $name = null,
    ) {}
}
