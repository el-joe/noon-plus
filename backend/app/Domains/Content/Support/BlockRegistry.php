<?php

namespace App\Domains\Content\Support;

/**
 * The page-builder block registry v2 — 57 block types, the single source of
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
     * Editor groups (add-section gallery categories) in display order.
     */
    public const GROUPS = [
        'hero' => 'Hero & sliders',
        'banners' => 'Banners & grids',
        'navigation' => 'Category & brand navigation',
        'products' => 'Products & deals',
        'containers' => 'Layout containers',
        'ads' => 'Sponsored & ads',
        'listing' => 'Listing',
        'content' => 'Content & engagement',
        'subcategories' => 'Sub-category navigation',
        'headers' => 'Page headers',
    ];

    /**
     * Editor name and one-line description of each block type.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const LABELS = [
        'hero_carousel' => ['Hero carousel', 'Large full-width slides with autoplay and dots.'],
        'hero_bento' => ['Hero bento', 'Main carousel with side banners, or a row of styled cards.'],
        'banner_slider' => ['Banner slider', 'Thin, half or full height banner slider.'],
        'banner_scroller' => ['Banner scroller', '2–4 banners visible with arrows.'],
        'promo_banner' => ['Promo banners', 'Single, side by side, 1 big + 2×2 or stacked banners or text cards.'],
        'banner_grid' => ['Banner grid', 'Grid of image tiles: columns, showcase, brand tiles, quick links.'],
        'banner_strip' => ['Banner strip', 'Full-width image with optional clickable hotspots.'],
        'banner_full' => ['Wide banner', 'One wide banner, a styled text banner or a sponsored ad slot.'],
        'countdown_strip' => ['Countdown strip', 'Thin campaign bar with a live countdown.'],
        'tile_scroller' => ['Tile scroller', 'Round or square category / brand tiles in 1 or 2 rows.'],
        'category_tiles' => ['Category tiles', 'Square category shortcuts with arrows.'],
        'category_showcase' => ['Category showcase', 'Title, 7 tiles and View all.'],
        'brand_carousel' => ['Brand carousel', 'Brand logos with offer captions.'],
        'segment_tabs' => ['Segment tabs', 'Women / Men / Kids style text tabs.'],
        'quick_links' => ['Quick links', 'Small icon and text chips.'],
        'product_carousel' => ['Product carousel', 'Products from a source in 1 or 2 rows.'],
        'product_grid' => ['Product grid', 'Products from a source in 4–6 columns.'],
        'item_carousel' => ['Recently viewed', 'The shopper\'s recently viewed products.'],
        'mega_deals' => ['Mega deals', 'Coloured band with a countdown and flash sale deals.'],
        'hero_offer_list' => ['Hero + offers', 'Hero visual with a scroller of offers.'],
        'frenzy_deals' => ['Frenzy deals', 'Limited-quantity deals with a timer.'],
        'deal_grid' => ['Deal grid 2×2', 'Four mini deals with a countdown and All deals.'],
        'offer_cards' => ['Offer cards', 'Coupon cards with code badges.'],
        'tile_grid' => ['Tile grid 2×2', 'Four image tiles with title and subtitle.'],
        'panel_row' => ['Panel row', '2–3 columns, each holding a block.'],
        'section_band' => ['Section band', 'Coloured band wrapping several blocks with a title.'],
        'sponsored_brand_banner' => ['Sponsored brand banner', 'Paid brand banner from ad bookings.'],
        'sponsored_product' => ['Sponsored products', 'Paid product tiles from ad bookings.'],
        'sponsored_brand_video' => ['Sponsored brand video', 'Paid brand video from ad bookings.'],
        'sponsored_video' => ['Sponsored video', 'Paid video from ad bookings.'],
        'ad_slot' => ['Ad slot', 'Any ad slot by its key.'],
        'product_listing' => ['Product listing', 'Filters, sort and the product results.'],
        'verticals' => ['Verticals', 'Classifieds, Travel, Real estate and Creators cards.'],
        'creators_carousel' => ['Creators carousel', 'Top creators of the country.'],
        'classified_carousel' => ['Classifieds carousel', 'Latest or featured classified ads.'],
        'travel_packages_carousel' => ['Travel packages', 'Travel packages by trip type or destination.'],
        'properties_carousel' => ['Properties carousel', 'Featured property listings.'],
        'video_block' => ['Video', 'A video from the media library.'],
        'live_streams_strip' => ['Live streams', 'Live now and scheduled streams.'],
        'html' => ['Rich text', 'Formatted text and links.'],
        'seo_text' => ['SEO text', 'Collapsible SEO copy with Read more.'],
        'popular_searches' => ['Popular searches', 'Keyword chips linking to search.'],
        'app_download' => ['App download', 'App Store and Google Play badges.'],
        'newsletter_signup' => ['Newsletter sign-up', 'Email opt-in form.'],
        'vendor_spotlight' => ['Seller spotlight', 'Featured sellers.'],
        'subcategory_tiles' => ['Sub-category tiles', 'Image tiles of the category\'s children.'],
        'subcategory_hub' => ['Sub-category hub', 'Grouped cards for deep category trees.'],
        'subcategory_chips' => ['Sub-category chips', 'Chip bar above the listing.'],
        'category_header' => ['Category header', 'Dark band with breadcrumb, name and description.'],
        'brand_header' => ['Store header', 'Cover, logo, badge and store tabs.'],
        'campaign_hero' => ['Campaign hero', 'Title, subline, countdown and jump chips.'],
        'category_hero' => ['Category promo slides', 'Coloured promo cards with dots.'],
        'category_promo_tiles' => ['Category promo tiles', 'One big card and four coloured tiles.'],
        'category_brand_tiles' => ['Category brand tiles', 'Brands of the category with offer captions.'],
        'category_gift_band' => ['Gift band', '"Under AED 99" band with product mini-cards.'],
        'category_deal_columns' => ['Deal columns', 'Mega deals with one column per sub-category.'],
        'sponsored_brand_showcase' => ['Sponsored brand showcase', '"Explore more" sponsored brand row.'],
    ];

    /**
     * @var array<string, array{group: string, kind: string, label: string, description: string, required: array<int, string>}>|null
     */
    private static ?array $definitions = null;

    /**
     * @return array<string, array{group: string, kind: string, label: string, description: string, required: array<int, string>}>
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
            ['vendor_spotlight', 'content', 'editor', ['items|vendor_ids|source']],
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
                'label' => self::LABELS[$type][0] ?? ucfirst(str_replace('_', ' ', $type)),
                'description' => self::LABELS[$type][1] ?? '',
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
     * @return array{group: string, kind: string, label: string, description: string, required: array<int, string>}|null
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
