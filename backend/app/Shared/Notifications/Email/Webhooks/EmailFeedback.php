<?php

namespace App\Shared\Notifications\Email\Webhooks;

/**
 * One delivery event reported by the mail provider.
 *
 * @property-read string $type `delivered` | `bounce` | `complaint` | `open`
 */
final readonly class EmailFeedback
{
    public function __construct(
        public string $type,
        public ?string $email = null,
        public ?string $messageId = null,
        public ?int $logId = null,
        public bool $permanent = false,
        public ?string $reason = null,
    ) {}
}
