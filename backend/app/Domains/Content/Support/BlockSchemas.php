<?php

namespace App\Domains\Content\Support;

use App\Domains\Content\Support\BlockField as F;

/**
 * Settings schema of every page-builder block type (57/57), derived from
 * what the storefront renderer (`website/features/page-builder/blocks/*`)
 * and {@see BlockResolver} actually read. The admin editor renders forms
 * from it and {@see BlockSettings} validates and stores the answers; keys
 * the schema does not know are kept as they are.
 */
class BlockSchemas
{
    /**
     * @var array<string, array<int, F>>|null
     */
    private static ?array $schemas = null;

    public const PRODUCT_SOURCES = [
        'manual' => 'Hand-picked products',
        'new_arrivals' => 'New arrivals',
        'category' => 'Category',
        'brand' => 'Brand',
        'bestsellers' => 'Bestsellers',
        'top_rated' => 'Top rated',
        'deals' => 'Deals',
        'trending' => 'Trending',
        'recommended' => 'Recommended',
        'coupon' => 'Products of a coupon',
    ];

    public const TONES = ['light' => 'Light yellow', 'brand' => 'Brand yellow', 'dark' => 'Dark', 'subtle' => 'Light blue', 'success' => 'Light green', 'surface' => 'White'];

    public const STICKER_TONES = ['' => 'Match the card', 'dark' => 'Dark', 'brand' => 'Brand yellow', 'danger' => 'Red', 'info' => 'Blue', 'success' => 'Green'];

    /**
     * Editor groups in display order.
     *
     * @return array<string, string>
     */
    public static function groups(): array
    {
        return ['content' => 'Content', 'products' => 'Products', 'design' => 'Design', 'visibility' => 'Visibility & schedule', 'advanced' => 'Advanced'];
    }

    /**
     * @return array<int, F>
     */
    public static function for(string $type): array
    {
        return self::all()[$type] ?? [];
    }

