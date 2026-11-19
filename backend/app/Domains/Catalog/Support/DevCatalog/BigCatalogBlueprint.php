<?php

namespace App\Domains\Catalog\Support\DevCatalog;

/**
 * Static data of the development "big catalogue" (nawy:dev:big-catalog):
 * the category tree it fills, product archetypes (image, brand family,
 * price band in AED, nouns, variant axes), fictional brands and the store
 * name parts per country. Only the 10 reused demo images are referenced
 * (owner decision); brands are fictional (no third-party marks).
 */
final class BigCatalogBlueprint
{
    /** The 10 demo images, copied once to the public disk under products/demo/. */
    public const IMAGES = [
        'phone' => 'product-smartphone.png',
        'earbuds' => 'product-earbuds.png',
        'watch' => 'product-smartwatch.png',
        'perfume' => 'product-perfume.png',
        'sneakers' => 'product-sneakers.png',
        'espresso' => 'product-espresso.png',
        'airfryer' => 'product-air-fryer.png',
        'abaya' => 'product-abaya.png',
        'flatlay' => 'lifestyle-flatlay.jpg',
        'banner' => 'promo-banner-wide.png',
    ];

    public const IMAGE_DIR = 'products/demo';

    /**
     * AED → local currency (fixed development rates) and decimals.
     *
     * @var array<string, array{0: float, 1: int}>
     */
    public const RATES = [
        'AED' => [1.0, 2], 'SAR' => [1.02, 2], 'QAR' => [0.99, 2], 'EGP' => [13.4, 2],
        'OMR' => [0.1048, 3], 'KWD' => [0.0837, 3], 'BHD' => [0.1026, 3], 'JOD' => [0.193, 3],
    ];

