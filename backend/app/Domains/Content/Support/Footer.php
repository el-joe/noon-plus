<?php

namespace App\Domains\Content\Support;

use App\Domains\Countries\Models\Country;
use App\Domains\Settings\SettingsService;

/**
 * Storefront footer settings per country × language (settings catalogue
 * `footer_settings`, SRS §15): link columns, social links, copyright line.
 * Stored through the audited settings service; the global value serves
 * countries without their own.
 */
class Footer
{
    public const NETWORKS = ['instagram', 'x', 'facebook', 'tiktok', 'youtube', 'snapchat', 'linkedin', 'whatsapp'];

    public function __construct(private SettingsService $settings) {}

    /**
     * @return array{columns: array<int, array{title: string, links: array<int, array{label: string, url: string}>}>, social: array<string, string>, copyright: string|null}
     */
    public function get(Country $country, string $locale): array
    {
        $value = (array) $this->settings->get("footer_settings.{$locale}", [], $country);

        return ['columns' => $value['columns'] ?? [], 'social' => $value['social'] ?? [], 'copyright' => $value['copyright'] ?? null];
    }

    /**
     * @param  array{columns: array<int, array{title: string, links: array<int, array{label: string, url: string}>}>, social: array<string, string>, copyright: string|null}  $footer
     */
    public function save(Country $country, string $locale, array $footer, int $userId): void
    {
        $this->settings->set("footer_settings.{$locale}", $footer, $country, $userId);
    }

    /**
     * Parse the admin's "Label | URL" lines into links.
     *
     * @return array<int, array{label: string, url: string}>
     */
    public static function parseLinks(string $lines): array
    {
        $links = [];

        foreach (preg_split('/\R/', $lines) ?: [] as $line) {
            $parts = array_map('trim', explode('|', $line, 2));

            if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '' && (str_starts_with($parts[1], '/') || filter_var($parts[1], FILTER_VALIDATE_URL))) {
                $links[] = ['label' => mb_substr($parts[0], 0, 80), 'url' => mb_substr($parts[1], 0, 255)];
            }
        }

        return $links;
    }
}