    /**
     * @return array<string, array<int, F>>
     */
    public static function all(): array
    {
        if (self::$schemas !== null) {
            return self::$schemas;
        }

        $schemas = [
            // A. Hero & sliders
            'hero_carousel' => [
                F::repeater('slides', 'Slides', self::imageItem(), 1, 12)->required()->itemTitle(['label', 'link']),
                F::number('autoplay', 'Autoplay', 0, 30)->unit('s')->default(5)->help('0 turns autoplay off.')->in('design'),
            ],
            'hero_bento' => [
                F::select('variant', 'Layout', ['' => 'Main carousel + 2 side banners', 'cards' => 'Row of styled cards']),
                F::repeater('slots', 'Slots', [
                    ...self::imageItem(),
                    F::repeater('slides', 'Slides (main slot only)', self::imageItem(), 0, 12)->itemTitle(['label', 'link']),
                ], 1, 3)->showIf(['variant' => '!cards'])->required()->help('The first slot is the main carousel, the next two the side banners.'),
                F::repeater('slots', 'Cards', [
                    ...self::card(),
                    F::repeater('items', 'Stacked cards (instead of one card)', self::card(), 0, 3)->itemTitle(['title']),
                ], 1, 8)->showIf(['variant' => 'cards'])->required()->itemTitle(['title']),
            ],
            'banner_slider' => [
                ...self::heading(),
                F::repeater('slides', 'Slides', self::imageItem(), 1, 12)->required()->itemTitle(['label', 'link']),
                F::select('height', 'Height', ['full' => 'Full (290px)', 'half' => 'Half (240px)', 'thin' => 'Thin strip (110px)'])->default('full')->in('design'),
                F::select('width', 'Banner width', ['full' => 'One banner per slide', 'half' => 'Two banners side by side'])->default('full')->in('design'),
                ...self::surface(),
            ],
            'banner_scroller' => [
                ...self::heading(),
                F::repeater('items', 'Banners', self::imageItem(), 1, 20)->required()->itemTitle(['label', 'link']),
                F::number('visible', 'Banners visible on desktop', 2, 4)->default(3)->in('design'),
                ...self::surface(),
            ],
            // B. Banners & grids
            'promo_banner' => [
                ...self::heading(),
                F::select('variant', 'Style', ['' => 'Image banners', 'cards' => 'Styled text cards']),
                F::select('layout', 'Arrangement', ['' => 'Automatic', 'single' => 'Single', 'n_up' => 'Side by side', 'big_2x2' => '1 big + 2×2', 'stacked' => 'Stacked'])->showIf(['variant' => '!cards'])->in('design'),
                F::select('layout', 'Arrangement', ['' => 'Side by side', 'stacked' => 'Stacked'])->showIf(['variant' => 'cards'])->in('design'),
                F::repeater('items', 'Banners', self::imageItem(), 1, 6)->showIf(['variant' => '!cards'])->required()->itemTitle(['label', 'link']),
                F::repeater('items', 'Cards', self::card(), 1, 6)->showIf(['variant' => 'cards'])->required()->itemTitle(['title']),
                ...self::surface(),
            ],
            'banner_grid' => [
                F::group('header', 'Header', [F::text('label', 'Header title')->translatable()]),
                ...self::heading(false),
                F::select('variant', 'Grid', ['n_up' => 'Columns (2–6)', 'showcase_1x7' => 'Showcase 1×7', 'brand_tiles' => 'Brand tiles', 'quick_links' => 'Quick links', 'segment_tabs' => 'Segment tabs', 'wide' => 'One wide banner', 'two_by_two' => '2×2'])->default('n_up'),
                F::number('columns', 'Columns', 2, 6)->showIf(['variant' => ['n_up', '']])->in('design'),
                F::repeater('tiles', 'Tiles', self::imageItem(true), 1, 12)->required()->itemTitle(['label', 'link']),
                ...self::surface(),
            ],
            'banner_strip' => [
                F::image('image_d', 'Image (desktop)')->withAlt()->required(),
                F::image('image_m', 'Image (mobile)')->help('Optional crop for phones.'),
                F::link('link', 'Link')->help('Ignored when hotspots are set.'),
                F::number('height', 'Height (0 = natural)', 0, 800)->unit('px')->in('design'),
                F::repeater('hotspots', 'Hotspots (several links on one image)', [
                    F::link('link', 'Link')->required(),
                    F::text('label', 'Accessible label')->translatable(),
                    F::number('x', 'Left (%)', 0, 100)->default(0),
                    F::number('y', 'Top (%)', 0, 100)->default(0),
                    F::number('w', 'Width (%)', 1, 100)->default(10),
                    F::number('h', 'Height (%)', 1, 100)->default(100),
                ], 0, 12)->itemTitle(['label', 'link']),
            ],
            'banner_full' => [
                F::picker('ad_slot', 'ad_slot_key', 'Sponsored ad slot')->help('Fills the banner from live ad bookings (always labelled). Leave empty for your own banner.'),
                F::text('title', 'Title (styled text banner)')->translatable()->showIf(['ad_slot_key' => ''])->help('With a title the banner renders as a styled text card.'),
                F::text('subtitle', 'Subtitle')->translatable()->showIf(['ad_slot_key' => '', 'title' => '!']),
                F::text('sticker', 'Sticker')->translatable()->showIf(['ad_slot_key' => '', 'title' => '!']),
                F::select('tone', 'Card colour', self::TONES)->showIf(['ad_slot_key' => '', 'title' => '!'])->in('design'),
                F::select('sticker_tone', 'Sticker colour', self::STICKER_TONES)->showIf(['ad_slot_key' => '', 'title' => '!'])->in('design'),
                F::image('image', 'Image')->withAlt()->showIf(['ad_slot_key' => '']),
                F::image('image_m', 'Image (mobile)')->showIf(['ad_slot_key' => '']),
                F::link('link', 'Link')->showIf(['ad_slot_key' => '']),
            ],
            'countdown_strip' => [
                F::text('text', 'Text')->translatable()->required(),
                F::datetime('ends_at', 'Ends at')->required(),
                F::link('link', 'Link'),
            ],
            // C. Category / brand navigation
            'tile_scroller' => self::tiles('items', 12, true),
            'category_tiles' => self::tiles('items', 12, false),
            'category_showcase' => self::tiles('tiles', 7, false),
            'brand_carousel' => [
                ...self::heading(),
                F::repeater('items', 'Brands', [
                    F::picker('brand', 'brand', 'Brand')->fills(['label' => 'name_i18n', 'image' => 'image_i18n', 'link' => 'link_i18n'])->help('Picking a brand fills the logo, name and link.'),
                    F::image('image', 'Logo')->withAlt(),
                    F::text('label', 'Name')->translatable(),
                    F::text('caption', 'Offer caption')->translatable()->placeholder('Up to 40% off'),
                    F::link('link', 'Link'),
                ], 1, 24)->required()->itemTitle(['label', 'brand']),
                ...self::surface(),
            ],
            'segment_tabs' => [
                F::repeater('tabs', 'Tabs', [
                    F::text('label', 'Label')->translatable()->required(),
                    F::link('link', 'Link'),
                ], 2, 5)->required()->itemTitle(['label']),
                F::text('target', 'Default target page (slug)')->in('advanced')->help('Used by tabs without a link.'),
            ],
            'quick_links' => [
                ...self::heading(),
                F::repeater('items', 'Links', [
                    F::text('label', 'Label')->translatable()->required(),
                    F::image('image', 'Icon'),
                    F::link('link', 'Link')->required(),
                ], 1, 12)->required()->itemTitle(['label']),
                ...self::surface(),
            ],
            // D. Products & deals
            'product_carousel' => [
                ...self::heading(),
                ...self::productSource(),
                F::select('rows', 'Rows', ['1' => 'One row', '2' => 'Two rows'])->default('1')->in('design'),
                F::group('banner', 'Side banner (desktop)', [F::image('image', 'Image')->withAlt(), F::link('link', 'Link')])->in('design'),
                ...self::surface(),
            ],
            'product_grid' => [
                ...self::heading(),
                ...self::productSource(),
                F::number('columns', 'Columns on desktop', 4, 6)->default(5)->in('design'),
                ...self::surface(),
            ],
            'item_carousel' => [
                F::text('title', 'Title')->translatable()->placeholder('Recently viewed'),
                F::select('source', 'Products', ['recently_viewed' => 'Recently viewed on this device'])->default('recently_viewed')->required()->in('products'),
            ],
            'mega_deals' => [
                F::text('title', 'Title')->translatable()->help('Defaults to the flash sale name.'),
                self::flashSale()->required(),
                F::color('background', 'Band colour')->in('design'),
            ],
            'hero_offer_list' => [
                F::text('title', 'Title')->translatable(),
                F::image('hero_image', 'Hero image')->required(),
                F::link('link', 'Hero link'),
                F::select('source', 'Offers from', ['flash_sale' => 'A flash sale', 'manual' => 'Offer tiles below'])->default('flash_sale')->required()->in('products'),
                self::flashSale()->showIf(['source' => ['flash_sale', '']]),
                F::repeater('items', 'Offer tiles', [
                    F::image('image', 'Image')->withAlt(),
                    F::text('label', 'Title')->translatable(),
                    F::text('sticker', 'Tag')->translatable(),
                    F::link('link', 'Link'),
                ], 0, 12)->showIf(['source' => ['manual', '']])->in('products')->itemTitle(['label', 'link']),
                F::color('background', 'Band colour')->in('design'),
            ],
            'frenzy_deals' => [
                F::text('title', 'Title')->translatable()->help('Defaults to the flash sale name.'),
                self::flashSale()->required(),
                F::color('background', 'Band colour')->in('design'),
            ],
            'deal_grid' => [
                F::text('title', 'Title')->translatable()->help('Defaults to the flash sale name.'),
                self::flashSale()->required(),
                F::number('max_items', 'Deals shown', 1, 8)->default(4)->in('products'),
            ],
            'offer_cards' => [
                ...self::heading(),
                F::repeater('items', 'Offer cards', [
                    F::picker('coupon', 'code', 'Coupon')->help('Cards whose coupon is not live are hidden.'),
                    F::image('image', 'Image')->withAlt(),
                    F::text('title', 'Title')->translatable(),
                    F::text('caption', 'Caption')->translatable(),
                    F::link('link', 'Link'),
                ], 1, 12)->required()->itemTitle(['title', 'code']),
                ...self::surface(),
            ],
            'tile_grid' => [
                ...self::heading(),
                F::repeater('items', 'Tiles', [
                    F::picker('category', 'category', 'Category')->help('Fills the image, name and link from the category; your own values win.'),
                    F::image('image', 'Image')->withAlt(),
                    F::text('label', 'Title')->translatable(),
                    F::text('caption', 'Subtitle')->translatable(),
                    F::link('link', 'Link'),
                ], 1, 4)->required()->itemTitle(['label', 'category']),
                ...self::surface(),
            ],
            // E. Layout containers
            'panel_row' => [
                F::repeater('columns', 'Columns', [
                    F::number('width', 'Relative width', 1, 4)->default(1),
                    F::block('block', 'Block in this column'),
                ], 1, 3)->required()->itemTitle(['block.type']),
            ],
            'section_band' => [
                ...self::heading(),
                F::color('background', 'Band colour')->in('design'),
                F::image('background_image', 'Background image')->in('design'),
                F::blocks('children', 'Blocks inside the band', 1, 8)->required(),
            ],
            // F. Sponsored / ads
            'sponsored_brand_banner' => self::adSlot('sponsored_brand_banner'),
            'sponsored_product' => self::adSlot('sponsored_product'),
            'sponsored_brand_video' => self::adSlot('sponsored_brand_video'),
            'sponsored_video' => self::adSlot('sponsored_video'),
            'ad_slot' => [F::picker('ad_slot', 'ad_slot_key', 'Ad slot')->required()->help('Creatives come from live bookings of this slot.')],
            // G. Listing zone
            'product_listing' => [
                F::select('scope', 'Products', ['category' => 'This category', 'brand' => 'This brand', 'campaign' => 'This campaign', 'search' => 'Search results', 'seller' => 'This seller'])->default('category')->required()->in('products'),
                F::picker('category', 'category', 'Category')->showIf(['scope' => ['campaign', 'search']])->help('Optional: limit the listing to a category.')->in('products'),
                F::select('default_sort', 'Default sort', ['' => 'Default', 'recommended' => 'Recommended', 'bestselling' => 'Bestselling', 'newest' => 'Newest', 'rating' => 'Rating', 'price_asc' => 'Price: low to high', 'price_desc' => 'Price: high to low'])->in('products'),
            ],
            // H. Content, marketplace & engagement
            'verticals' => [
                ...self::heading(),
                F::repeater('items', 'Card texts (optional overrides)', [
                    F::select('key', 'Card', ['classifieds' => 'Classifieds', 'travel' => 'Travel', 'realEstate' => 'Real estate', 'creators' => 'Creators'])->required(),
                    F::text('title', 'Title')->translatable(),
                    F::text('caption', 'Caption')->translatable(),
                ], 0, 4)->itemTitle(['key']),
            ],
            'creators_carousel' => [
                ...self::heading(),
                F::text('type', 'Creator type (optional)')->in('products')->help('Only creators with this job type.'),
                F::number('max_items', 'Creators shown', 1, 24)->default(12)->in('products'),
                ...self::teaserTiles(),
            ],
            'classified_carousel' => [
                ...self::heading(),
                F::select('source', 'Ads', ['latest' => 'Latest ads', 'featured' => 'Featured ads'])->default('latest')->required()->in('products'),
                F::text('category', 'Classifieds category (slug)')->in('products'),
                F::select('kind', 'Kind', ['' => 'All', 'offer' => 'Offers', 'wanted' => 'Wanted'])->in('products'),
                F::select('sort', 'Sort', ['recommended' => 'Recommended', 'newest' => 'Newest', 'price_asc' => 'Price: low to high', 'price_desc' => 'Price: high to low'])->default('recommended')->in('products'),
                F::number('limit', 'Ads shown', 1, 24)->default(12)->in('products'),
                ...self::teaserTiles(),
            ],
            'travel_packages_carousel' => [
                ...self::heading(),
                F::select('source', 'Packages', ['latest' => 'Latest', 'umrah' => 'Umrah', 'hajj' => 'Hajj', 'holiday' => 'Holiday', 'honeymoon' => 'Honeymoon', 'adventure' => 'Adventure', 'business' => 'Business'])->default('latest')->required()->in('products'),
                F::select('trip_type', 'Trip type', ['' => 'Any', 'umrah' => 'Umrah', 'hajj' => 'Hajj', 'holiday' => 'Holiday', 'honeymoon' => 'Honeymoon', 'adventure' => 'Adventure', 'business' => 'Business'])->in('products'),
                F::text('destination', 'Destination (optional)')->in('products'),
                F::number('limit', 'Packages shown', 1, 24)->default(12)->in('products'),
                ...self::teaserTiles(),
            ],
            'properties_carousel' => [
                ...self::heading(),
                F::select('source', 'Properties', ['latest' => 'Latest', 'rent' => 'For rent', 'sale' => 'For sale', 'off_plan' => 'Off-plan'])->default('latest')->required()->in('products'),
                F::text('category', 'Property category (slug)')->in('products'),
                F::select('purpose', 'Purpose', ['' => 'Any', 'rent' => 'Rent', 'sale' => 'Sale', 'off_plan' => 'Off-plan'])->in('products'),
                F::text('city', 'City (optional)')->in('products'),
                F::number('limit', 'Properties shown', 1, 24)->default(12)->in('products'),
                ...self::teaserTiles(),
            ],
            'video_block' => [
                ...self::heading(),
                F::picker('stream', 'stream_slug', 'Video from the media library')->help('Live & videos (admin media library).'),
                F::text('video_url', 'Or a video file URL (https)')->translatable()->placeholder('https://…'),
                F::image('poster', 'Poster image'),
            ],
            'live_streams_strip' => [...self::heading()],
            'html' => [
                ...self::heading(),
                F::richText('html', 'Content')->translatable()->required()->help('Sanitised when shown.'),
            ],
            'seo_text' => [
                F::text('title', 'Title')->translatable(),
                F::richText('text', 'Text')->translatable()->required(),
            ],
            'popular_searches' => [
                F::text('title', 'Title')->translatable()->placeholder('Popular searches'),
                F::tags('keywords', 'Keywords')->translatable()->required()->help('Each keyword links to its search results.'),
            ],
            'app_download' => [
                F::text('title', 'Title')->translatable()->placeholder('Get the app'),
                F::text('subtitle', 'Subtitle')->translatable(),
                F::link('ios_url', 'App Store link'),
                F::link('android_url', 'Google Play link'),
            ],
            'newsletter_signup' => [
                F::text('title', 'Title')->translatable(),
                F::text('subtitle', 'Subtitle')->translatable(),
            ],
            'vendor_spotlight' => [
                ...self::heading(),
                F::repeater('items', 'Sellers', [
                    F::picker('vendor', 'vendor', 'Seller')->fills(['label' => 'name_i18n', 'image' => 'image_i18n', 'link' => 'link_i18n'])->help('Picking a seller fills the logo, name and link.'),
                    F::image('image', 'Logo')->withAlt(),
                    F::text('label', 'Name')->translatable(),
                    F::text('caption', 'Caption')->translatable(),
                    F::link('link', 'Link'),
                ], 1, 24)->required()->itemTitle(['label', 'vendor']),
                ...self::surface(),
            ],
            // I. Sub-category navigation
            'subcategory_tiles' => [
                ...self::heading(false),
                F::select('style', 'Style', ['' => 'Square tiles with "All"', 'circle' => 'Round images with offer captions'])->in('design'),
                F::number('max_rows', 'Rows', 1, 6)->default(2)->showIf(['style' => '!circle'])->in('design'),
                F::number('max_items', 'Sub-categories shown', 1, 24)->default(9)->showIf(['style' => 'circle'])->in('design'),
                ...self::surface(),
            ],
            'subcategory_hub' => [
                ...self::heading(false),
                F::number('max_links', 'Links per card', 1, 12)->default(6)->in('design'),
            ],
            'subcategory_chips' => [
                F::select('style', 'Style', ['' => 'Chips', 'boxes' => 'Boxes'])->in('design'),
                F::toggle('sticky', 'Stick to the top while scrolling')->default(true)->showIf(['style' => '!boxes'])->in('design'),
            ],
            // J. Page headers
            'category_header' => [
                F::text('title', 'Title')->translatable()->help('Defaults to the category name.'),
                F::textarea('description', 'Description')->translatable(),
                F::image('image', 'Banner image'),
            ],
            'brand_header' => [
                F::text('name', 'Name')->translatable()->required(),
                F::text('caption', 'Tagline')->translatable(),
                F::image('logo', 'Logo'),
                F::image('cover', 'Cover image'),
                F::toggle('official', 'Official store badge'),
                F::repeater('tabs', 'Store tabs', [
                    F::text('label', 'Label')->translatable()->required(),
                    F::link('link', 'Link'),
                ], 0, 6)->itemTitle(['label']),
            ],
            'campaign_hero' => [
                F::text('title', 'Title')->translatable()->required(),
                F::text('subline', 'Subline')->translatable(),
                F::datetime('ends_at', 'Countdown ends at')->required(),
                F::image('image', 'Background image'),
                F::repeater('chips', 'Jump chips', [
                    F::text('label', 'Label')->translatable()->required(),
                    F::link('link', 'Link or #section'),
                ], 0, 8)->itemTitle(['label']),
            ],
            // K. Category page
            'category_hero' => [
                F::repeater('slides', 'Slides', self::card(), 1, 8)->required()->itemTitle(['title']),
                F::number('autoplay', 'Autoplay', 1, 30)->unit('s')->default(6)->in('design'),
            ],
            'category_promo_tiles' => [
                ...self::heading(false),
                F::group('hero', 'Big card', [...self::card(), F::picker('category', 'category', 'Caption from category offers')]),
                F::repeater('tiles', 'Tiles', [
                    F::text('title', 'Title')->translatable()->required(),
                    F::text('caption', 'Caption')->translatable()->help('Replaced by real offers when a category is set.'),
                    F::picker('category', 'category', 'Caption from category offers'),
                    F::image('image', 'Image'),
                    F::link('link', 'Link'),
                    F::select('tone', 'Colour', ['' => 'Automatic', ...self::TONES]),
                ], 0, 4)->itemTitle(['title']),
            ],
            'category_brand_tiles' => [
                ...self::heading(false),
                F::picker('category', 'category', 'Category')->help('Defaults to the page category.')->in('products'),
                F::picker('brand', 'brands', 'Only these brands (optional)')->multiple(24)->in('products'),
                F::number('max_items', 'Brands shown', 1, 12)->default(6)->in('products'),
            ],
            'category_gift_band' => [
                F::text('sticker', 'Sticker')->translatable(),
                F::text('title', 'Title')->translatable(),
                F::text('subtitle', 'Subtitle')->translatable(),
                F::link('link', 'Link'),
                F::picker('category', 'category', 'Category')->in('products'),
                F::number('max_price', 'Maximum price', 0, null)->unit('currency')->in('products'),
                F::number('max_items', 'Products shown', 1, 12)->default(4)->in('products'),
            ],
            'category_deal_columns' => [
                F::text('title', 'Title')->translatable(),
                F::picker('category', 'category', 'Category (one column per sub-category)')->in('products'),
                F::picker('flash_sale', 'flash_sale_slug', 'Flash sale')->in('products'),
                F::number('max_columns', 'Columns', 1, 6)->default(5)->in('products'),
            ],
            'sponsored_brand_showcase' => [
                F::text('title', 'Title')->translatable(),
                F::picker('ad_slot', 'ad_slot_key', 'Ad slot')->default('sponsored_brand_banner')->in('products'),
                F::picker('category', 'category', 'Category scope')->help('Defaults to the page category.')->in('products'),
                F::number('max_items', 'Brand rows shown', 1, 4)->default(1)->in('products'),
            ],
        ];

        foreach ($schemas as $type => $fields) {
            $schemas[$type] = [...$fields, F::make('datetime_range', 'visibility', 'Show between')->in('visibility')->help('Country time. Outside this window the section is not shown.')];
        }

        return self::$schemas = $schemas;
    }

