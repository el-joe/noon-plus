<?php

namespace App\Domains\Newsletter\Http\Controllers;

use App\Domains\Newsletter\Http\Requests\NewsletterTokenRequest;
use App\Domains\Newsletter\Http\Requests\SubscribeNewsletterRequest;
use App\Domains\Newsletter\Support\NewsletterSubscriptions;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiResponse;
use App\Shared\Settings\VerticalSettings;
use Illuminate\Http\JsonResponse;

/**
 * Newsletter API (G-W12, page-builder block `newsletter_signup`):
 * subscribe (always 202 with the same answer, so it never tells whether
 * an address was already subscribed; with double opt-in an email is sent),
 * confirm and unsubscribe with the emailed token.
 */
class NewsletterController extends Controller
{
    public function __construct(private NewsletterSubscriptions $subscriptions) {}

    public function subscribe(SubscribeNewsletterRequest $request, VerticalSettings $settings): JsonResponse
    {
        $this->subscriptions->subscribe($request->validated('email'), (string) $request->attributes->get('market'), $request->validated('source'));
        $pending = (bool) $settings->get('newsletter.double_opt_in');

        return ApiResponse::success([
            'status' => $pending ? 'confirmation_sent' : 'subscribed',
            'message' => $pending ? __('Check your inbox to confirm your subscription.') : __('You are subscribed.'),
        ], status: 202);
    }

    public function confirm(NewsletterTokenRequest $request): JsonResponse
    {
        $subscription = $this->subscriptions->confirm($request->validated('token'));

        return $subscription === null
            ? ApiResponse::error('invalid_token', __('This confirmation link is not valid.'), 404)
            : ApiResponse::success(['status' => 'subscribed', 'email' => $subscription->email]);
    }

    public function unsubscribe(NewsletterTokenRequest $request): JsonResponse
    {
        $subscription = $this->subscriptions->unsubscribe($request->validated('token'));

        return $subscription === null
            ? ApiResponse::error('invalid_token', __('This link is not valid.'), 404)
            : ApiResponse::success(['status' => 'unsubscribed']);
    }
}
