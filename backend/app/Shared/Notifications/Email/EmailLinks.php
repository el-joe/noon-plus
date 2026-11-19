<?php

namespace App\Shared\Notifications\Email;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Countries\Support\DomainMap;
use App\Domains\Panels\Support\Panel;
use Throwable;

/**
 * Absolute links for email buttons and footers: the storefront for
 * customers (`{storefront}/{market}/{path}` in the recipient's language)
 * and the panel host for business users.
 */
class EmailLinks
{
    /**
     * Panel used for each catalogue actor when the caller does not name one.
     */
    public const PANELS = ['seller' => 'partner', 'travel' => 'travel_agency', 'marketer' => 'marketer', 'delivery' => 'delivery', 'admin' => 'admin'];

    public function __construct(private CountryContext $countryContext) {}

    public function storefront(string $path, string $locale, array $query = []): string
    {
        $country = $this->countryContext->has() ? $this->countryContext->current() : null;
        $base = rtrim($this->storefrontBase($locale), '/');
        $market = $country === null ? null : $country->marketSlug(in_array($locale, $country->locales ?? [], true) ? $locale : ($country->default_locale ?: 'en'));
        $url = $base.($market === null ? '' : '/'.$market).'/'.ltrim($path, '/');

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }

    public function panel(?string $panelKey, string $path = '/'): string
    {
        try {
            return Panel::find($panelKey ?? 'partner')->url($path);
        } catch (Throwable) {
            return rtrim((string) config('app.url'), '/').'/'.ltrim($path, '/');
        }
    }

    /**
     * The panel of a catalogue actor, or null for customers.
     */
    public static function panelFor(string $actor, ?string $override = null): ?string
    {
        return $override ?? self::PANELS[$actor] ?? null;
    }

    /**
     * Where the recipient manages preferences / lands after a one-click unsubscribe.
     *
     * @return array{preferences: string, unsubscribe: string}
     */
    public function preferenceLinks(?string $panelKey, string $locale, string $token): array
    {
        if ($panelKey === null) {
            return [
                'preferences' => $this->storefront('account/notifications/settings', $locale),
                'unsubscribe' => $this->storefront('unsubscribe', $locale, ['token' => $token]),
            ];
        }

        return [
            'preferences' => $this->panel($panelKey, 'account/email-preferences'),
            'unsubscribe' => route('email.unsubscribe.show', ['token' => $token]),
        ];
    }

    private function storefrontBase(string $locale): string
    {
        try {
            return app(DomainMap::class)->storefrontBase($this->countryContext->has() ? $this->countryContext->current() : null, $locale);
        } catch (Throwable) {
            return (string) config('nawy.payments.storefront_url');
        }
    }
}