    /**
     * Root slug => [en, ar, image, weight, [l2 slug => [en, ar, [l3 slug => [en, ar, archetype]]]]].
     * Existing slugs are reused where they already sit at that place; the
     * others are created. Weight = share of the products.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: int, 4: array<string, array{0: string, 1: string, 2: array<string, array{0: string, 1: string, 2: string}>}>}>
     */
    public static function tree(): array
    {
        return [
            'electronics' => ['Electronics', 'إلكترونيات', 'phone', 22, [
                'mobiles' => ['Mobiles', 'هواتف', [
                    'mobiles-smartphones' => ['Smartphones', 'هواتف ذكية', 'phone'],
                    'mobiles-foldables' => ['Foldable phones', 'هواتف قابلة للطي', 'phone'],
                    'mobiles-cases' => ['Cases & covers', 'أغطية وحافظات', 'case'],
                    'mobiles-chargers' => ['Chargers & cables', 'شواحن وكابلات', 'charger'],
                ]],
                'audio' => ['Audio', 'صوتيات', [
                    'audio-earbuds' => ['Wireless earbuds', 'سماعات لاسلكية', 'earbuds'],
                    'audio-headphones' => ['Headphones', 'سماعات رأس', 'headphones'],
                    'audio-speakers' => ['Bluetooth speakers', 'مكبرات صوت بلوتوث', 'speaker'],
                ]],
                'mobiles-accessories' => ['Mobiles & Accessories', 'الهواتف والإكسسوارات', [
                    'tablets' => ['Tablets', 'أجهزة لوحية', 'tablet'],
                    'wearables' => ['Wearables', 'أجهزة قابلة للارتداء', 'smartwatch'],
                    'power-banks' => ['Power Banks', 'باور بانك', 'powerbank'],
                    'headphones-speakers' => ['Headphones & Speakers', 'سماعات ومكبرات صوت', 'speaker'],
                ]],
                'laptops-accessories' => ['Laptops & Accessories', 'لابتوبات وإكسسوارات', [
                    'performance-laptops' => ['Performance Laptops', 'لابتوبات عالية الأداء', 'laptop'],
                    'gaming-laptops' => ['Gaming Laptops', 'لابتوبات ألعاب', 'laptop'],
                    'budget-laptops' => ['Budget Laptops', 'لابتوبات اقتصادية', 'laptop'],
                    'monitors' => ['Monitors', 'شاشات', 'monitor'],
                    'storage-devices' => ['Storage Devices', 'أجهزة تخزين', 'storage'],
                    'input-devices' => ['Input Devices', 'أجهزة إدخال', 'keyboard'],
                ]],
                'gaming-essentials' => ['Gaming Essentials', 'أساسيات الألعاب', [
                    'gaming-consoles' => ['Gaming Consoles', 'أجهزة ألعاب', 'console'],
                    'gaming-accessories' => ['Gaming Accessories', 'إكسسوارات الألعاب', 'controller'],
                    'gaming-monitors' => ['Gaming Monitors', 'شاشات ألعاب', 'monitor'],
                ]],
                'televisions-home-entertainment' => ['Televisions & Home Entertainment', 'التلفزيونات والترفيه المنزلي', [
                    'led' => ['LED', 'LED', 'tv'],
                    'qled' => ['QLED', 'QLED', 'tv'],
                    'oled' => ['OLED', 'OLED', 'tv'],
                    'projectors' => ['Projectors', 'بروجكتورات', 'projector'],
                    'soundbars' => ['Soundbars', 'مكبرات صوت عمودية', 'speaker'],
                    'streaming-devices' => ['Streaming Devices', 'أجهزة البث', 'streaming'],
                ]],
                'cameras' => ['Cameras', 'كاميرات', [
                    'action-cameras' => ['Action Cameras', 'كاميرات الحركة', 'camera'],
                    'digital-cameras' => ['Digital Cameras', 'كاميرات رقمية', 'camera'],
                    'instant-cameras' => ['Instant Cameras', 'كاميرات فورية', 'camera'],
                    'camera-accessories' => ['Camera Accessories', 'إكسسوارات الكاميرا', 'charger'],
                ]],
                'smart-home' => ['Smart Home', 'المنزل الذكي', [
                    'smart-home-plugs' => ['Smart plugs', 'مقابس ذكية', 'smart_plug'],
                    'smart-home-cameras' => ['Smart cameras', 'كاميرات ذكية', 'smart_cam'],
                    'smart-home-lighting' => ['Smart lighting', 'إضاءة ذكية', 'smart_bulb'],
                ]],
            ]],
            'womens-fashion' => ["Women's Fashion", 'أزياء نسائية', 'abaya', 8, [
                'clothing' => ['Clothing', 'ملابس', [
                    'tops' => ['Tops', 'بلايز', 'top'],
                    'dresses' => ['Dresses', 'فساتين', 'dress'],
                    'wf-clothing-abayas' => ['Abayas', 'عبايات', 'abaya'],
                    'wf-clothing-jeans' => ['Jeans', 'جينز', 'jeans'],
                ]],
                'bags-accessories' => ['Bags & Accessories', 'حقائب وإكسسوارات', [
                    'handbags' => ['Handbags', 'حقائب يد', 'handbag'],
                    'backpacks' => ['Backpacks', 'حقائب ظهر', 'backpack'],
                    'wf-bags-watches' => ['Watches', 'ساعات', 'watch'],
                    'wf-bags-sunglasses' => ['Sunglasses', 'نظارات شمسية', 'sunglasses'],
                ]],
                'wf-shoes' => ['Shoes', 'أحذية', [
                    'wf-shoes-heels' => ['Heels', 'كعب عالي', 'heels'],
                    'wf-shoes-sneakers' => ['Sneakers', 'أحذية رياضية', 'sneaker'],
                    'wf-shoes-flats' => ['Flats & sandals', 'صنادل وأحذية مسطحة', 'heels'],
                ]],
            ]],
            'mens-fashion' => ["Men's Fashion", 'أزياء رجالية', 'sneakers', 7, [
                'mf-clothing' => ['Clothing', 'ملابس', [
                    'mf-clothing-tshirts' => ['T-shirts', 'تيشيرتات', 'tshirt'],
                    'mf-clothing-polo' => ['Polo shirts', 'قمصان بولو', 'tshirt'],
                    'mf-clothing-trousers' => ['Trousers', 'بناطيل', 'trousers'],
                    'mf-clothing-jeans' => ['Jeans', 'جينز', 'jeans'],
                    'mf-clothing-kandura' => ['Kanduras', 'كنادير', 'kandura'],
                ]],
                'springsummer' => ['Sportswear', 'ملابس رياضية', [
                    't-shirts' => ['T-shirts', 'تيشيرتات', 'sports_top'],
                    'polo-shirts' => ['Track tops', 'جاكيتات رياضية', 'sports_top'],
                    'mf-sportswear-shorts' => ['Trousers & shorts', 'بناطيل وشورتات', 'trousers'],
                ]],
                'shoes' => ['Shoes', 'أحذية', [
                    'sports-shoes' => ['Sports shoes', 'أحذية رياضية', 'running_shoe'],
                    'sneakers' => ['Sneakers', 'سنيكرز', 'sneaker'],
                    'mf-shoes-boots' => ['Boots', 'أحذية برقبة', 'formal_shoe'],
                    'mf-shoes-slippers' => ['Slippers', 'شباشب', 'sneaker'],
                ]],
                'mf-bags' => ['Bags & accessories', 'حقائب وإكسسوارات', [
                    'mf-bags-backpacks' => ['Backpacks', 'حقائب ظهر', 'backpack'],
                    'mf-bags-wallets' => ['Wallets', 'محافظ', 'wallet'],
                    'mf-bags-travel' => ['Travel bags', 'حقائب سفر', 'luggage'],
                    'mf-bags-watches' => ['Watches', 'ساعات', 'watch'],
                    'mf-bags-sunglasses' => ['Sunglasses', 'نظارات شمسية', 'sunglasses'],
                ]],
            ]],
            'kids-fashion' => ['Kids Fashion', 'أزياء الأطفال', 'abaya', 3, [
                'girls-clothing' => ['Girls Clothing', 'ملابس البنات', [
                    't-shirts-1' => ['T-shirts', 'تيشيرتات', 'kids_wear'],
                    'pants-leggings' => ['Pants & Leggings', 'بناطيل وليقنز', 'kids_wear'],
                ]],
                'kf-boys' => ['Boys Clothing', 'ملابس الأولاد', [
                    'kf-boys-tshirts' => ['T-shirts', 'تيشيرتات', 'kids_wear'],
                    'kf-boys-shorts' => ['Shorts', 'شورتات', 'kids_wear'],
                ]],
                'shoes-1' => ['Shoes', 'أحذية', [
                    'sports-shoes-1' => ['Sports Shoes', 'أحذية رياضية', 'kids_shoe'],
                    'sneakers-1' => ['Sneakers', 'سنيكرز', 'kids_shoe'],
                ]],
            ]],
            'fashion' => ['Fashion', 'أزياء', 'abaya', 4, [
                'abayas' => ['Abayas', 'عبايات', [
                    'abayas-classic' => ['Classic abayas', 'عبايات كلاسيكية', 'abaya'],
                    'abayas-embroidered' => ['Embroidered abayas', 'عبايات مطرزة', 'abaya'],
                    'abayas-kaftans' => ['Kaftans', 'قفاطين', 'abaya'],
                ]],
                'bags' => ['Bags & Luggage', 'حقائب وأمتعة', [
                    'bags-travel' => ['Luggage', 'أمتعة', 'luggage'],
                    'bags-totes' => ['Tote bags', 'حقائب توت', 'handbag'],
                ]],
                'jewellery' => ['Jewellery & Watches', 'مجوهرات وساعات', [
                    'jewellery-watches' => ['Watches', 'ساعات', 'watch'],
                    'jewellery-bracelets' => ['Bracelets', 'أساور', 'jewel'],
                ]],
                'sunglasses' => ['Sunglasses', 'نظارات شمسية', [
                    'sunglasses-aviator' => ['Aviator', 'أفياتور', 'sunglasses'],
                    'sunglasses-square' => ['Square frames', 'إطارات مربعة', 'sunglasses'],
                ]],
            ]],
            'beauty-fragrances' => ['Beauty & Fragrances', 'الجمال والعطور', 'perfume', 9, [
                'makeup' => ['Makeup', 'مكياج', [
                    'mascara' => ['Mascara', 'ماسكارا', 'makeup'],
                    'foundation' => ['Foundation', 'كريم أساس', 'makeup'],
                    'blush-bronzer' => ['Blush & Bronzer', 'أحمر خدود وبرونزر', 'makeup'],
                ]],
                'skincare' => ['Skincare', 'العناية بالبشرة', [
                    'moisturizers' => ['Moisturizers', 'مرطبات', 'skincare'],
                    'sunscreens' => ['Sunscreens', 'واقيات شمس', 'skincare'],
                    'cleansers' => ['Cleansers', 'منظفات', 'skincare'],
                ]],
                'fragrances' => ['Fragrances', 'عطور', [
                    'womens-fragrances' => ["Women's Fragrances", 'عطور نسائية', 'perfume'],
                    'mens-fragrances' => ["Men's Fragrances", 'عطور رجالية', 'perfume'],
                    'fragrances-oud' => ['Oud & bakhoor', 'عود وبخور', 'perfume'],
                ]],
            ]],
            'beauty' => ['Beauty & Personal Care', 'الجمال والعناية الشخصية', 'perfume', 5, [
                'haircare' => ['Hair Care', 'العناية بالشعر', [
                    'haircare-dryers' => ['Hair dryers', 'مجففات شعر', 'hair_tool'],
                    'haircare-straighteners' => ['Straighteners', 'مكاوي شعر', 'hair_tool'],
                    'haircare-shampoo' => ['Shampoo & conditioner', 'شامبو وبلسم', 'haircare'],
                ]],
                'mens-grooming' => ["Men's Grooming", 'العناية للرجال', [
                    'grooming-trimmers' => ['Trimmers', 'ماكينات حلاقة', 'trimmer'],
                    'grooming-beard' => ['Beard care', 'العناية باللحية', 'beauty_care'],
                ]],
                'beauty-bath-body' => ['Bath & body', 'الاستحمام والجسم', [
                    'bath-body-wash' => ['Body wash', 'غسول الجسم', 'beauty_care'],
                    'bath-body-lotions' => ['Body lotions', 'لوشن الجسم', 'beauty_care'],
                ]],
            ]],
            'home-kitchen' => ['Home & kitchen', 'المنزل والمطبخ', 'espresso', 7, [
                'kitchen-appliances' => ['Kitchen appliances', 'أجهزة المطبخ', [
                    'ka-coffee-makers' => ['Coffee makers', 'ماكينات قهوة', 'espresso'],
                    'ka-air-fryers' => ['Air fryers', 'قلايات هوائية', 'airfryer'],
                    'ka-blenders' => ['Blenders', 'خلاطات', 'blender'],
                ]],
                'home-appliances' => ['Home appliances', 'أجهزة منزلية', [
                    'ha-vacuums' => ['Vacuums', 'مكانس كهربائية', 'vacuum'],
                    'ha-air-conditioners' => ['Air conditioners', 'مكيفات', 'appliance'],
                    'ha-washing-machines' => ['Washing machines', 'غسالات', 'appliance'],
                    'ha-refrigerators' => ['Refrigerators', 'ثلاجات', 'appliance'],
                ]],
            ]],
            'home' => ['Home', 'المنزل', 'flatlay', 5, [
                'kitchen-dining' => ['Kitchen & Dining', 'المطبخ والطعام', [
                    'cookware' => ['Cookware', 'أواني الطبخ', 'cookware'],
                    'storage-organization' => ['Storage & Organization', 'التخزين والتنظيم', 'storage_home'],
                ]],
                'furniture' => ['Furniture', 'أثاث', [
                    'sofas' => ['Sofas', 'كنب', 'furniture'],
                    'coffee-tables' => ['Coffee Tables', 'طاولات قهوة', 'furniture'],
                ]],
                'bedding' => ['Bedding & Bath', 'المفروشات والحمام', [
                    'bedding-sheets' => ['Bed sheets', 'شراشف', 'bedding'],
                    'bedding-towels' => ['Towels', 'مناشف', 'bedding'],
                ]],
                'decor' => ['Home Décor', 'ديكور المنزل', [
                    'decor-vases' => ['Vases', 'مزهريات', 'decor'],
                    'decor-lamps' => ['Lamps', 'مصابيح', 'lamp'],
                ]],
            ]],
            'grocery' => ['Grocery', 'البقالة', 'flatlay', 6, [
                'home-care-supplies' => ['Home Care Supplies', 'مستلزمات العناية بالمنزل', [
                    'household-cleaners' => ['Household Cleaners', 'منظفات منزلية', 'cleaner'],
                    'laundry-care' => ['Laundry Care', 'العناية بالغسيل', 'cleaner'],
                ]],
                'beverages' => ['Beverages', 'مشروبات', [
                    'tea' => ['Tea', 'شاي', 'tea'],
                    'coffee' => ['Coffee', 'قهوة', 'coffee'],
                ]],
                'food-staples' => ['Food Staples', 'المواد الغذائية الأساسية', [
                    'staples-rice' => ['Rice & grains', 'أرز وحبوب', 'staple'],
                    'staples-dates' => ['Dates', 'تمور', 'dates'],
                ]],
                'snacks' => ['Snacks & Sweets', 'وجبات خفيفة وحلويات', [
                    'snacks-chocolate' => ['Chocolate', 'شوكولاتة', 'snack'],
                    'snacks-nuts' => ['Nuts', 'مكسرات', 'snack'],
                ]],
            ]],
            'baby-maternity' => ['Baby & Maternity', 'الأطفال والأمومة', 'flatlay', 3, [
                'baby-essentials' => ['Baby Essentials', 'أساسيات الطفل', [
                    'best-sellers' => ['Best Sellers', 'الأكثر مبيعاً', 'baby_care'],
                    'gift-shop' => ['Gift Shop', 'متجر الهدايا', 'toy'],
                ]],
                'feeding-essentials' => ['Feeding Essentials', 'مستلزمات الرضاعة', [
                    'breast-pumps' => ['Breast Pumps', 'شفاطات حليب', 'baby_feed'],
                    'baby-bottles' => ['Baby Bottles', 'رضاعات', 'baby_feed'],
                ]],
                'bm-diapering' => ['Diapering', 'الحفاضات', [
                    'bm-diapers' => ['Diapers', 'حفاضات', 'diaper'],
                    'bm-wipes' => ['Wipes', 'مناديل مبللة', 'diaper'],
                ]],
            ]],
            'toys' => ['Toys', 'ألعاب', 'flatlay', 4, [
                'outdoor-toys' => ['Outdoor Toys', 'ألعاب خارجية', [
                    'blasters' => ['Blasters', 'مسدسات مائية', 'toy'],
                    'trampolines-bouncers' => ['Trampolines & Bouncers', 'ترامبولين ونطاطيات', 'toy'],
                ]],
                'indoor-toys' => ['Indoor Toys', 'ألعاب داخلية', [
                    'puzzles' => ['Puzzles', 'ألغاز', 'puzzle'],
                    'card-board-games' => ['Card & Board Games', 'ألعاب الورق واللوح', 'board_game'],
                ]],
                'educational' => ['Educational', 'ألعاب تعليمية', [
                    'edu-stem' => ['STEM kits', 'ألعاب العلوم', 'toy'],
                    'edu-building' => ['Building blocks', 'مكعبات البناء', 'toy'],
                ]],
            ]],
            'sports' => ['Sports & Outdoors', 'الرياضة والهواء الطلق', 'sneakers', 4, [
                'fitness' => ['Fitness Equipment', 'معدات اللياقة', [
                    'fitness-dumbbells' => ['Dumbbells', 'دمبلز', 'fitness'],
                    'fitness-yoga' => ['Yoga mats', 'حصائر يوغا', 'fitness'],
                ]],
                'sportswear' => ['Sportswear', 'ملابس رياضية', [
                    'sportswear-tops' => ['Sports tops', 'قمصان رياضية', 'sports_top'],
                    'sportswear-shoes' => ['Running shoes', 'أحذية جري', 'running_shoe'],
                ]],
                'outdoor-sports' => ['Outdoor Sports', 'رياضات خارجية', [
                    'outdoor-camping' => ['Camping', 'تخييم', 'outdoor'],
                    'outdoor-cycling' => ['Cycling', 'دراجات', 'outdoor'],
                ]],
                'swimming' => ['Swimming', 'سباحة', [
                    'swimming-gear' => ['Swim gear', 'معدات السباحة', 'swim'],
                ]],
            ]],
            'health' => ['Health & Wellness', 'الصحة والعافية', 'perfume', 3, [
                'vitamins' => ['Vitamins & Supplements', 'فيتامينات ومكملات', [
                    'vitamins-multi' => ['Multivitamins', 'فيتامينات متعددة', 'vitamin'],
                    'vitamins-protein' => ['Protein', 'بروتين', 'vitamin'],
                ]],
                'medical-devices' => ['Medical Devices', 'أجهزة طبية', [
                    'medical-bp' => ['Blood pressure monitors', 'أجهزة ضغط الدم', 'medical'],
                    'medical-thermometers' => ['Thermometers', 'موازين حرارة', 'medical'],
                ]],
                'baby-care' => ['Baby Care', 'العناية بالطفل', [
                    'baby-care-skin' => ['Baby skincare', 'العناية ببشرة الطفل', 'baby_care'],
                ]],
            ]],
            'books' => ['Books & Stationery', 'الكتب والقرطاسية', 'flatlay', 2, [
                'arabic-books' => ['Arabic Books', 'كتب عربية', [
                    'arabic-books-novels' => ['Novels', 'روايات', 'book'],
                    'arabic-books-kids' => ["Children's books", 'كتب الأطفال', 'book'],
                ]],
                'english-books' => ['English Books', 'كتب إنجليزية', [
                    'english-books-fiction' => ['Fiction', 'روايات', 'book'],
                    'english-books-business' => ['Business', 'أعمال', 'book'],
                ]],
                'stationery' => ['Stationery', 'قرطاسية', [
                    'stationery-notebooks' => ['Notebooks', 'دفاتر', 'stationery'],
                    'stationery-pens' => ['Pens', 'أقلام', 'stationery'],
                ]],
            ]],
            'automotive' => ['Automotive', 'السيارات', 'airfryer', 2, [
                'car-care' => ['Car Care', 'العناية بالسيارة', [
                    'car-care-cleaning' => ['Cleaning & polish', 'تنظيف وتلميع', 'car_care'],
                ]],
                'car-accessories' => ['Car Accessories', 'إكسسوارات السيارة', [
                    'car-accessories-chargers' => ['Car chargers', 'شواحن سيارة', 'car_acc'],
                    'car-accessories-dashcams' => ['Dash cams', 'كاميرات السيارة', 'dashcam'],
                ]],
                'parts' => ['Parts & Tools', 'قطع غيار وأدوات', [
                    'parts-tools' => ['Tool kits', 'عدة أدوات', 'tools'],
                    'parts-batteries' => ['Batteries', 'بطاريات', 'car_acc'],
                ]],
            ]],
        ];
    }

