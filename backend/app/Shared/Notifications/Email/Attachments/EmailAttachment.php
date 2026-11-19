<?php

namespace App\Shared\Notifications\Email\Attachments;

/**
 * Builds a file for an email at send time (so the queued job carries only
 * the arguments, e.g. an order number). Runs inside the email's country.
 */
interface EmailAttachment
{
    /**
     * @param  array<string, mixed>  $arguments
     * @return array{data: string, name: string, mime: string}|null
     */
    public function build(array $arguments): ?array;
}
