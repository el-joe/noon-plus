<?php

namespace App\Domains\Content\Support;

use App\Domains\Content\Enums\PageTemplate;
use App\Domains\Content\Models\Page;
use App\Domains\Countries\Models\Country;

/**
 * Preview links of page-builder drafts: a signed, expiring token for one
 * country and page address (every language of it), which `GET pages/{slug}`
 * accepts as `?preview=` to serve the current draft revision uncached.
 */
class PagePreview
{
    public const TTL_SECONDS = 7200;

    public function token(Country $country, string $slug, ?int $expires = null, bool $showHidden = false): string
    {
        $payload = $country->code.'|'.$slug.'|'.($expires ?? now()->addSeconds(self::TTL_SECONDS)->getTimestamp()).($showHidden ? '|h' : '');

        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=').'.'.$this->signature($payload);
    }

    public function valid(?string $token, Country $country, string $slug): bool
    {
        return $this->parts($token, $country, $slug) !== null;
    }

    /**
     * Whether a valid token also shows sections an editor hid ("Show hidden").
     */
    public function showsHidden(?string $token, Country $country, string $slug): bool
    {
        return ($this->parts($token, $country, $slug)[3] ?? null) === 'h';
    }

    /**
     * @return array<int, string>|null
     */
    private function parts(?string $token, Country $country, string $slug): ?array
    {
        if (! is_string($token) || ! str_contains($token, '.')) {
            return null;
        }

        [$encoded, $signature] = explode('.', $token, 2);
        $payload = (string) base64_decode(strtr($encoded, '-_', '+/'), true);
        $parts = explode('|', $payload);

        $valid = in_array(count($parts), [3, 4], true)
            && hash_equals($this->signature($payload), $signature)
            && $parts[0] === $country->code
            && $parts[1] === $slug
            && (int) $parts[2] >= now()->getTimestamp();

        return $valid ? $parts : null;
    }

    /**
     * Storefront address of the page in a language, with the preview token.
     */
    public function url(Page $page, Country $country, string $locale, bool $showHidden = false): string
    {
        $path = $page->template === PageTemplate::Home ? '' : '/'.$page->slug;

        $origin = rtrim((string) config('nawy.payments.storefront_url'), '/');

        // A secure admin page can only frame a secure storefront (mixed content).
        if (request()->isSecure()) {
            $origin = (string) preg_replace('#^http://#i', 'https://', $origin);
        }

        return $origin.'/'.$country->marketSlug($locale).$path.'?preview='.$this->token($country, $page->slug, null, $showHidden);
    }

    private function signature(string $payload): string
    {
        return hash_hmac('sha256', 'page-preview|'.$payload, (string) config('app.key'));
    }
}