    /**
     * Archetype => [image, brand family, min AED, max AED, nouns [en, ar][], axes, gender(s)].
     * Axes: storage | colour | size | shoe | none.
     *
     * @return array<string, array{0: string, 1: string, 2: int, 3: int, 4: array<int, array{0: string, 1: string}>, 5: array<int, string>, 6: array<int, string>}>
     */
    public static function archetypes(): array
    {
        $none = [];

        return [
            'phone' => ['phone', 'tech', 699, 5199, [['Smartphone', 'هاتف ذكي'], ['5G Smartphone', 'هاتف ذكي 5G']], ['storage', 'colour'], $none],
            'tablet' => ['phone', 'tech', 799, 3999, [['Tablet', 'جهاز لوحي'], ['Tab Pro', 'تاب برو']], ['storage', 'colour'], $none],
            'laptop' => ['phone', 'tech', 1899, 8999, [['Laptop', 'لابتوب'], ['Notebook Pro', 'نوتبوك برو']], ['storage', 'colour'], $none],
            'monitor' => ['phone', 'tech', 449, 2499, [['27" Monitor', 'شاشة 27 بوصة'], ['QHD Monitor', 'شاشة QHD']], [], $none],
            'storage' => ['phone', 'tech', 79, 699, [['Portable SSD', 'قرص SSD محمول'], ['Flash Drive', 'فلاش ميموري']], ['storage'], $none],
            'keyboard' => ['earbuds', 'tech', 59, 549, [['Wireless Keyboard', 'لوحة مفاتيح لاسلكية'], ['Wireless Mouse', 'ماوس لاسلكي']], ['colour'], $none],
            'console' => ['phone', 'tech', 1299, 2499, [['Game Console', 'جهاز ألعاب'], ['Handheld Console', 'جهاز ألعاب محمول']], ['storage'], $none],
            'controller' => ['earbuds', 'tech', 99, 449, [['Wireless Controller', 'يد تحكم لاسلكية'], ['Gaming Headset', 'سماعة ألعاب']], ['colour'], $none],
            'tv' => ['phone', 'tech', 1199, 9999, [['55" 4K Smart TV', 'تلفزيون ذكي 55 بوصة 4K'], ['65" Smart TV', 'تلفزيون ذكي 65 بوصة']], [], $none],
            'projector' => ['phone', 'tech', 599, 3499, [['Mini Projector', 'بروجكتور صغير'], ['4K Projector', 'بروجكتور 4K']], [], $none],
            'streaming' => ['phone', 'tech', 149, 399, [['Streaming Stick', 'جهاز بث'], ['4K Streaming Box', 'جهاز بث 4K']], [], $none],
            'camera' => ['phone', 'tech', 699, 6999, [['Action Camera', 'كاميرا حركة'], ['Mirrorless Camera', 'كاميرا بدون مرآة']], ['colour'], $none],
            'case' => ['phone', 'tech', 29, 149, [['Phone Case', 'غطاء هاتف'], ['MagSafe Case', 'غطاء مغناطيسي']], ['colour'], $none],
            'charger' => ['phone', 'tech', 39, 249, [['65W Fast Charger', 'شاحن سريع 65 واط'], ['USB-C Cable 2m', 'كابل USB-C بطول 2 متر']], ['colour'], $none],
            'powerbank' => ['phone', 'tech', 79, 299, [['Power Bank 20000mAh', 'باور بانك 20000 مللي أمبير'], ['MagSafe Power Bank', 'باور بانك مغناطيسي']], ['colour'], $none],
            'earbuds' => ['earbuds', 'tech', 99, 999, [['Earbuds Pro ANC', 'سماعات برو بخاصية إلغاء الضوضاء'], ['Wireless Earbuds', 'سماعات لاسلكية']], ['colour'], $none],
            'headphones' => ['earbuds', 'tech', 199, 1599, [['Over-ear Headphones', 'سماعات رأس'], ['ANC Headphones', 'سماعات عازلة للضوضاء']], ['colour'], $none],
            'speaker' => ['earbuds', 'tech', 149, 1899, [['Bluetooth Speaker', 'مكبر صوت بلوتوث'], ['Soundbar 5.1', 'مكبر صوت عمودي 5.1']], ['colour'], $none],
            'smartwatch' => ['watch', 'tech', 299, 1999, [['Smart Watch Series 5', 'ساعة ذكية الإصدار 5'], ['Fitness Band', 'سوار لياقة']], ['colour', 'size'], ['Unisex']],
            'smart_plug' => ['phone', 'tech', 49, 199, [['Smart Plug', 'مقبس ذكي'], ['Smart Hub', 'محور ذكي']], [], $none],
            'smart_cam' => ['phone', 'tech', 149, 799, [['Security Camera 2K', 'كاميرا مراقبة 2K'], ['Video Doorbell', 'جرس باب بالفيديو']], [], $none],
            'smart_bulb' => ['phone', 'tech', 39, 249, [['Smart Bulb', 'لمبة ذكية'], ['LED Strip', 'شريط LED']], ['colour'], $none],
            'abaya' => ['abaya', 'fashion', 149, 1299, [['Classic Abaya', 'عباية كلاسيكية'], ['Embroidered Abaya', 'عباية مطرزة'], ['Linen Kaftan', 'قفطان كتان']], ['colour', 'size'], ['Women']],
            'kandura' => ['abaya', 'fashion', 149, 599, [['Kandura', 'كندورة'], ['Summer Kandura', 'كندورة صيفية']], ['colour', 'size'], ['Men']],
            'dress' => ['abaya', 'fashion', 99, 899, [['Maxi Dress', 'فستان طويل'], ['Midi Dress', 'فستان متوسط']], ['colour', 'size'], ['Women']],
            'top' => ['abaya', 'fashion', 49, 299, [['Blouse', 'بلوزة'], ['Knit Top', 'بلوزة تريكو']], ['colour', 'size'], ['Women']],
            'tshirt' => ['abaya', 'fashion', 39, 199, [['Cotton T-shirt', 'تيشيرت قطن'], ['Polo Shirt', 'قميص بولو']], ['colour', 'size'], ['Men']],
            'trousers' => ['abaya', 'fashion', 79, 349, [['Chino Trousers', 'بنطال تشينو'], ['Jogger Pants', 'بنطال رياضي']], ['colour', 'size'], ['Men']],
            'jeans' => ['abaya', 'fashion', 99, 449, [['Slim Fit Jeans', 'جينز ضيق'], ['Straight Jeans', 'جينز مستقيم']], ['colour', 'size'], ['Women', 'Men']],
            'sports_top' => ['sneakers', 'sport', 59, 299, [['Training Tee', 'تيشيرت تمرين'], ['Track Jacket', 'جاكيت رياضي']], ['colour', 'size'], ['Men', 'Women']],
            'kids_wear' => ['abaya', 'kids', 29, 149, [['Kids T-shirt', 'تيشيرت أطفال'], ['Kids Set', 'طقم أطفال']], ['colour', 'size'], ['Girls', 'Boys']],
            'sneaker' => ['sneakers', 'sport', 149, 899, [['Air Sneakers', 'حذاء سنيكرز'], ['Court Sneakers', 'حذاء كورت']], ['colour', 'shoe'], ['Men', 'Women', 'Unisex']],
            'running_shoe' => ['sneakers', 'sport', 199, 799, [['Air Running Shoes', 'حذاء جري'], ['Trail Runner', 'حذاء جري للمسارات']], ['colour', 'shoe'], ['Men', 'Women']],
            'formal_shoe' => ['sneakers', 'fashion', 199, 699, [['Leather Boots', 'جزمة جلد'], ['Chelsea Boots', 'جزمة تشيلسي']], ['colour', 'shoe'], ['Men']],
            'heels' => ['sneakers', 'fashion', 129, 599, [['Block Heels', 'كعب عريض'], ['Leather Sandals', 'صندل جلد']], ['colour', 'shoe'], ['Women']],
            'kids_shoe' => ['sneakers', 'kids', 79, 299, [['Kids Sneakers', 'سنيكرز أطفال'], ['Kids Runners', 'حذاء جري أطفال']], ['colour', 'shoe'], ['Girls', 'Boys']],
            'handbag' => ['abaya', 'fashion', 149, 1499, [['Leather Handbag', 'حقيبة يد جلد'], ['Tote Bag', 'حقيبة توت']], ['colour'], ['Women']],
            'backpack' => ['abaya', 'fashion', 99, 499, [['Laptop Backpack', 'حقيبة ظهر للابتوب'], ['City Backpack', 'حقيبة ظهر']], ['colour'], ['Unisex']],
            'luggage' => ['abaya', 'fashion', 299, 1499, [['Cabin Trolley', 'حقيبة سفر مقصورة'], ['Large Suitcase', 'حقيبة سفر كبيرة']], ['colour'], ['Unisex']],
            'wallet' => ['watch', 'fashion', 59, 399, [['Leather Wallet', 'محفظة جلد'], ['Leather Belt', 'حزام جلد']], ['colour'], ['Men']],
            'watch' => ['watch', 'fashion', 199, 2499, [['Chronograph Watch', 'ساعة كرونوغراف'], ['Classic Watch', 'ساعة كلاسيكية']], ['colour'], ['Men', 'Women']],
            'jewel' => ['watch', 'fashion', 99, 999, [['Gold-plated Bracelet', 'سوار مطلي بالذهب'], ['Charm Bracelet', 'سوار تعليقات']], ['colour'], ['Women']],
            'sunglasses' => ['watch', 'fashion', 149, 899, [['Aviator Sunglasses', 'نظارة أفياتور'], ['Square Sunglasses', 'نظارة مربعة']], ['colour'], ['Men', 'Women', 'Unisex']],
            'perfume' => ['perfume', 'beauty', 129, 1299, [['Royal Oud 100ml', 'عود ملكي 100 مل'], ['Eau de Parfum 90ml', 'أو دو بارفان 90 مل'], ['Musk Al Tahara 50ml', 'مسك الطهارة 50 مل']], [], ['Women', 'Men', 'Unisex']],
            'makeup' => ['perfume', 'beauty', 39, 249, [['Volume Mascara', 'ماسكارا للكثافة'], ['Matte Foundation', 'كريم أساس مطفي'], ['Blush Palette', 'باليت أحمر خدود']], ['colour'], ['Women']],
            'skincare' => ['perfume', 'beauty', 49, 399, [['Hydra Moisturizer', 'مرطب مائي'], ['SPF 50 Sunscreen', 'واقي شمس SPF 50'], ['Gentle Cleanser', 'غسول لطيف']], [], ['Women', 'Unisex']],
            'haircare' => ['perfume', 'beauty', 29, 149, [['Argan Shampoo', 'شامبو أرغان'], ['Repair Conditioner', 'بلسم مرمم']], [], ['Unisex']],
            'hair_tool' => ['airfryer', 'beauty', 129, 1299, [['Ionic Hair Dryer', 'مجفف شعر أيوني'], ['Ceramic Straightener', 'مكواة شعر سيراميك']], ['colour'], ['Women']],
            'trimmer' => ['airfryer', 'beauty', 99, 599, [['Beard Trimmer', 'ماكينة تشذيب اللحية'], ['Grooming Kit', 'طقم عناية']], [], ['Men']],
            'beauty_care' => ['perfume', 'beauty', 19, 199, [['Body Wash 500ml', 'غسول جسم 500 مل'], ['Body Lotion', 'لوشن الجسم'], ['Beard Oil', 'زيت اللحية']], [], ['Unisex']],
            'espresso' => ['espresso', 'home', 399, 3499, [['Pro Espresso Machine', 'ماكينة إسبريسو برو'], ['Capsule Coffee Maker', 'ماكينة قهوة كبسولات']], ['colour'], $none],
            'airfryer' => ['airfryer', 'home', 249, 999, [['Air Fryer 5.5L', 'قلاية هوائية 5.5 لتر'], ['Dual Air Fryer', 'قلاية هوائية مزدوجة']], ['colour'], $none],
            'blender' => ['espresso', 'home', 149, 899, [['Power Blender', 'خلاط قوي'], ['Juicer', 'عصارة']], ['colour'], $none],
            'vacuum' => ['airfryer', 'home', 399, 2999, [['Cordless Vacuum', 'مكنسة لاسلكية'], ['Robot Vacuum', 'مكنسة روبوت']], [], $none],
            'appliance' => ['airfryer', 'home', 999, 4999, [['Split AC 1.5 Ton', 'مكيف سبليت 1.5 طن'], ['Front Load Washer 9kg', 'غسالة أمامية 9 كغ'], ['Double Door Fridge', 'ثلاجة بابين']], [], $none],
            'cookware' => ['airfryer', 'home', 79, 899, [['Non-stick Cookware Set', 'طقم أواني غير لاصقة'], ['Cast Iron Pan', 'مقلاة حديد']], ['colour'], $none],
            'storage_home' => ['flatlay', 'home', 29, 199, [['Storage Box Set', 'طقم صناديق تخزين'], ['Pantry Organizer', 'منظم مخزن']], [], $none],
            'furniture' => ['flatlay', 'home', 599, 4999, [['3-Seater Sofa', 'كنبة 3 مقاعد'], ['Oak Coffee Table', 'طاولة قهوة خشب']], ['colour'], $none],
            'bedding' => ['flatlay', 'home', 79, 599, [['Cotton Bed Sheet Set', 'طقم شراشف قطن'], ['Bath Towel Set', 'طقم مناشف']], ['colour', 'size'], $none],
            'decor' => ['flatlay', 'home', 49, 399, [['Ceramic Vase', 'مزهرية سيراميك'], ['Photo Frame Set', 'طقم إطارات صور']], ['colour'], $none],
            'lamp' => ['flatlay', 'home', 99, 699, [['Table Lamp', 'مصباح طاولة'], ['Floor Lamp', 'مصباح أرضي']], ['colour'], $none],
            'cleaner' => ['flatlay', 'grocery', 9, 69, [['Multi-surface Cleaner 1L', 'منظف متعدد الأسطح 1 لتر'], ['Laundry Detergent 3L', 'منظف غسيل 3 لتر']], [], $none],
            'tea' => ['espresso', 'grocery', 12, 79, [['Black Tea 100 bags', 'شاي أسود 100 كيس'], ['Green Tea', 'شاي أخضر']], [], $none],
            'coffee' => ['espresso', 'grocery', 19, 129, [['Arabica Beans 1kg', 'حبوب أرابيكا 1 كغ'], ['Arabic Coffee with Cardamom', 'قهوة عربية بالهيل']], [], $none],
            'staple' => ['flatlay', 'grocery', 15, 99, [['Basmati Rice 5kg', 'أرز بسمتي 5 كغ'], ['Organic Honey 500g', 'عسل عضوي 500 غ']], [], $none],
            'dates' => ['flatlay', 'grocery', 25, 199, [['Medjool Dates 1kg', 'تمر مجدول 1 كغ'], ['Sukkari Dates Box', 'علبة تمر سكري']], [], $none],
            'snack' => ['flatlay', 'grocery', 9, 89, [['Dark Chocolate Box', 'علبة شوكولاتة داكنة'], ['Mixed Nuts 500g', 'مكسرات مشكلة 500 غ']], [], $none],
            'baby_care' => ['perfume', 'kids', 19, 199, [['Baby Lotion', 'لوشن أطفال'], ['Baby Care Set', 'طقم العناية بالطفل']], [], ['Kids']],
            'baby_feed' => ['flatlay', 'kids', 39, 799, [['Anti-colic Bottle Set', 'طقم رضاعات ضد المغص'], ['Electric Breast Pump', 'شفاط حليب كهربائي']], [], $none],
            'diaper' => ['flatlay', 'kids', 39, 149, [['Diapers Size 4, 64 pcs', 'حفاضات مقاس 4، 64 قطعة'], ['Baby Wipes 12 pack', 'مناديل أطفال 12 عبوة']], ['size'], ['Kids']],
            'toy' => ['flatlay', 'kids', 29, 599, [['Water Blaster', 'مسدس ماء'], ['STEM Robot Kit', 'طقم روبوت تعليمي'], ['Building Blocks 500 pcs', 'مكعبات 500 قطعة']], [], ['Kids']],
            'puzzle' => ['flatlay', 'kids', 29, 149, [['1000-piece Puzzle', 'لغز 1000 قطعة'], ['3D Puzzle', 'لغز ثلاثي الأبعاد']], [], ['Kids']],
            'board_game' => ['flatlay', 'kids', 49, 249, [['Family Board Game', 'لعبة لوحية عائلية'], ['Card Game', 'لعبة ورق']], [], ['Kids']],
            'fitness' => ['sneakers', 'sport', 49, 999, [['Adjustable Dumbbells', 'دمبلز قابلة للتعديل'], ['Yoga Mat 6mm', 'حصيرة يوغا 6 مم']], ['colour'], ['Unisex']],
            'outdoor' => ['sneakers', 'sport', 99, 1999, [['Camping Tent 4P', 'خيمة 4 أشخاص'], ['City Bike', 'دراجة مدينة']], ['colour'], ['Unisex']],
            'swim' => ['sneakers', 'sport', 29, 199, [['Swim Goggles', 'نظارة سباحة'], ['Swim Cap', 'قبعة سباحة']], ['colour'], ['Unisex']],
            'vitamin' => ['perfume', 'health', 39, 299, [['Multivitamin 90 tabs', 'فيتامينات متعددة 90 قرص'], ['Whey Protein 2kg', 'واي بروتين 2 كغ']], [], ['Unisex']],
            'medical' => ['watch', 'health', 59, 499, [['Blood Pressure Monitor', 'جهاز قياس ضغط الدم'], ['Infrared Thermometer', 'ميزان حرارة بالأشعة']], [], $none],
            'book' => ['flatlay', 'books', 19, 119, [['Novel, paperback', 'رواية، غلاف ورقي'], ['Illustrated Edition', 'طبعة مصورة']], [], $none],
            'stationery' => ['flatlay', 'books', 9, 99, [['A5 Notebook Set', 'طقم دفاتر A5'], ['Gel Pen Set', 'طقم أقلام جل']], ['colour'], $none],
            'car_care' => ['airfryer', 'auto', 29, 299, [['Car Polish Kit', 'طقم تلميع السيارة'], ['Pressure Washer', 'غسالة ضغط']], [], $none],
            'car_acc' => ['phone', 'auto', 39, 799, [['Car Charger 2-port', 'شاحن سيارة منفذين'], ['Car Battery 70Ah', 'بطارية سيارة 70 أمبير']], [], $none],
            'dashcam' => ['phone', 'auto', 199, 899, [['4K Dash Cam', 'كاميرا سيارة 4K'], ['Front & Rear Dash Cam', 'كاميرا أمامية وخلفية']], [], $none],
            'tools' => ['airfryer', 'auto', 99, 699, [['Tool Kit 120 pcs', 'عدة أدوات 120 قطعة'], ['Cordless Drill', 'دريل لاسلكي']], [], $none],
        ];
    }