    /**
     * Title, subtitle and "View all" of blocks drawn with the common section chrome.
     *
     * @return array<int, F>
     */
    private static function heading(bool $viewAll = true): array
    {
        return array_values(array_filter([
            F::text('title', 'Title')->translatable(),
            F::text('subtitle', 'Subtitle')->translatable(),
            $viewAll ? F::link('view_all', '"View all" link') : null,
        ]));
    }

    /**
     * @return array<int, F>
     */
    private static function surface(): array
    {
        return [
            F::color('background', 'Background colour')->in('design'),
            F::toggle('surface', 'White card behind the section')->default(true)->showIf(['background' => ''])->in('design'),
        ];
    }

    /**
     * An image slide / banner / tile.
     *
     * @return array<int, F>
     */
    private static function imageItem(bool $caption = false): array
    {
        return array_values(array_filter([
            F::image('image_d', 'Image (desktop)')->withAlt(),
            F::image('image_m', 'Image (mobile)')->help('Optional crop for phones.'),
            F::link('link', 'Link'),
            F::text('label', 'Label')->translatable()->help('Shown when there is no image.'),
            $caption ? F::text('caption', 'Caption')->translatable() : null,
        ]));
    }

    /**
     * A styled text card (home design banners).
     *
     * @return array<int, F>
     */
    private static function card(): array
    {
        return [
            F::text('title', 'Title')->translatable(),
            F::text('subtitle', 'Subtitle')->translatable(),
            F::text('sticker', 'Sticker')->translatable(),
            F::picker('coupon', 'code', 'Coupon code chip'),
            F::image('image', 'Product image')->withAlt(),
            F::link('link', 'Link'),
            F::select('tone', 'Card colour', ['' => 'Default', ...self::TONES]),
            F::select('sticker_tone', 'Sticker colour', self::STICKER_TONES),
        ];
    }

