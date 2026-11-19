<?php

namespace App\Shared\Http;

use App\Domains\Countries\Support\CountryContext;
use App\Domains\Countries\Support\DomainMap;

/**
 * Links into the customer website (`NAWY_STOREFRONT_URL`, config
 * `nawy.payments.storefront_url`) for emails: `{storefront}/{market}/{path}`.
 * The market is the request's (`uae-en`) when there is one, otherwise the
 * current country with the given (or current) language.
 */
class StorefrontLinks
{
    public function __construct(private CountryContext $countryContext) {}

    /**
     * @param  array<string, scalar|null>  $query
     */
    public function to(string $path, array $query = [], ?string $locale = null): string
    {
        $url = $this->base().'/'.$this->market($locale).'/'.ltrim($path, '/');

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }

    public function market(?string $locale = null): string
    {
        $requested = request()?->attributes->get('market');

        if ($locale === null && is_string($requested) && $requested !== '') {
            return $requested;
        }

        $country = $this->countryContext->current();
        $locale ??= app()->getLocale();

        return $country->marketSlug(in_array($locale, $country->locales ?? [], true) ? $locale : ($country->default_locale ?: 'en'));
    }

    /**
     * QC-044: the current country's primary storefront domain when one is
     * mapped (Admin → Countries → Domains), else NAWY_STOREFRONT_URL.
     */
    public function base(): string
    {
        return app(DomainMap::class)->storefrontBase($this->countryContext->has() ? $this->countryContext->current() : null, app()->getLocale());
    }
}