    /**
     * Fictional brands per family: slug => [name, name_ar].
     *
     * @return array<string, array<string, array{0: string, 1: string}>>
     */
    public static function brands(): array
    {
        return [
            'tech' => ['nova' => ['Nova', 'نوفا'], 'soundmax' => ['SoundMax', 'ساوند ماكس'], 'pulse' => ['Pulse', 'بلس'], 'voltix' => ['Voltix', 'فولتكس'], 'orbit' => ['Orbit', 'أوربت'], 'lumen' => ['Lumen', 'لومن'], 'kairo' => ['Kairo', 'كايرو'], 'zentra' => ['Zentra', 'زنترا']],
            'fashion' => ['stride' => ['Stride', 'سترايد'], 'r-and-v' => ['R&V', 'آر آند في'], 'sahara-line' => ['Sahara Line', 'صحارى لاين'], 'medina' => ['Medina', 'مدينة'], 'urban-dune' => ['Urban Dune', 'أوربان ديون'], 'layla' => ['Layla', 'ليلى'], 'noor-atelier' => ['Noor Atelier', 'نور أتيليه']],
            'sport' => ['stride' => ['Stride', 'سترايد'], 'cardio' => ['Cardio', 'كارديو'], 'peak-arena' => ['Peak Arena', 'بيك أرينا'], 'falcon-fit' => ['Falcon Fit', 'فالكون فيت']],
            'beauty' => ['al-noor' => ['Al Noor', 'النور'], 'verda' => ['Verda', 'فيردا'], 'define' => ['Define', 'ديفاين'], 'grimel' => ['Grimel', 'غريميل'], 'oudh-house' => ['Oudh House', 'بيت العود'], 'lumi-skin' => ['Lumi Skin', 'لومي سكن']],
            'home' => ['brewmaster' => ['Brewmaster', 'برو ماستر'], 'casa-bella' => ['Casa Bella', 'كازا بيلا'], 'homecraft' => ['HomeCraft', 'هوم كرافت'], 'kitchenly' => ['Kitchenly', 'كيتشنلي'], 'dar-living' => ['Dar Living', 'دار ليفنج']],
            'grocery' => ['bayt-farms' => ['Bayt Farms', 'مزارع البيت'], 'oasis-pantry' => ['Oasis Pantry', 'مخزن الواحة'], 'golden-date' => ['Golden Date', 'التمرة الذهبية'], 'al-waha' => ['Al Waha', 'الواحة']],
            'kids' => ['tiny-steps' => ['Tiny Steps', 'خطوات صغيرة'], 'playnest' => ['PlayNest', 'بلاي نست'], 'bubbo' => ['Bubbo', 'بوبو']],
            'health' => ['well-plus' => ['Well+', 'ويل بلس'], 'vitara' => ['Vitara', 'فيتارا']],
            'books' => ['qalam-press' => ['Qalam Press', 'دار القلم'], 'inkwell' => ['Inkwell', 'إنكويل']],
            'auto' => ['autoglow' => ['AutoGlow', 'أوتو جلو'], 'roadpro' => ['RoadPro', 'رود برو']],
        ];
    }