    /**
     * Category / brand tile rows (tile_scroller, category_tiles, category_showcase).
     *
     * @return array<int, F>
     */
    private static function tiles(string $key, int $max, bool $live): array
    {
        return array_values(array_filter([
            ...self::heading(),
            F::select('source', 'Tiles from', ['manual' => 'Tiles below', 'category' => 'Sub-categories of a category'])->default('manual')->required(),
            F::picker('category', 'category', 'Category')->showIf(['source' => 'category'])->help('Lists its sub-categories that have products. Without one, the page category.'),
            F::number('max_items', 'Tiles shown', 1, 24)->default($max)->showIf(['source' => 'category']),
            F::repeater($key, 'Tiles', [
                F::picker('category', 'category', 'Category')->help('Fills image, name and link from the category; your own values win.'),
                F::image('image', 'Image')->withAlt(),
                F::text('label', 'Label')->translatable(),
                F::text('caption', 'Caption')->translatable()->placeholder('From AED 36'),
                F::text('sticker', 'Sticker')->translatable()->placeholder('NEW'),
                F::link('link', 'Link'),
            ], 0, 24)->showIf(['source' => ['manual', '']])->itemTitle(['label', 'category']),
            $key === 'tiles' ? null : F::select('shape', 'Tile shape', ['rounded' => 'Rounded square', 'circle' => 'Circle', 'card' => 'Card'])->default('rounded')->in('design'),
            $key === 'tiles' ? null : F::select('rows', 'Rows', ['1' => 'One row', '2' => 'Two rows'])->default('1')->in('design'),
            $live ? F::toggle('live_pill', 'Show the running live stream')->in('design') : null,
            ...self::surface(),
        ]));
    }

