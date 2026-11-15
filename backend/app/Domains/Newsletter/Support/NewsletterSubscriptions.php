<?php

namespace App\Domains\Newsletter\Support;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Newsletter\Models\NewsletterSubscription;
use App\Domains\Newsletter\Notifications\ConfirmNewsletterSubscription;
use App\Shared\Settings\VerticalSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Newsletter sign-up per country with double opt-in (setting
 * `newsletter.double_opt_in`, default on). The answer never reveals
 * whether an address was already subscribed. The emailed link opens the
 * storefront `/{market}/newsletter/confirm?token=…`, which calls
 * `POST newsletter/confirm`; the same token unsubscribes.
 */
class NewsletterSubscriptions
{
    public function __construct(private VerticalSettings $settings, private CountryContext $countryContext) {}

    public function subscribe(string $email, string $market, ?string $source = null): NewsletterSubscription
    {
        $email = mb_strtolower(trim($email));
        $doubleOptIn = (bool) $this->settings->get('newsletter.double_opt_in');
        $token = null;

        $subscription = DB::connection((new NewsletterSubscription)->getConnectionName())->transaction(function () use ($email, $source, $doubleOptIn, &$token): NewsletterSubscription {
            $subscription = NewsletterSubscription::query()->where('email', $email)->lockForUpdate()->first();

            if ($subscription?->status === NewsletterSubscription::CONFIRMED) {
                return $subscription;
            }

            $resendAfter = $this->settings->int('newsletter.resend_after_seconds');

            if ($subscription?->status === NewsletterSubscription::PENDING && $subscription->confirmation_sent_at?->gt(now()->subSeconds($resendAfter))) {
                return $subscription;
            }

            $token = Str::random(48);
            $subscription ??= new NewsletterSubscription(['email' => $email]);
            $subscription->fill([
                'locale' => app()->getLocale() === 'ar' ? 'ar' : 'en',
                'source' => $source ?? $subscription->source,
                'token_hash' => hash('sha256', $token),
                'status' => $doubleOptIn ? NewsletterSubscription::PENDING : NewsletterSubscription::CONFIRMED,
                'confirmation_sent_at' => $doubleOptIn ? now() : null,
                'confirmed_at' => $doubleOptIn ? null : now(),
                'unsubscribed_at' => null,
            ])->save();

            return $subscription;
        });

        if ($token !== null && $doubleOptIn) {
            $url = rtrim((string) config('nawy.payments.storefront_url'), '/').'/'.$market.'/newsletter/confirm?token='.$token;
            Notification::route('mail', $email)->notify(new ConfirmNewsletterSubscription($url, $this->countryContext->current()->name_en));
        }

        return $subscription;
    }

    public function confirm(string $token): ?NewsletterSubscription
    {
        $subscription = $this->byToken($token);

        if ($subscription === null || $subscription->status === NewsletterSubscription::UNSUBSCRIBED) {
            return null;
        }

        if ($subscription->status === NewsletterSubscription::PENDING) {
            $subscription->forceFill(['status' => NewsletterSubscription::CONFIRMED, 'confirmed_at' => now()])->save();
        }

        return $subscription;
    }

    public function unsubscribe(string $token): ?NewsletterSubscription
    {
        $subscription = $this->byToken($token);
        $subscription?->forceFill(['status' => NewsletterSubscription::UNSUBSCRIBED, 'unsubscribed_at' => now()])->save();

        return $subscription;
    }

    private function byToken(string $token): ?NewsletterSubscription
    {
        return NewsletterSubscription::query()->where('token_hash', hash('sha256', $token))->first();
    }
}
