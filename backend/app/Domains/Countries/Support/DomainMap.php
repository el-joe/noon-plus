<?php

namespace App\Domains\Countries\Support;

use App\Domains\Countries\Enums\DomainKind;
use App\Domains\Countries\Http\Middleware\MapCustomHost;
use App\Domains\Countries\Models\Country;
use App\Domains\Countries\Models\Domain;
use App\Domains\Panels\Support\Panel;
use App\Shared\Http\StorefrontLinks;
use Illuminate\Support\Facades\Cache;

/**
 * Hostname → market / panel / portal (QC-044, MASTER_PLAN §5). The whole
 * map of active domains is cached (forgotten on every change) and read by:
 *
 * - the website proxy through `GET /api/v1/domains/resolve?host=` — a
 *   custom storefront host (e.g. a ccTLD `nawy.om`) opens its market;
 * - {@see MapCustomHost} — a panel
 *   alias (`admin.omn.nawy.com`) or a `redirect_to` host answers with a
 *   redirect to the canonical host (panel: with `?country=`);
 * - {@see StorefrontLinks} and the payment return URL —
 *   emails and gateway returns use the country's primary storefront host.
 */
class DomainMap
{
    private const CACHE_KEY = 'domains:map:v1';

    /**
     * @return array<string, array{hostname: string, kind: string, country: string|null, locale: string|null, market: string|null, panel: string|null, is_primary: bool, redirect_to: string|null}>
     */
    public function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function (): array {
            $map = [];

            foreach (Domain::query()->where('is_active', true)->with('country')->orderBy('id')->get() as $domain) {
                $country = $domain->country;
                $locale = $country === null ? null : ($domain->locale ?: $country->default_locale);
                $map[$domain->hostname] = [
                    'hostname' => $domain->hostname,
                    'kind' => $domain->kind->value,
                    'country' => $country?->code,
                    'locale' => $locale,
                    'market' => $country === null || $locale === null ? null : $country->marketSlug($locale),
                    'panel' => $domain->panel,
                    'is_primary' => $domain->is_primary,
                    'redirect_to' => $domain->redirect_to,
                ];
            }

            return $map;
        });
    }

    /**
     * @return array{hostname: string, kind: string, country: string|null, locale: string|null, market: string|null, panel: string|null, is_primary: bool, redirect_to: string|null}|null
     */
    public function resolve(?string $host): ?array
    {
        $host = self::normalise($host);

        return $host === null ? null : ($this->all()[$host] ?? null);
    }

    /**
     * The country's primary storefront host, if one is mapped.
     */
    public function primaryStorefront(Country $country, ?string $locale = null): ?string
    {
        $candidates = array_filter($this->all(), fn (array $entry): bool => $entry['kind'] === DomainKind::Storefront->value && $entry['country'] === $country->code);

        if ($candidates === []) {
            return null;
        }

        $primary = array_filter($candidates, fn (array $entry): bool => $entry['is_primary'] && $entry['redirect_to'] === null);
        $pool = $primary !== [] ? $primary : array_filter($candidates, fn (array $entry): bool => $entry['redirect_to'] === null);
        $byLocale = $locale === null ? [] : array_filter($pool, fn (array $entry): bool => $entry['locale'] === $locale);

        return (array_values($byLocale)[0] ?? array_values($pool)[0] ?? null)['hostname'] ?? null;
    }

    /**
     * `https://{primary host}` for the country, else the configured storefront URL.
     */
    public function storefrontBase(?Country $country = null, ?string $locale = null): string
    {
        $configured = rtrim((string) config('nawy.payments.storefront_url'), '/');
        $host = $country === null ? null : $this->primaryStorefront($country, $locale);

        if ($host === null) {
            return $configured;
        }

        return (parse_url($configured, PHP_URL_SCHEME) ?: 'https').'://'.$host;
    }

    /**
     * Where a request on `$host` must go instead (alias / redirect host), or null to serve it.
     */
    public function redirectFor(?string $host, string $pathAndQuery): ?string
    {
        $entry = $this->resolve($host);

        if ($entry === null) {
            return null;
        }

        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';

        if ($entry['redirect_to'] !== null) {
            return $scheme.'://'.$entry['redirect_to'].$pathAndQuery;
        }

        if ($entry['kind'] !== DomainKind::Panel->value || $entry['panel'] === null || config("nawy.panels.{$entry['panel']}") === null) {
            return null;
        }

        $url = $scheme.'://'.Panel::find($entry['panel'])->host.$pathAndQuery;

        if ($entry['country'] !== null) {
            $url .= (str_contains($pathAndQuery, '?') ? '&' : '?').'country='.$entry['country'];
        }

        return $url;
    }

    /**
     * Lower-case host without port or trailing dot; null when not a hostname.
     */
    public static function normalise(?string $host): ?string
    {
        $host = strtolower(trim((string) $host));
        $host = rtrim(preg_replace('/:\d+$/', '', $host) ?? '', '.');

        return self::isHostname($host) ? $host : null;
    }

    public static function isHostname(string $host): bool
    {
        return strlen($host) <= 253 && (bool) preg_match('/^(?=.{1,253}$)(?!-)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/', $host);
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