    /**
     * @return array<int, F>
     */
    private static function productSource(): array
    {
        return [
            F::select('source', 'Products from', self::PRODUCT_SOURCES)->default('bestsellers')->required()->in('products'),
            F::picker('product', 'listing_ids', 'Products')->multiple(48)->showIf(['source' => 'manual'])->in('products')->help('Drag to order. Only live offers show.'),
            F::picker('category', 'category', 'Category')->showIf(['source' => '!manual'])->in('products')->help('Required for "Category"; narrows every other source.'),
            F::picker('brand', 'brand', 'Brand')->showIf(['source' => '!manual'])->in('products')->help('Required for "Brand"; narrows every other source.'),
            F::picker('coupon', 'coupon', 'Coupon')->showIf(['source' => 'coupon'])->in('products'),
            F::number('max_items', 'Items to show', 1, 48)->default(12)->unit('products')->in('products'),
            F::select('fallback_source', 'Fill up from', ['' => 'Nothing', ...array_diff_key(self::PRODUCT_SOURCES, ['manual' => true])])->showIf(['source' => '!manual'])->in('products')->help('Used when the source finds too few products.'),
            F::number('min_items', 'Fill up below', 1, 48)->default(4)->showIf(['source' => '!manual', 'fallback_source' => '!'])->in('products'),
        ];
    }

    private static function flashSale(): F
    {
        return F::picker('flash_sale', 'flash_sale_slug', 'Flash sale')->clears(['flash_sale_id'])->in('products');
    }

    /**
     * @return array<int, F>
     */
    private static function adSlot(string $default): array
    {
        return [F::picker('ad_slot', 'ad_slot_key', 'Ad slot')->default($default)->help('Ads come from live bookings of this slot and are always labelled.')];
    }

    /**
     * Editor tiles shown instead of the live feed (vertical teasers).
     *
     * @return array<int, F>
     */
    private static function teaserTiles(): array
    {
        return [
            F::repeater('items', 'Teaser tiles (replace the live feed)', [
                F::image('image', 'Image')->withAlt(),
                F::text('label', 'Title')->translatable(),
                F::text('caption', 'Caption')->translatable(),
                F::link('link', 'Link'),
            ], 0, 12)->in('advanced')->itemTitle(['label', 'link']),
        ];
    }
}
