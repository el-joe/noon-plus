<?php

namespace App\Domains\Migration\Support;

/**
 * Legacy page-builder block types → the new block registry (plan §6
 * "Content", MG1 `block_type_map`). A block whose type has no equivalent,
 * or that would not carry its required settings, becomes an `html` block
 * flagged for review; a page with a flagged block is imported as a draft.
 * The legacy config is always kept under `settings.legacy`.
 */
class BlockTypeMap
{
    /**
     * @var array<string, string>
     */
    public const MAP = [
        'hero_slider' => 'hero_carousel',
        'full_banner' => 'banner_full',
        'ad_images_2col' => 'banner_grid',
        'ad_images_4col' => 'banner_grid',
        'brand_strip' => 'brand_carousel',
        'category_pills' => 'category_tiles',
        'countdown_deal' => 'countdown_strip',
        'countdown_timer' => 'countdown_strip',
        'deal_of_day' => 'product_carousel',
        'product_row' => 'product_carousel',
        'newsletter_signup' => 'newsletter_signup',
        'search_trends' => 'popular_searches',
        'text_block' => 'html',
        'video_banner' => 'video_block',
        'flash_sale' => 'product_carousel',
    ];

    /**
     * @param  array<string, mixed>  $config  legacy block config
     * @param  array<int, int>  $listingIds  migrated listings of the block's products
     * @return array{type: string, settings: array<string, mixed>, flagged: bool}
     */
    public static function convert(string $legacyType, array $config, string $locale, array $listingIds = []): array
    {
        $title = (string) ($config["title_{$locale}"] ?? $config['title_en'] ?? $config['title'] ?? '');
        $base = array_filter(['title' => $title], fn (string $value): bool => $value !== '') + ['legacy' => ['type' => $legacyType, 'config' => $config]];
        $type = self::MAP[$legacyType] ?? null;

        $settings = match ($type) {
            'product_carousel' => ['source' => $listingIds === [] ? 'new_arrivals' : 'manual', 'listing_ids' => $listingIds, 'max_items' => (int) ($config['max_items'] ?? 12)],
            'brand_carousel', 'category_tiles' => ['source' => 'auto', 'max_items' => (int) ($config['max_items'] ?? 12)],
            'countdown_strip' => ['text' => $title, 'ends_at' => $config['ends_at'] ?? null, 'background' => $config['background_color'] ?? null, 'color' => $config['text_color'] ?? null],
            'newsletter_signup' => ['subtitle' => $config["subtitle_{$locale}"] ?? null],
            'popular_searches' => ['source' => 'trending'],
            'html' => ['html' => (string) ($config["content_html_{$locale}"] ?? $config['content_html_en'] ?? $config["content_{$locale}"] ?? $config['html'] ?? '')],
            'video_block' => ['video_url' => $config['video_url'] ?? $config['url'] ?? null],
            'hero_carousel' => ['slides' => $config['slides'] ?? []],
            'banner_full' => ['image' => $config['image'] ?? $config["image_{$locale}"] ?? $config['image_url'] ?? null, 'link' => $config['link_url'] ?? $config['link'] ?? null],
            'banner_grid' => ['tiles' => $config['images'] ?? $config['items'] ?? []],
            default => null,
        };

        if ($type === null || $settings === null) {
            return ['type' => 'html', 'settings' => ['html' => '', 'needs_review' => true] + $base, 'flagged' => true];
        }

        $settings = array_filter($settings, fn ($value): bool => $value !== null) + $base;
        $flagged = in_array(null, array_map(fn (string $required) => self::satisfied($required, $settings), self::required($type)), true);

        if ($flagged) {
            $settings['needs_review'] = true;
        }

        return ['type' => $type, 'settings' => $settings, 'flagged' => $flagged];
    }

    /**
     * @return array<int, string>
     */
    private static function required(string $type): array
    {
        return match ($type) {
            'hero_carousel' => ['slides'],
            'banner_full' => ['image'],
            'banner_grid' => ['tiles'],
            'countdown_strip' => ['text', 'ends_at'],
            'html' => ['html'],
            'video_block' => ['video_url'],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private static function satisfied(string $key, array $settings): ?bool
    {
        $value = $settings[$key] ?? null;

        return $value === null || $value === '' || $value === [] ? null : true;
    }
}
