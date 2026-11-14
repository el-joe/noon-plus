# Noon UAE homepage audit — live, 2026-11-14 (desktop 1440, /uae-en/)
Method: built-in browser, full scroll to trigger lazy sections, DOM heading extraction + screenshots. Status: **inspected successfully** (public page). Content rotates daily — structure is what we replicate, not their assets/brands.

| # | Section (their heading) | Layout | Our block type |
|---|---|---|---|
| 0 | Header (yellow) | logo · location "Other – Dubai" · search with rotating placeholder ("Search for "Sunscreen"") · العربية · Log in · Orders · Wishlist · Cart | `global.header` (not a block) |
| 1 | Category nav | text links + overflow arrow + membership pill "Get Free Delivery with noon one" | `global.category_nav` |
| 2 | Hero | **bento**: 3 tall portrait banners (carousel, pause control) + right column with 2 stacked landscape banners (bank-card offer, "Today's top deals") | `hero_bento` |
| 3 | Category tiles | ~12 rounded-square image tiles + label, horizontal scroll with arrows ("Deals", "Electronics", "Mobiles", "Laptops & Desktops", "Beauty", "Gift Cards", …) | `category_tiles` |
| 4 | 3-up panel row | **More reasons to shop** (2×2 image tiles, title + subtitle) · **Mega deals** (countdown chip + "ALL DEALS", 2×2 mini product cards with deal ribbons) · **In focus** (2 stacked banners) | `panel_row` with children `tile_grid`, `deal_grid`, `banner_stack` |
| 5 | Best picks for you | dense product carousel (~12 visible, compact cards, express/badges) | `product_carousel` (source: personalised) |
| 6 | Maximize your savings | offer cards carousel: image, title, coupon code chip ("Code: SALE200") | `offer_cards` |
| 7 | Bestsellers for you | product carousel | `product_carousel` (source: bestsellers) |
| 8 | Free soundbar worth 199 AED | campaign product carousel + VIEW ALL | `product_carousel` (source: campaign) |
| 9 | Buy 2 Get 10% off on Fragrances \| Use code : FRAGRANCE10 | coupon-linked product carousel + VIEW ALL | `product_carousel` (source: coupon) |
| 10 | Full-width banner (rotates: "Searching for a vehicle?", "The Gift Store") | 1 wide banner | `banner_full` |
| 11 | Top bulk deals in grocery | product carousel | `product_carousel` (source: collection) |
| 12 | Category showcases ×16 (Women's fashion, Men's fashion, Beauty, Home & kitchen, Home appliances, Electronics, Mobiles & accessories, Laptops & accessories, Kids' fashion, Health & nutrition, Grocery, Furniture, Automotive, Stationery & office, Books & media, …) | title + VIEW ALL + 7 square image tiles each | `category_showcase` (repeatable) |
| 13 | Partner brand banner (real-estate developer ad) | wide sponsored banner | `banner_full` (sponsored=true) |
| 14 | SEO copy ("Superior online shopping in the UAE", …) | long text with links, "Read more" | `seo_text` |
| 15 | Popular Searches | dense keyword chip cloud | `popular_searches` |
| 16 | Footer | help bar · 9 link columns (Electronics, Fashion, Home and Kitchen, Beauty, Baby & Toys, Top Brands, Discover Now, Popular, GCC) · Connect with us · Shop on the go (app badges) · © + payment marks + legal links | `global.footer` |
| — | Cookie bar | Essential/Optional customise | `global.consent` |

Our additions (spec §5/§9): `verticals` (Classifieds / Travel / Real estate), `creators_carousel`, `live_media_floating` (global, scheduled), sponsored slots inside `product_carousel` (labelled "Sponsored").
