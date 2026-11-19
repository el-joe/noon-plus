<?php

namespace App\Domains\Content\Support\DevLayouts;

use App\Domains\Catalog\Models\Category;
use App\Domains\Classifieds\Enums\ClassifiedAdStatus;
use App\Domains\Classifieds\Models\ClassifiedAd;
use App\Domains\Content\Support\BlockTiles;
use App\Domains\Countries\Models\Country;
use App\Domains\Coupons\Enums\CouponType;
use App\Domains\Coupons\Models\Coupon;
use App\Domains\FlashSales\Enums\FlashSaleEventStatus;
use App\Domains\FlashSales\Models\FlashSaleEvent;
use Illuminate\Support\Collection;

/**
 * The home page of the design (docs/design/reference/home-page-target.webp)
 * as page-builder zones for one country and language, section by section:
 * hero cards, category strip (+ live pill), "More reasons to shop" ·
 * "Mega deals" · "In focus", product carousels from server-side sources,
 * coupon cards, vertical banners, category showcases, creators, SEO text
 * and popular searches. Call it inside the country's CountryContext — it
 * reads the country DB for category paths, the flash sale and the coupons,
 * and leaves out what is not there (no dead links or codes):
 *
 *     $zones = $countryContext->runAs($country, fn () => app(HomeLayout::class)->zones($country, 'ar'));
 *
 * Prices in copy are AED amounts converted at fixed rates and rounded
 * ({@see self::money()}); products always come from block sources.
 */
class HomeLayout
{
    /**
     * Fixed AED conversion rates for marketing copy.
     */
    private const RATES = ['AED' => 1.0, 'SAR' => 1.024, 'OMR' => 0.102, 'KWD' => 0.0841, 'BHD' => 0.1028, 'QAR' => 0.992, 'EGP' => 13.45, 'JOD' => 0.1932];

    private const THREE_DECIMALS = ['OMR', 'KWD', 'BHD', 'JOD'];

    /**
     * Country name in "in the UAE" copy, cities for SEO text.
     *
     * @var array<string, array{en: string, ar: string, cities_en: string, cities_ar: string}>
     */
    private const PLACES = [
        'uae' => ['en' => 'the UAE', 'ar' => 'الإمارات', 'cities_en' => 'Dubai, Abu Dhabi, Sharjah and across the UAE', 'cities_ar' => 'دبي وأبوظبي والشارقة وجميع أنحاء الإمارات'],
        'omn' => ['en' => 'Oman', 'ar' => 'عُمان', 'cities_en' => 'Muscat, Salalah, Sohar and across Oman', 'cities_ar' => 'مسقط وصلالة وصحار وجميع أنحاء عُمان'],
        'ksa' => ['en' => 'Saudi Arabia', 'ar' => 'السعودية', 'cities_en' => 'Riyadh, Jeddah, Dammam and across Saudi Arabia', 'cities_ar' => 'الرياض وجدة والدمام وجميع أنحاء السعودية'],
        'egy' => ['en' => 'Egypt', 'ar' => 'مصر', 'cities_en' => 'Cairo, Alexandria, Giza and across Egypt', 'cities_ar' => 'القاهرة والإسكندرية والجيزة وجميع أنحاء مصر'],
        'kwt' => ['en' => 'Kuwait', 'ar' => 'الكويت', 'cities_en' => 'Kuwait City, Hawalli, Salmiya and across Kuwait', 'cities_ar' => 'مدينة الكويت وحولي والسالمية وجميع أنحاء الكويت'],
        'qat' => ['en' => 'Qatar', 'ar' => 'قطر', 'cities_en' => 'Doha, Al Rayyan, Al Wakrah and across Qatar', 'cities_ar' => 'الدوحة والريان والوكرة وجميع أنحاء قطر'],
        'bhr' => ['en' => 'Bahrain', 'ar' => 'البحرين', 'cities_en' => 'Manama, Muharraq, Riffa and across Bahrain', 'cities_ar' => 'المنامة والمحرق والرفاع وجميع أنحاء البحرين'],
        'jor' => ['en' => 'Jordan', 'ar' => 'الأردن', 'cities_en' => 'Amman, Irbid, Zarqa and across Jordan', 'cities_ar' => 'عمّان وإربد والزرقاء وجميع أنحاء الأردن'],
    ];

    private string $locale = 'en';

