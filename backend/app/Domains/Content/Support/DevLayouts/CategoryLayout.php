<?php

namespace App\Domains\Content\Support\DevLayouts;

use App\Domains\Catalog\Models\Brand;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Support\ProductSearch;
use App\Domains\Countries\Models\Country;
use App\Domains\Coupons\Models\Coupon;
use App\Domains\FlashSales\Enums\FlashSaleEventStatus;
use App\Domains\FlashSales\Models\FlashSaleEvent;
use App\Shared\Http\PublicUrl;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A category page of the design (docs/design/reference/category-page-target.webp)
 * as page-builder zones (template `category`, page slug = category slug) for
 * one country, root category and language:
 *
 * - `listing`: sub-category boxes, then the product listing (rail, results
 *   header "… Results for 'Electronics in UAE'", sponsored products and
 *   sponsored brand rows every 4 rows, infinite scroll);
 * - `top` (above the results, beside the rail): promo slides, sub-category
 *   circles with offer captions, "Recommended for you", Mega Deals columns,
 *   promo tiles, brand tiles, "Gifts they'll love", "Explore more"
 *   (sponsored brand row, only with a live booking);
 * - `bottom`: SEO text and popular searches.
 *
 * Call it inside the country's CountryContext — it reads the country DB for
 * the sub-categories, brands, the flash sale and coupons, and leaves out what
 * is not there:
 *
 *     $zones = $countryContext->runAs($country, fn () => app(CategoryLayout::class)->zones($country, $root, 'ar'));
 *
 * Prices in copy are AED amounts converted at fixed rates; discounts and
 * "From" prices on tiles are resolved from live offers when the page renders.
 */
class CategoryLayout
{
    /**
     * Fixed AED conversion rates for marketing copy.
     */
    private const RATES = ['AED' => 1.0, 'SAR' => 1.024, 'OMR' => 0.102, 'KWD' => 0.0841, 'BHD' => 0.1028, 'QAR' => 0.992, 'EGP' => 13.45, 'JOD' => 0.1932];

    private const THREE_DECIMALS = ['OMR', 'KWD', 'BHD', 'JOD'];

    /**
     * @var array<string, array{en: string, ar: string}>
     */
    private const PLACES = [
        'uae' => ['en' => 'the UAE', 'ar' => 'الإمارات'],
        'omn' => ['en' => 'Oman', 'ar' => 'عُمان'],
        'ksa' => ['en' => 'Saudi Arabia', 'ar' => 'السعودية'],
        'egy' => ['en' => 'Egypt', 'ar' => 'مصر'],
        'kwt' => ['en' => 'Kuwait', 'ar' => 'الكويت'],
        'qat' => ['en' => 'Qatar', 'ar' => 'قطر'],
        'bhr' => ['en' => 'Bahrain', 'ar' => 'البحرين'],
        'jor' => ['en' => 'Jordan', 'ar' => 'الأردن'],
    ];

