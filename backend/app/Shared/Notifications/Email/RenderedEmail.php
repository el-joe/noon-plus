<?php

namespace App\Shared\Notifications\Email;

/**
 * A catalogue email ready to send: subject, HTML, plain text, the template
 * version used (0 = built-in text) and the unsubscribe URLs when the
 * category can be turned off.
 */
final readonly class RenderedEmail
{
    public function __construct(
        public string $subject,
        public string $html,
        public string $text,
        public int $templateVersion,
        public string $locale,
        public ?string $unsubscribeUrl = null,
        public ?string $oneClickUrl = null,
    ) {}
}