    private string $currency = 'AED';

    /**
     * @var Collection<string, Coupon>
     */
    private Collection $coupons;

    public function __construct(private BlockTiles $tiles) {}

    /**
     * @return array{main: array<int, array{id: string, type: string, settings: array<string, mixed>}>}
     */
    public function zones(Country $country, string $locale): array
    {
        $this->locale = $locale === 'ar' ? 'ar' : 'en';
        $this->currency = (string) $country->currency;
        $this->coupons = Coupon::query()->whereIn('code', ['FIRST15', 'NAWY10', 'SCENT10', 'GLOW', 'SALE200', 'WELL', 'BUY2', 'FREEBUDS'])
            ->where('is_active', true)->whereNull('admin_disabled_at')
            ->where(fn ($live) => $live->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($live) => $live->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->get()->keyBy(fn (Coupon $coupon): string => strtoupper($coupon->code));
        $place = self::PLACES[$country->code] ?? ['en' => (string) $country->name_en, 'ar' => (string) $country->name_ar, 'cities_en' => (string) $country->name_en, 'cities_ar' => (string) $country->name_ar];

        $blocks = [
            $this->hero(),
            $this->categoryStrip(),
            $this->panels(),
            $this->carousel('home-best-picks', $this->t('Best picks for you', 'أفضل الاختيارات لك'), ['source' => 'recommended', 'max_items' => 16]),
            $this->savings(),
            $this->carousel('home-bestsellers', $this->t('Bestsellers for you', 'الأكثر مبيعاً لك'), ['source' => 'bestsellers', 'max_items' => 16], '/search?sort=bestselling'),
            $this->freeEarbuds(),
            $this->fragrances(),
            $this->vehicles(),
            ['id' => 'home-verticals', 'type' => 'verticals', 'settings' => []],
            $this->carousel('home-grocery-deals', $this->t('Top bulk deals in grocery', 'أفضل عروض الجملة في البقالة'), ['source' => 'deals', 'category' => 'grocery', 'fallback_source' => 'category', 'max_items' => 16], $this->path('grocery'), 'grocery'),
            $this->showcase('womens-fashion', "Women's fashion", 'أزياء نسائية'),
            $this->showcase('mens-fashion', "Men's fashion", 'أزياء رجالية'),
            $this->showcase('electronics', 'Electronics', 'الإلكترونيات'),
            $this->find('home-appliances') !== null ? $this->showcase('home-appliances', 'Home appliances', 'الأجهزة المنزلية') : $this->showcase('home-kitchen', 'Home & kitchen', 'المنزل والمطبخ'),
            $this->showcase('beauty', 'Beauty', 'الجمال'),
            ['id' => 'home-creators', 'type' => 'creators_carousel', 'settings' => ['title' => $this->t('Shop with top creators', 'تسوّق مع أبرز صنّاع المحتوى'), 'view_all' => '/creators', 'max_items' => 12]],
            $this->realEstate(),
            $this->seo($place),
            $this->popularSearches(),
        ];

        return ['main' => array_values(array_filter($blocks))];
    }

    /**
     * Use this currency for {@see self::money()} without building the zones.
     */
    public function withCurrency(string $currency): static
    {
        $this->currency = $currency;

        return $this;
    }

    /**
     * An AED amount in the country's currency, rounded for copy:
     * "AED 249", "SAR 255", "OMR 25.400", "EGP 3,350".
     */
    public function money(float $aed): string
    {
        return $this->currency.' '.$this->amount($aed);
    }

    public function amount(float $aed): string
    {
        $value = $aed * (self::RATES[$this->currency] ?? 1.0);

        if (in_array($this->currency, self::THREE_DECIMALS, true)) {
            return number_format(round($value * 20) / 20, 3);
        }

        $step = match (true) {
            $value >= 1000 => 50,
            $value >= 100 => 5,
            default => 1,
        };

        return number_format(max(1, round($value / $step) * $step), 0);
    }

    /**
     * Large amounts in millions: "AED 1.8M", "EGP 24.2M".
     */
    public function millions(float $aed): string
    {
        $value = $aed * (self::RATES[$this->currency] ?? 1.0) / 1_000_000;

        return $this->currency.' '.rtrim(rtrim(number_format($value, $value < 10 ? 1 : 0), '0'), '.').'M';
    }

    /**
     * @return array<string, mixed>
     */
    private function hero(): array
    {
        $first = $this->coupons->get('FIRST15');
        $bank = $this->coupons->get('NAWY10');

        return ['id' => 'home-hero', 'type' => 'hero_bento', 'settings' => ['variant' => 'cards', 'slots' => [
            array_filter([
                'tone' => 'brand',
                'sticker' => $this->t('Welcome to Nawy', 'أهلاً بك في نوي'),
                'title' => $first !== null ? $this->t($this->percent($first).'% Cashback', 'استرداد نقدي '.$this->percent($first).'%') : $this->t('Free express delivery', 'توصيل سريع مجاني'),
                'subtitle' => $first !== null && $first->min_order_amount === null ? $this->t('No minimum order', 'بدون حد أدنى للطلب') : $this->t('On your first order', 'على طلبك الأول'),
                'code' => $first?->code,
                'image' => '/img/product-smartphone.png',
                'link' => '/deals',
            ]),
            ['tone' => 'light', 'sticker' => $this->t('Home week', 'أسبوع المنزل'), 'title' => $this->t('Kitchen & home up to 50% off', 'المطبخ والمنزل بخصم حتى 50%'), 'subtitle' => $this->t('Appliances, cookware & more', 'أجهزة وأدوات طبخ والمزيد'), 'image' => '/img/product-espresso.png', 'link' => $this->path('home-kitchen') ?? $this->path('home') ?? '/deals'],
            ['tone' => 'dark', 'sticker' => $this->t('Mega sale', 'تخفيضات كبرى'), 'title' => $this->t('Up to 70% off electronics', 'خصم حتى 70% على الإلكترونيات'), 'subtitle' => $this->t('Free express delivery', 'توصيل سريع مجاني'), 'image' => '/img/product-smartwatch.png', 'link' => $this->path('electronics') ?? '/deals'],
            ['items' => array_values(array_filter([
                $bank !== null ? ['tone' => 'subtle', 'sticker' => $this->t('Bank offer', 'عرض البنك'), 'title' => $this->t('Extra '.$this->percent($bank).'% off', 'خصم إضافي '.$this->percent($bank).'%'), 'subtitle' => $this->t('With Nawy credit cards', 'مع بطاقات نوي الائتمانية'), 'code' => $bank->code, 'link' => '/deals'] : null,
                ['tone' => 'success', 'title' => $this->t("Today's top deals", 'أفضل عروض اليوم'), 'subtitle' => $this->t('Explore and save', 'اكتشف ووفّر'), 'image' => '/img/product-sneakers.png', 'link' => '/deals'],
            ]))],
        ]]];
    }

    /**
     * @return array<string, mixed>
     */
    private function categoryStrip(): array
    {
        $items = [['label' => $this->t('Deals', 'العروض'), 'image' => '/img/lifestyle-flatlay.jpg', 'link' => '/deals']];

        foreach (['electronics', 'mobiles', 'laptops-accessories', 'beauty'] as $slug) {
            $items[] = ['category' => $slug];
        }

        $items[] = ['label' => $this->t('Gift cards', 'بطاقات الهدايا'), 'image' => '/img/promo-banner-wide.png', 'link' => '/gift-cards'];

        foreach (['womens-fashion', 'mens-fashion', 'toys', 'home-kitchen', 'health', 'wearables'] as $slug) {
            $items[] = ['category' => $slug];
        }

        return ['id' => 'home-categories', 'type' => 'tile_scroller', 'settings' => ['shape' => 'circle', 'live_pill' => true, 'items' => $items]];
    }

    /**
     * "More reasons to shop" · "Mega deals" · "In focus".
     *
     * @return array<string, mixed>
     */
    private function panels(): array
    {
        $sale = FlashSaleEvent::query()->where('slug', 'big-mega-deals')->whereIn('status', FlashSaleEventStatus::pricing())->where('ends_at', '>', now())->first()
            ?? FlashSaleEvent::query()->whereIn('status', FlashSaleEventStatus::pricing())->where('starts_at', '<=', now())->where('ends_at', '>', now())->orderBy('ends_at')->first();

        $columns = [
            ['width' => 1, 'block' => ['type' => 'tile_grid', 'settings' => ['title' => $this->t('More reasons to shop', 'المزيد من أسباب التسوّق'), 'items' => array_values(array_filter([
                $this->find('grocery') !== null ? ['category' => 'grocery', 'label' => $this->t('Grocery', 'البقالة'), 'caption' => $this->t('Top deals, wide selection', 'أفضل العروض وتشكيلة واسعة')] : null,
                ['label' => $this->t('Bestsellers', 'الأكثر مبيعاً'), 'caption' => $this->t('Shop our top picks', 'تسوّق أفضل اختياراتنا'), 'image' => '/img/product-earbuds.png', 'link' => '/search?sort=bestselling'],
                ['label' => $this->t('New arrivals', 'وصل حديثاً'), 'caption' => $this->t('The latest, curated for you', 'الأحدث، مختارة لك'), 'image' => '/img/product-smartphone.png', 'link' => '/search?sort=newest'],
                ['label' => $this->t('Nawy Plus', 'نوي بلس'), 'caption' => $this->t('Free delivery & member prices', 'توصيل مجاني وأسعار للأعضاء'), 'image' => '/img/product-perfume.png', 'link' => '/membership'],
            ]))]]],
        ];

        if ($sale !== null) {
            $columns[] = ['width' => 1, 'block' => ['type' => 'deal_grid', 'settings' => ['title' => $this->t('Mega deals', 'صفقات ضخمة'), 'flash_sale_slug' => $sale->slug, 'max_items' => 4]]];
        }

        $columns[] = ['width' => 1, 'block' => ['type' => 'promo_banner', 'settings' => ['variant' => 'cards', 'layout' => 'stacked', 'title' => $this->t('In focus', 'تحت الضوء'), 'items' => [
            ['tone' => 'dark', 'sticker' => $this->t('Mega sales', 'تخفيضات كبرى'), 'title' => $this->t('Up to 60% off wearables', 'خصم حتى 60% على الأجهزة القابلة للارتداء'), 'subtitle' => $this->t('Smartwatches & bands', 'ساعات ذكية وأساور'), 'image' => '/img/product-smartwatch.png', 'link' => $this->path('wearables') ?? $this->path('electronics') ?? '/deals'],
            ['tone' => 'subtle', 'sticker' => $this->t('Beauty edit', 'مختارات الجمال'), 'title' => $this->t('The science of better skin', 'علم البشرة الأجمل'), 'subtitle' => $this->t('Skincare from '.$this->money(29), 'العناية بالبشرة من '.$this->money(29)), 'link' => $this->path('skincare') ?? $this->path('beauty') ?? '/deals'],
        ]]]];

        return ['id' => 'home-panels', 'type' => 'panel_row', 'settings' => ['columns' => $columns]];
    }

    /**
     * "Maximize your savings": cards for the live public coupons.
     *
     * @return array<string, mixed>|null
     */
    private function savings(): ?array
    {
        $cards = [
            ['GLOW', 'beauty', '/img/product-perfume.png', 'Free beauty box', 'هدية علبة جمال', 'On beauty & fragrances', 'على الجمال والعطور'],
            ['SCENT10', 'fragrances', '/img/product-earbuds.png', 'Buy 2, get 10% off', 'اشترِ 2 واحصل على خصم 10%', 'On fragrances', 'على العطور'],
            ['SALE200', null, '/img/product-smartphone.png', null, null, 'On your order', 'على طلبك'],
            ['WELL', 'health', '/img/product-sneakers.png', 'Free wellness box', 'هدية علبة العافية', 'On health & wellness', 'على الصحة والعافية'],
        ];
        $items = [];

        foreach ($cards as [$code, $category, $image, $titleEn, $titleAr, $captionEn, $captionAr]) {
            $coupon = $this->coupons->get($code);

            if ($coupon === null) {
                continue;
            }

            $title = match (true) {
                (int) $coupon->buy_quantity >= 2 && $coupon->type === CouponType::Percentage => $this->t('Buy '.(int) $coupon->buy_quantity.', get '.$this->percent($coupon).'% off', 'اشترِ '.(int) $coupon->buy_quantity.' واحصل على خصم '.$this->percent($coupon).'%'),
                $titleEn === null => $this->couponTitle($coupon) ?? $code,
                default => $this->couponTitle($coupon) ?? $this->t((string) $titleEn, (string) $titleAr),
            };

            $caption = $coupon->min_order_amount !== null && (float) $coupon->min_order_amount > 0
                ? $this->t('On orders over '.$this->currency.' '.$this->format((float) $coupon->min_order_amount), 'على الطلبات فوق '.$this->currency.' '.$this->format((float) $coupon->min_order_amount))
                : $this->t($captionEn, $captionAr);

            $items[] = ['code' => $coupon->code, 'title' => $title, 'caption' => $caption, 'image' => $image, 'link' => ($category !== null ? $this->path($category) : null) ?? '/deals'];
        }

        return $items === [] ? null : ['id' => 'home-savings', 'type' => 'offer_cards', 'settings' => ['title' => $this->t('Maximize your savings', 'ضاعف توفيرك'), 'items' => $items]];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function freeEarbuds(): ?array
    {
        if ($this->find('mobiles') === null) {
            return null;
        }

        $coupon = $this->coupons->get('FREEBUDS');
        $title = $coupon !== null
            ? $this->t('Free earbuds worth '.$this->money(249).' with selected smartphones', 'سماعات مجانية بقيمة '.$this->money(249).' مع هواتف ذكية مختارة')
            : $this->t('Top smartphones', 'أفضل الهواتف الذكية');

        return $this->carousel('home-free-earbuds', $title, array_filter(['source' => $coupon !== null ? 'coupon' : 'category', 'coupon' => $coupon?->code, 'category' => 'mobiles', 'fallback_source' => 'category', 'max_items' => 16]), $this->path('mobiles'));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fragrances(): ?array
    {
        if ($this->find('fragrances') === null) {
            return null;
        }

        $coupon = $this->coupons->get('SCENT10');
        $title = $coupon !== null
            ? $this->t('Buy 2, get '.$this->percent($coupon).'% off on fragrances | Use code: '.$coupon->code, 'اشترِ 2 واحصل على خصم '.$this->percent($coupon).'% على العطور | استخدم الكود: '.$coupon->code)
            : $this->t('Fragrances you will love', 'عطور ستحبها');

        return $this->carousel('home-fragrances', $title, array_filter(['source' => $coupon !== null ? 'coupon' : 'category', 'coupon' => $coupon?->code, 'category' => 'fragrances', 'fallback_source' => 'category', 'max_items' => 16]), $this->path('fragrances'));
    }

    /**
     * @return array<string, mixed>
     */
    private function vehicles(): array
    {
        $ads = ClassifiedAd::query()->where('status', ClassifiedAdStatus::Active)->count();
        $count = $ads >= 1000 ? number_format(intdiv($ads, 1000) * 1000).'+ ' : '';

        return ['id' => 'home-vehicles', 'type' => 'banner_full', 'settings' => [
            'tone' => 'dark',
            'sticker' => $this->t('Open Souq', 'السوق المفتوح'),
            'title' => $this->t('Searching for a vehicle? '.$count.'cars, bikes & boats', 'تبحث عن مركبة؟ '.$count.'سيارات ودراجات وقوارب'),
            'subtitle' => $this->t('Buy from verified dealers and owners near you · post your ad in 2 minutes', 'اشترِ من تجار وملاك موثّقين بالقرب منك · انشر إعلانك خلال دقيقتين'),
            'link' => '/classified',
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    private function realEstate(): array
    {
        return ['id' => 'home-real-estate', 'type' => 'banner_full', 'settings' => [
            'tone' => 'subtle',
            'sticker' => $this->t('Real estate', 'العقارات'),
            'title' => $this->t('Marina Heights — waterfront homes from '.$this->millions(1_800_000), 'مارينا هايتس — منازل على الواجهة البحرية من '.$this->millions(1_800_000)),
            'subtitle' => $this->t('Listed by a verified broker on Nawy Real Estate · book a viewing', 'معروضة من وسيط موثّق على نوي للعقارات · احجز معاينة'),
            'link' => '/property',
        ]];
    }

    /**
     * @param  array{en: string, ar: string, cities_en: string, cities_ar: string}  $place
     * @return array<string, mixed>
     */
    private function seo(array $place): array
    {
        $text = $this->locale === 'ar'
            ? '<p>يوفّر لك نوي ملايين المنتجات من بائعين موثوقين في الإلكترونيات والأزياء والجمال والمنزل والبقالة — إضافة إلى الإعلانات المبوّبة وباقات السفر والعقارات في مكان واحد. استمتع بالتوصيل السريع المجاني والدفع عند الاستلام والإرجاع السهل.</p>'
                .'<p>تسوّق أحدث الهواتف الذكية وأجهزة اللابتوب والعطور والأجهزة المنزلية بأفضل الأسعار في '.$place['cities_ar'].'، مع مدفوعات آمنة وخيارات اشترِ الآن وادفع لاحقاً.</p>'
            : '<p>Nawy brings you millions of products from trusted sellers across electronics, fashion, beauty, home and grocery — plus classifieds, travel packages and real estate in one place. Enjoy free express delivery, cash on delivery and easy returns.</p>'
                .'<p>Shop the latest smartphones, laptops, perfumes and appliances at the best prices in '.$place['cities_en'].', with secure payments and Buy Now Pay Later options.</p>';

        return ['id' => 'home-seo', 'type' => 'seo_text', 'settings' => [
            'title' => $this->t('Superior online shopping in '.$place['en'], 'تسوّق إلكتروني متميّز في '.$place['ar']),
            'text' => $text,
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    private function popularSearches(): array
    {
        $keywords = $this->locale === 'ar'
            ? ['هواتف نوفا', 'أغطية آيفون', 'قلاية هوائية', 'عطر عود', 'أحذية جري', 'ساعة ذكية', 'لابتوب ألعاب', 'سماعات لاسلكية', 'ماكينة إسبريسو', 'عربة أطفال', 'عباية', 'باور بانك', 'سيارات مستعملة', 'شقق للإيجار', 'باقات عمرة', 'بطاقات هدايا', 'مجموعات العناية بالبشرة', 'كرسي مكتب', 'مكنسة روبوت', 'دراجات أطفال']
            : ['Nova X12', 'iPhone cases', 'Air fryer', 'Oud perfume', 'Running shoes', 'Smart watch', 'Gaming laptop', 'Earbuds', 'Espresso machine', 'Baby stroller', 'Abaya', 'Power bank', 'Used cars', 'Apartments for rent', 'Umrah packages', 'Gift cards', 'Skincare sets', 'Office chair', 'Robot vacuum', 'Kids bikes'];

        return ['id' => 'home-popular-searches', 'type' => 'popular_searches', 'settings' => ['title' => $this->t('Popular searches', 'عمليات البحث الشائعة'), 'keywords' => $keywords]];
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>|null
     */
    private function carousel(string $id, string $title, array $source, ?string $viewAll = null, ?string $requiredCategory = null): ?array
    {
        if ($requiredCategory !== null && $this->find($requiredCategory) === null) {
            return null;
        }

        return ['id' => $id, 'type' => 'product_carousel', 'settings' => array_filter(['title' => $title, ...$source, 'view_all' => $viewAll])];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function showcase(string $slug, string $en, string $ar): ?array
    {
        $path = $this->path($slug);

        return $path === null ? null : ['id' => 'home-showcase-'.$slug, 'type' => 'category_showcase', 'settings' => ['title' => $this->t($en, $ar), 'category' => $slug, 'max_items' => 7, 'view_all' => $path]];
    }

    private function couponTitle(Coupon $coupon): ?string
    {
        return match ($coupon->type) {
            CouponType::Percentage => $this->t($this->percent($coupon).'% off', 'خصم '.$this->percent($coupon).'%'),
            CouponType::FixedAmount => $this->t('Extra '.$this->currency.' '.$this->format((float) $coupon->value).' off', 'خصم إضافي '.$this->currency.' '.$this->format((float) $coupon->value)),
            CouponType::FreeShipping => $this->t('Free delivery', 'توصيل مجاني'),
            default => null,
        };
    }

    private function percent(Coupon $coupon): string
    {
        return rtrim(rtrim(number_format((float) $coupon->value, 2, '.', ''), '0'), '.');
    }

    /**
     * A stored amount (already in the country's currency) for copy.
     */
    private function format(float $amount): string
    {
        return in_array($this->currency, self::THREE_DECIMALS, true) ? number_format($amount, 3) : number_format($amount, fmod($amount, 1.0) === 0.0 ? 0 : 2);
    }

    private function find(string $slug): ?Category
    {
        return $this->tiles->find($slug);
    }

    private function path(string $slug): ?string
    {
        $category = $this->find($slug);

        return $category === null ? null : $this->tiles->path($category);
    }

    private function t(string $en, string $ar): string
    {
        return $this->locale === 'ar' ? $ar : $en;
    }
}