    /**
     * Per root: promo image (website/public/img), the coupon of its slide, and the slide copy (EN, AR).
     *
     * @var array<string, array{image: string, coupon: string|null, title: array{0: string, 1: string}, subtitle: array{0: string, 1: string}}>
     */
    private const THEMES = [
        'electronics' => ['image' => '/img/product-smartphone.png', 'coupon' => 'NAWY10', 'title' => ['Tech week: the latest phones & audio', 'أسبوع التقنية: أحدث الهواتف والصوتيات'], 'subtitle' => ['Smartphones, earbuds, wearables & more', 'هواتف ذكية وسماعات وأجهزة قابلة للارتداء والمزيد']],
        'fashion' => ['image' => '/img/product-abaya.png', 'coupon' => 'NAWY10', 'title' => ['New season styles', 'إطلالات الموسم الجديد'], 'subtitle' => ['Abayas, sneakers, bags & more', 'عبايات وأحذية رياضية وحقائب والمزيد']],
        'womens-fashion' => ['image' => '/img/product-abaya.png', 'coupon' => 'NAWY10', 'title' => ["Women's new arrivals", 'وصل حديثاً للنساء'], 'subtitle' => ['Abayas, dresses, bags & shoes', 'عبايات وفساتين وحقائب وأحذية']],
        'mens-fashion' => ['image' => '/img/product-sneakers.png', 'coupon' => 'NAWY10', 'title' => ["Men's essentials", 'أساسيات الرجال'], 'subtitle' => ['Sneakers, shirts, watches & more', 'أحذية رياضية وقمصان وساعات والمزيد']],
        'kids-fashion' => ['image' => '/img/product-sneakers.png', 'coupon' => 'FIRST15', 'title' => ['Back to school picks', 'اختيارات العودة إلى المدرسة'], 'subtitle' => ['Shoes, uniforms & bags for kids', 'أحذية وزي مدرسي وحقائب للأطفال']],
        'beauty-fragrances' => ['image' => '/img/product-perfume.png', 'coupon' => 'SCENT10', 'title' => ['Beauty week: top fragrances', 'أسبوع الجمال: أفضل العطور'], 'subtitle' => ['Oud, musk & designer scents', 'عود ومسك وعطور فاخرة']],
        'beauty' => ['image' => '/img/product-perfume.png', 'coupon' => 'GLOW', 'title' => ['Glow up: beauty favourites', 'تألّقي: مفضلات الجمال'], 'subtitle' => ['Skincare, makeup & fragrance', 'العناية بالبشرة والمكياج والعطور']],
        'home' => ['image' => '/img/product-espresso.png', 'coupon' => 'NAWY10', 'title' => ['Refresh your home', 'جدّد منزلك'], 'subtitle' => ['Décor, furniture & kitchen', 'ديكور وأثاث ومطبخ']],
        'home-kitchen' => ['image' => '/img/product-air-fryer.png', 'coupon' => 'NAWY10', 'title' => ['Kitchen upgrades', 'ترقيات المطبخ'], 'subtitle' => ['Air fryers, espresso machines & cookware', 'قلايات هوائية وماكينات إسبريسو وأدوات طهي']],
        'grocery' => ['image' => '/img/lifestyle-flatlay.jpg', 'coupon' => 'BUY2', 'title' => ['Stock up & save', 'خزّن ووفّر'], 'subtitle' => ['Bulk deals on pantry staples', 'عروض الجملة على أساسيات المطبخ']],
        'baby-maternity' => ['image' => '/img/lifestyle-flatlay.jpg', 'coupon' => 'FIRST15', 'title' => ['Everything for baby', 'كل ما يحتاجه طفلك'], 'subtitle' => ['Strollers, diapers, feeding & care', 'عربات وحفاضات وتغذية وعناية']],
        'toys' => ['image' => '/img/lifestyle-flatlay.jpg', 'coupon' => 'FIRST15', 'title' => ['Playtime picks', 'اختيارات وقت اللعب'], 'subtitle' => ['Games, puzzles, ride-ons & more', 'ألعاب وألغاز ومركبات أطفال والمزيد']],
        'sports' => ['image' => '/img/product-sneakers.png', 'coupon' => 'WELL', 'title' => ['Gear up for the season', 'استعد للموسم'], 'subtitle' => ['Running, fitness & outdoor', 'الجري واللياقة والأنشطة الخارجية']],
        'health' => ['image' => '/img/product-smartwatch.png', 'coupon' => 'WELL', 'title' => ['Feel your best', 'اعتنِ بصحتك'], 'subtitle' => ['Vitamins, trackers & personal care', 'فيتامينات وأجهزة تتبع وعناية شخصية']],
        'books' => ['image' => '/img/lifestyle-flatlay.jpg', 'coupon' => 'NAWY10', 'title' => ['Read more this month', 'اقرأ أكثر هذا الشهر'], 'subtitle' => ['Bestsellers, kids books & stationery', 'الأكثر مبيعاً وكتب الأطفال والقرطاسية']],
        'automotive' => ['image' => '/img/promo-banner-wide.png', 'coupon' => 'NAWY10', 'title' => ['Drive in style', 'قُد بأناقة'], 'subtitle' => ['Accessories, care & electronics for your car', 'إكسسوارات وعناية وإلكترونيات لسيارتك']],
    ];

    private const IMAGES = ['/img/product-smartphone.png', '/img/product-earbuds.png', '/img/product-smartwatch.png', '/img/product-perfume.png', '/img/product-sneakers.png'];

