<?php

namespace App\Shared\Notifications\Email\Webhooks;

use Illuminate\Http\Request;

/**
 * A mail provider's bounce / complaint webhook: verify the request is
 * really from the provider, then read its events.
 */
interface EmailWebhookDriver
{
    public function verify(Request $request): bool;

    /**
     * @return array<int, EmailFeedback>
     */
    public function parse(Request $request): array;
}