    /** Model names used in product titles. */
    public const MODELS = ['X12', 'S9', 'Pro', 'Air', 'Max', 'Lite', 'Ultra', 'Neo', 'Plus', 'One', 'Edge', 'Prime', 'Go', 'Flex', 'Mini', 'Elite'];

    /** @var array<string, array<int, array{0: string, 1: string}>> */
    public const AXES = [
        'storage' => [['128GB', '128 جيجابايت'], ['256GB', '256 جيجابايت'], ['512GB', '512 جيجابايت'], ['1TB', '1 تيرابايت']],
        'colour' => [['Black', 'أسود'], ['White', 'أبيض'], ['Silver', 'فضي'], ['Blue', 'أزرق'], ['Beige', 'بيج'], ['Green', 'أخضر'], ['Pink', 'وردي'], ['Gold', 'ذهبي']],
        'size' => [['S', 'S'], ['M', 'M'], ['L', 'L'], ['XL', 'XL']],
        'shoe' => [['40', '40'], ['41', '41'], ['42', '42'], ['43', '43'], ['44', '44']],
    ];

    /**
     * Store-name parts per country: [places en/ar, family words].
     *
     * @return array<string, array<int, array{0: string, 1: string}>>
     */
    public static function places(): array
    {
        return [
            'uae' => [['Dubai', 'دبي'], ['Sharjah', 'الشارقة'], ['Abu Dhabi', 'أبوظبي'], ['Al Ain', 'العين'], ['Ajman', 'عجمان'], ['Deira', 'ديرة'], ['Jumeirah', 'جميرا'], ['Marina', 'المارينا'], ['Fujairah', 'الفجيرة'], ['Al Barsha', 'البرشاء']],
            'omn' => [['Muscat', 'مسقط'], ['Muttrah', 'مطرح'], ['Sohar', 'صحار'], ['Salalah', 'صلالة'], ['Nizwa', 'نزوى'], ['Seeb', 'السيب'], ['Ruwi', 'روي'], ['Sur', 'صور'], ['Ibri', 'عبري'], ['Barka', 'بركاء']],
            'ksa' => [['Riyadh', 'الرياض'], ['Jeddah', 'جدة'], ['Dammam', 'الدمام'], ['Makkah', 'مكة'], ['Madinah', 'المدينة'], ['Khobar', 'الخبر'], ['Taif', 'الطائف'], ['Abha', 'أبها'], ['Najd', 'نجد'], ['Qassim', 'القصيم']],
            'egy' => [['Cairo', 'القاهرة'], ['Alexandria', 'الإسكندرية'], ['Giza', 'الجيزة'], ['Zamalek', 'الزمالك'], ['Maadi', 'المعادي'], ['Heliopolis', 'مصر الجديدة'], ['Nile', 'النيل'], ['Mansoura', 'المنصورة'], ['Tanta', 'طنطا'], ['Luxor', 'الأقصر']],
            'kwt' => [['Kuwait', 'الكويت'], ['Salmiya', 'السالمية'], ['Hawally', 'حولي'], ['Fahaheel', 'الفحيحيل'], ['Jahra', 'الجهراء'], ['Farwaniya', 'الفروانية'], ['Sharq', 'شرق'], ['Mubarakiya', 'المباركية'], ['Ahmadi', 'الأحمدي'], ['Mangaf', 'المنقف']],
            'qat' => [['Doha', 'الدوحة'], ['Al Wakrah', 'الوكرة'], ['Lusail', 'لوسيل'], ['Al Khor', 'الخور'], ['West Bay', 'الخليج الغربي'], ['Souq Waqif', 'سوق واقف'], ['Pearl', 'اللؤلؤة'], ['Al Rayyan', 'الريان'], ['Msheireb', 'مشيرب'], ['Al Sadd', 'السد']],
            'bhr' => [['Manama', 'المنامة'], ['Muharraq', 'المحرق'], ['Riffa', 'الرفاع'], ['Seef', 'السيف'], ['Juffair', 'الجفير'], ['Isa Town', 'مدينة عيسى'], ['Hamad Town', 'مدينة حمد'], ['Sitra', 'سترة'], ['Budaiya', 'البديع'], ['Adliya', 'العدلية']],
            'jor' => [['Amman', 'عمّان'], ['Irbid', 'إربد'], ['Zarqa', 'الزرقاء'], ['Aqaba', 'العقبة'], ['Madaba', 'مادبا'], ['Jerash', 'جرش'], ['Petra', 'البتراء'], ['Salt', 'السلط'], ['Abdali', 'العبدلي'], ['Rainbow', 'الرينبو']],
        ];
    }