    private string $locale = 'en';

    private string $currency = 'AED';

    public function __construct(private ProductSearch $search) {}

    /**
     * @return array{header: array<int, array{id: string, type: string, settings: array<string, mixed>}>, top: array<int, array{id: string, type: string, settings: array<string, mixed>}>, listing: array<int, array{id: string, type: string, settings: array<string, mixed>}>, bottom: array<int, array{id: string, type: string, settings: array<string, mixed>}>}
     */
    public function zones(Country $country, Category $root, string $locale): array
    {
        $this->locale = $locale === 'ar' ? 'ar' : 'en';
        $this->currency = (string) $country->currency;
        $place = self::PLACES[$country->code] ?? ['en' => (string) $country->name_en, 'ar' => (string) $country->name_ar];
        $name = $this->locale === 'ar' ? (string) $root->name_ar : (string) $root->name_en;
        $children = $this->childrenWithProducts($root);
        $id = fn (string $block): string => "cat-{$root->slug}-{$block}";

        $top = array_values(array_filter([
            $this->hero($root, $children, $id('hero')),
            ['id' => $id('circles'), 'type' => 'subcategory_tiles', 'settings' => ['style' => 'circle', 'max_items' => 9]],
            ['id' => $id('recommended'), 'type' => 'product_carousel', 'settings' => [
                'title' => $this->t('Recommended for you', 'مقترحة لك'),
                'source' => 'recommended', 'category' => $root->slug, 'fallback_source' => 'category', 'max_items' => 16,
                'view_all' => '/'.$root->slug.'?sort=rating',
            ]],
            $this->megaDeals($root, $id('mega-deals')),
            $this->promoTiles($root, $children, $id('promo-tiles')),
            ['id' => $id('brands'), 'type' => 'category_brand_tiles', 'settings' => ['category' => $root->slug, 'max_items' => 6]],
            ['id' => $id('gifts'), 'type' => 'category_gift_band', 'settings' => [
                'sticker' => $this->t('Under '.$this->money(99), 'أقل من '.$this->money(99)),
                'title' => $this->t("Gifts they'll love", 'هدايا سيحبونها'),
                'subtitle' => $this->t('Curated picks', 'اختيارات منتقاة'),
                'category' => $root->slug,
                'max_price' => $this->value(99),
                'max_items' => 4,
                'link' => '/'.$root->slug.'?max_price='.$this->value(99),
            ]],
            ['id' => $id('explore-more'), 'type' => 'sponsored_brand_showcase', 'settings' => [
                'title' => $this->t('Explore more', 'اكتشف المزيد'),
                'ad_slot_key' => 'sponsored_brand_banner',
                'category' => $root->slug,
            ]],
        ]));

        return [
            'header' => [],
            'top' => $top,
            'listing' => [
                ['id' => $id('chips'), 'type' => 'subcategory_chips', 'settings' => ['style' => 'boxes', 'sticky' => false]],
                ['id' => $id('listing'), 'type' => 'product_listing', 'settings' => ['scope' => 'category', 'default_sort' => 'recommended']],
            ],
            'bottom' => [
                $this->seo($name, $children, $place),
                $this->popularSearches($root, $children),
            ],
        ];
    }

    /**
     * An AED amount in the country's currency for copy: "AED 99", "OMR 10.100".
     */
    public function money(float $aed): string
    {
        return $this->currency.' '.$this->value($aed, true);
    }

    /**
     * The converted amount: rounded down to a whole unit (2-decimal
     * currencies) or to 0.100 (OMR, KWD, BHD, JOD).
     */
    public function value(float $aed, bool $formatted = false): string
    {
        $value = $aed * (self::RATES[$this->currency] ?? 1.0);

        if (in_array($this->currency, self::THREE_DECIMALS, true)) {
            $value = max(0.1, floor($value * 10) / 10);

            return $formatted ? number_format($value, 3) : number_format($value, 3, '.', '');
        }

        $value = max(1, floor($value));

        return $formatted ? number_format($value, 0) : (string) (int) $value;
    }

