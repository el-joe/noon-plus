<?php

namespace App\Domains\Content\Support;

/**
 * The page-builder block registry v2 — 51 block types, the single source of
 * truth shared with Figma, Next.js and Flutter (docs/design/page-builder-blocks.md
 * §2, from the SRS §15 block list, the Noon page-builder audit and the
 * design coverage). Kinds: editor (E), ad (A, filled by SponsoredRanker and
 * always labelled), system (S, personalised), container, listing, header.
 * `required` lists the settings a block needs before its page can be
 * published (`a|b` = one of them).
 */
class BlockRegistry
{
    /**
     * @var array<string, array{group: string, kind: string, label: string, required: array<int, string>}>|null
     */
    private static ?array $definitions = null;

    /**
     * @return array<string, array{group: string, kind: string, label: string, required: array<int, string>}>
     */
    public static function all(): array
    {
        if (self::$definitions !== null) {
            return self::$definitions;
        }

        $rows = [
            // A. Hero & sliders
            ['hero_carousel', 'hero', 'editor', ['slides']],
            ['hero_bento', 'hero', 'editor', ['slots']],
            ['banner_slider', 'hero', 'editor', ['slides']],
            ['banner_scroller', 'hero', 'editor', ['items']],
            // B. Banners & grids
            ['promo_banner', 'banners', 'editor', ['items']],
            ['banner_grid', 'banners', 'editor', ['tiles']],
            ['banner_strip', 'banners', 'editor', ['image_d']],
            ['banner_full', 'banners', 'editor', ['image|ad_slot_key|title']],
            ['countdown_strip', 'banners', 'editor', ['text', 'ends_at']],
            // C. Category / brand navigation
            ['tile_scroller', 'navigation', 'editor', ['items|source']],
            ['category_tiles', 'navigation', 'editor', ['items|source']],
            ['category_showcase', 'navigation', 'editor', ['tiles|source']],
            ['brand_carousel', 'navigation', 'editor', ['items|source']],
            ['segment_tabs', 'navigation', 'editor', ['tabs']],
            ['quick_links', 'navigation', 'editor', ['items']],
            // D. Products & deals
            ['product_carousel', 'products', 'editor', ['source']],
            ['product_grid', 'products', 'editor', ['source']],
            ['item_carousel', 'products', 'system', ['source']],
            ['mega_deals', 'products', 'editor', ['flash_sale_id|flash_sale_slug|collection']],
            ['hero_offer_list', 'products', 'editor', ['hero_image', 'source']],
            ['frenzy_deals', 'products', 'editor', ['flash_sale_id|flash_sale_slug']],
            ['deal_grid', 'products', 'editor', ['flash_sale_id|flash_sale_slug']],
            ['offer_cards', 'products', 'editor', ['items|source']],
            ['tile_grid', 'products', 'editor', ['items']],
            // E. Layout containers
            ['panel_row', 'containers', 'container', ['columns']],
            ['section_band', 'containers', 'container', ['children']],
            // F. Sponsored / ads (placed by rules)
            ['sponsored_brand_banner', 'ads', 'ad', []],
            ['sponsored_product', 'ads', 'ad', []],
            ['sponsored_brand_video', 'ads', 'ad', []],
            ['sponsored_video', 'ads', 'ad', []],
            ['ad_slot', 'ads', 'ad', ['ad_slot_key']],
            // G. Listing zone
            ['product_listing', 'listing', 'listing', ['scope']],
            // H. Content, marketplace & engagement
            ['verticals', 'content', 'editor', []],
            ['creators_carousel', 'content', 'system', []],
            ['classified_carousel', 'content', 'editor', ['source']],
            ['travel_packages_carousel', 'content', 'editor', ['source']],
            ['properties_carousel', 'content', 'editor', ['source']],
            ['video_block', 'content', 'editor', ['stream_slug|video_url']],
            ['live_streams_strip', 'content', 'system', []],
            ['html', 'content', 'editor', ['html']],
            ['seo_text', 'content', 'editor', ['text']],
            ['popular_searches', 'content', 'editor', ['source|keywords']],
            ['app_download', 'content', 'editor', []],
            ['newsletter_signup', 'content', 'editor', []],
            ['vendor_spotlight', 'content', 'editor', ['vendor_ids|source']],
            // I. Sub-category navigation
            ['subcategory_tiles', 'subcategories', 'editor', []],
            ['subcategory_hub', 'subcategories', 'editor', []],
            ['subcategory_chips', 'subcategories', 'listing', []],
            // J. Page headers
            ['category_header', 'headers', 'header', []],
            ['brand_header', 'headers', 'header', []],
            ['campaign_hero', 'headers', 'header', ['title', 'ends_at']],
            // K. Category page (design category-page-target): promo slides, promo / brand tiles, gifts, deal columns, sponsored brand row
            ['category_hero', 'hero', 'editor', ['slides']],
            ['category_promo_tiles', 'banners', 'editor', ['hero|tiles']],
            ['category_brand_tiles', 'navigation', 'editor', []],
            ['category_gift_band', 'products', 'editor', []],
            ['category_deal_columns', 'products', 'editor', []],
            ['sponsored_brand_showcase', 'ads', 'ad', []],
        ];

        self::$definitions = [];

        foreach ($rows as [$type, $group, $kind, $required]) {
            self::$definitions[$type] = [
                'group' => $group,
                'kind' => $kind,
                'label' => ucfirst(str_replace('_', ' ', $type)),
                'required' => $required,
            ];
        }

        return self::$definitions;
    }

    public static function has(string $type): bool
    {
        return isset(self::all()[$type]);
    }

    /**
     * @return array{group: string, kind: string, label: string, required: array<int, string>}|null
     */
    public static function get(string $type): ?array
    {
        return self::all()[$type] ?? null;
    }

    /**
     * Header blocks only go in a `header` zone (with sub-category tiles /
     * hub), listing blocks only in a `listing` zone, ads anywhere but the
     * header; everything else in content zones.
     */
    public static function allowedIn(string $type, string $zone): bool
    {
        $definition = self::get($type);

        return match ($definition['kind'] ?? null) {
            null => false,
            'header' => $zone === 'header',
            'listing' => $zone === 'listing',
            'ad' => $zone !== 'header',
            default => $zone === 'header' ? $definition['group'] === 'subcategories' : $zone !== 'listing',
        };
    }

    /**
     * Settings still missing for publishing ([] = ready).
     *
     * @param  array<string, mixed>  $settings
     * @return array<int, string>
     */
    public static function missing(string $type, array $settings): array
    {
        $missing = [];

        foreach (self::get($type)['required'] ?? [] as $requirement) {
            $options = explode('|', $requirement);
            $present = array_filter($options, fn (string $key): bool => isset($settings[$key]) && $settings[$key] !== '' && $settings[$key] !== []);

            if ($present === []) {
                $missing[] = implode(' or ', $options);
            }
        }

        return $missing;
    }

    /**
     * Block types grouped for the editor's "add block" menu.
     *
     * @return array<string, array<string, string>>
     */
    public static function grouped(): array
    {
        $groups = [];

        foreach (self::all() as $type => $definition) {
            $groups[$definition['group']][$type] = $definition['label'];
        }

        return $groups;
    }
}