    /**
     * Store kinds: family (or general) => [en word, ar word].
     *
     * @var array<int, array{0: string, 1: string, 2: string}>
     */
    public const STORE_KINDS = [
        ['tech', 'Electronics', 'للإلكترونيات'], ['tech', 'Digital', 'ديجيتال'], ['tech', 'Mobile Center', 'سنتر الموبايل'],
        ['fashion', 'Fashion House', 'دار الأزياء'], ['fashion', 'Boutique', 'بوتيك'], ['sport', 'Sports', 'للرياضة'],
        ['beauty', 'Perfumes', 'للعطور'], ['beauty', 'Beauty Corner', 'ركن الجمال'], ['home', 'Home Store', 'للأدوات المنزلية'],
        ['home', 'Appliances', 'للأجهزة المنزلية'], ['grocery', 'Hypermarket', 'هايبر ماركت'], ['grocery', 'Fresh Mart', 'فريش مارت'],
        ['kids', 'Kids World', 'عالم الأطفال'], ['health', 'Pharmacy', 'صيدلية'], ['books', 'Bookshop', 'مكتبة'],
        ['auto', 'Auto Parts', 'لقطع غيار السيارات'], ['general', 'Trading', 'للتجارة'], ['general', 'Souq', 'سوق'],
        ['general', 'Mega Store', 'ميجا ستور'], ['general', 'Online', 'أونلاين'],
    ];

    /** Brand stores (vendor slug {brand}-official): the "Supermall / direct from brand" rail option. */
    public const OFFICIAL_STORES = ['nova', 'soundmax', 'pulse', 'al-noor', 'stride', 'brewmaster', 'verda', 'casa-bella'];

    /** A local amount from an AED amount, rounded to a retail-looking price. */
    public static function price(float $aed, string $currency): string
    {
        [$rate, $decimals] = self::RATES[$currency] ?? [1.0, 2];
        $value = $aed * $rate;

        if ($decimals === 3) {
            $value = max(0.5, round($value * 20) / 20); // 0.050 steps

            return number_format($value, 3, '.', '');
        }

        $value = $value >= 100 ? floor($value / 5) * 5 + ($value >= 1000 ? 9 : 4) : max(1, round($value)) - 0.01;

        return number_format(max(0.99, $value), 2, '.', '');
    }
}