    /**
     * Level-2 children of the root that have products, in admin order.
     *
     * @return Collection<int, Category>
     */
    private function childrenWithProducts(Category $root): Collection
    {
        return Category::query()->where('parent_id', $root->id)->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get()
            ->filter(fn (Category $child): bool => $this->search->query(['category' => $child->slug])->exists())
            ->values();
    }

    /**
     * Promo slides: the root's theme (with its coupon when live) and one slide per first two sub-categories.
     *
     * @param  Collection<int, Category>  $children
     * @return array<string, mixed>
     */
    private function hero(Category $root, Collection $children, string $id): array
    {
        $theme = self::THEMES[$root->slug] ?? ['image' => '/img/promo-banner-wide.png', 'coupon' => 'NAWY10', 'title' => [$root->name_en, $root->name_ar], 'subtitle' => ['Top picks and deals', 'أفضل الاختيارات والعروض']];
        $coupon = $theme['coupon'] === null ? null : Coupon::query()->where('code', $theme['coupon'])->where('is_active', true)->whereNull('admin_disabled_at')
            ->where(fn ($live) => $live->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($live) => $live->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->value('code');
        $discount = $this->maxDiscount($root->slug);
        $slides = [[
            'tone' => 'brand',
            'sticker' => $discount >= 5 ? $this->t('Up to '.$discount.'% off', 'خصم حتى '.$discount.'٪') : $this->t('Top picks', 'الأكثر طلباً'),
            'title' => $this->t($theme['title'][0], $theme['title'][1]),
            'subtitle' => $this->t($theme['subtitle'][0], $theme['subtitle'][1]),
            'code' => $coupon,
            'image' => $theme['image'],
            'link' => '/'.$root->slug.($discount >= 5 ? '?deals=1' : ''),
        ]];

        foreach ($children->take(2) as $index => $child) {
            $childDiscount = $this->maxDiscount($child->slug);
            $slides[] = [
                'tone' => $index === 0 ? 'dark' : 'subtle',
                'sticker' => $childDiscount >= 5 ? $this->t('Up to '.$childDiscount.'% off', 'خصم حتى '.$childDiscount.'٪') : $this->t('New in', 'وصل حديثاً'),
                'title' => $this->t('Shop '.$child->name_en, 'تسوّق '.$child->name_ar),
                'subtitle' => $this->t('Free express delivery on Fulfilled by Nawy items', 'توصيل سريع مجاني على منتجات تتولى نوي توصيلها'),
                'image' => PublicUrl::of($child->image_path) ?? self::IMAGES[$index % count(self::IMAGES)],
                'link' => '/'.$root->slug.'/'.$child->slug,
            ];
        }

        return ['id' => $id, 'type' => 'category_hero', 'settings' => [
            'slides' => array_map(fn (array $slide): array => array_filter($slide, fn ($value): bool => $value !== null), $slides),
            'autoplay' => 6,
        ]];
    }

    /**
     * Mega Deals band when the root has products on deal.
     *
     * @return array<string, mixed>|null
     */
    private function megaDeals(Category $root, string $id): ?array
    {
        if (! $this->search->query(['category' => $root->slug, 'deals' => true])->exists()) {
            return null;
        }

        $sale = FlashSaleEvent::query()->whereIn('status', FlashSaleEventStatus::pricing())->where('ends_at', '>', now())->where('slug', 'big-mega-deals')->value('slug');

        return ['id' => $id, 'type' => 'category_deal_columns', 'settings' => array_filter([
            'title' => $this->t('Mega Deals', 'عروض ضخمة'),
            'category' => $root->slug,
            'flash_sale_slug' => $sale,
            'max_columns' => 5,
        ])];
    }

    /**
     * The big dark card (first sub-category) and four coloured tiles (the next four), captions from live offers.
     *
     * @param  Collection<int, Category>  $children
     * @return array<string, mixed>|null
     */
    private function promoTiles(Category $root, Collection $children, string $id): ?array
    {
        if ($children->isEmpty()) {
            return null;
        }

        $hero = $children->first();
        $grandchildren = Category::query()->where('parent_id', $hero->id)->where('is_active', true)->orderBy('sort_order')->limit(3)->get();
        $tiles = $children->slice(1, 4)->values()->map(fn (Category $child, int $index): array => [
            'title' => $this->t($child->name_en, $child->name_ar),
            'category' => $child->slug,
            'link' => '/'.$root->slug.'/'.$child->slug,
            'image' => PublicUrl::of($child->image_path) ?? self::IMAGES[$index % count(self::IMAGES)],
            'tone' => ['brand', 'light', 'success', 'subtle'][$index],
        ])->all();

        return ['id' => $id, 'type' => 'category_promo_tiles', 'settings' => [
            'hero' => [
                'sticker' => $this->t($root->name_en, $root->name_ar),
                'title' => $this->t('The best of '.$hero->name_en, 'الأفضل في '.$hero->name_ar),
                'subtitle' => $grandchildren->isEmpty() ? null : $this->t($grandchildren->pluck('name_en')->implode(', ').' & more', $grandchildren->pluck('name_ar')->implode('، ').' والمزيد'),
                'category' => $hero->slug,
                'image' => PublicUrl::of($hero->image_path) ?? self::IMAGES[0],
                'link' => '/'.$root->slug.'/'.$hero->slug,
                'tone' => 'dark',
            ],
            'tiles' => $tiles,
        ]];
    }

    /**
     * @param  Collection<int, Category>  $children
     * @param  array{en: string, ar: string}  $place
     * @return array<string, mixed>
     */
    private function seo(string $name, Collection $children, array $place): array
    {
        $list = $children->take(5)->map(fn (Category $child): string => $this->locale === 'ar' ? (string) $child->name_ar : mb_strtolower((string) $child->name_en))->implode($this->locale === 'ar' ? '، ' : ', ');
        $text = $this->locale === 'ar'
            ? '<p>تسوّق '.$name.' أونلاين في '.$place['ar'].' من نوي: '.($list !== '' ? $list.' ' : '').'وغيرها من بائعين موثوقين بأفضل الأسعار.</p><p>استمتع بالتوصيل السريع للمنتجات التي تتولى نوي توصيلها، والدفع عند الاستلام، والإرجاع السهل، وعروض يومية وكوبونات حصرية.</p>'
            : '<p>Shop '.$name.' online in '.$place['en'].' at Nawy: '.($list !== '' ? $list.' ' : '').'and more from trusted sellers at the best prices.</p><p>Enjoy express delivery on items fulfilled by Nawy, cash on delivery, easy returns, daily deals and exclusive coupons.</p>';

        return ['id' => 'cat-seo', 'type' => 'seo_text', 'settings' => [
            'title' => $this->t('Superior online shopping in '.$place['en'], 'تسوّق إلكتروني متميّز في '.$place['ar']),
            'text' => $text,
        ]];
    }

    /**
     * Sub-category names and the category's top brands.
     *
     * @param  Collection<int, Category>  $children
     * @return array<string, mixed>
     */
    private function popularSearches(Category $root, Collection $children): array
    {
        $brands = Brand::query()->where('is_active', true)
            ->whereIn('id', $this->search->query(['category' => $root->slug])->whereNotNull('products.brand_id')->select('products.brand_id'))
            ->orderBy('name')->limit(6)->pluck('name');
        $keywords = $children->take(8)->map(fn (Category $child): string => $this->locale === 'ar' ? (string) $child->name_ar : (string) $child->name_en)
            ->merge($brands)->unique()->values()->all();

        return ['id' => 'cat-popular-searches', 'type' => 'popular_searches', 'settings' => ['title' => $this->t('Popular searches', 'عمليات البحث الشائعة'), 'keywords' => $keywords]];
    }

    /**
     * Deepest sale discount (whole %) of the category's sellable offers right now.
     */
    private function maxDiscount(string $slug): int
    {
        $max = $this->search->sellableListings()
            ->join('product_variants', 'product_variants.id', '=', 'listings.product_variant_id')
            ->whereIn('product_variants.product_id', $this->search->query(['category' => $slug])->select('products.id'))
            ->whereNotNull('listings.sale_price')->whereColumn('listings.sale_price', '<', 'listings.price')->where('listings.price', '>', 0)
            ->toBase()
            ->max(DB::raw('(listings.price - listings.sale_price) / listings.price'));

        return (int) floor((float) $max * 100);
    }

    private function t(string $en, string $ar): string
    {
        return $this->locale === 'ar' ? $ar : $en;
    }
}
