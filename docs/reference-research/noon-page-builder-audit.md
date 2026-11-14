# Noon UAE — page types & widget catalogue (live audit 2026-11-14)
Method: built-in browser at 1440px. Each page is fully scrolled, then we read Noon's own CMS module tags (`data-qa="widget_<Type>"`) in DOM order with their rendered size, and add screenshots. The full widget order is listed per page so templates can be rebuilt 1:1 (structure only — no Noon assets).

## 1. Page types found
| Page type | Example URL | Structure |
|---|---|---|
| Home | `/uae-en/` | Widgets only (≈193 widget instances) |
| Store landing (category root) | `/uae-en/electronics/` | Widgets only, **no listing**, full width 1377 |
| **Category page** | `/uae-en/beauty/`, `/uae-en/fashion-women/`, `/uae-en/noon-supermarket/` | **Filter sidebar (left) + widget zone (1115 wide) on top + product listing ("Explore more") below**, with sponsored widgets inserted between grid rows |
| Category listing (deep) | `/uae-en/electronics-and-mobiles/` | Filter sidebar + sponsored brand banner + results header + quick-filter chips + grid; sponsored widgets every ~4 rows |
| Campaign page | `/uae-en/yellow-friday-sale-offers/` | Stacked full-width **banner strips** + banner grids + scroller + quick links → listing with filters |
| Brand store | `/uae-en/~adidas/` | Listing with filters (brand-filtered), no widgets |
| Search | `/uae-en/search/?q=…` | Listing + sponsored widgets (brand banner, sponsored products, **sponsored videos**) |

## 2. Widget order per page (type · rendered size · ×repeat)
- **Home:** noonDynamicCarousel 909×452 · promoBannerSlider 454×135 · promoBannerModule 454×303 ×2 · bannerModuleScroller 1377×137 (category tiles) · bannerModule 469×441 + 220×216 ×4 (2×2 "More reasons") · hpMegaDealDesktop 454×515 · promoBannerModule 454×433 / 424×217 / 424×202 ("In focus") · productCarousel ×6 · bannerModuleScroller 1377×265 (offer cards) · promoBannerSlider 1377×241 (wide) · itemCarousel · **bannerModule 1392×~300 + 195×300 ×7, repeated ≈20×** (category showcases) · bannerModule 1377×209 (wide partner banner)
- **Store landing /electronics/:** bannerModule 1392×73 + 170×73 ×8 (quick-link bar) · promoBannerSlider 1377×287 · promoBannerModule 1377×188 · bannerModuleScroller 1377×136 · productCarousel · **heroOfferListDesktop 1377×587** · bannerModuleScroller 1377×265 · productCarousel ×7 · promoBannerModule 1377×217 · productCarousel · bannerModule 1392×300 + 195×300 ×7 (×6 showcases) · **bannerModuleStrip 1377×239 ×2** · bannerModuleScroller 1377×237 · bannerModule 1392×193 + 136×193 ×10 (brand tiles)
- **Category /beauty/:** bannerModule 1122×61 + 218×61 ×5 (segment/quick links) · promoBannerSlider 1115×233 · bannerModuleScroller 1115×131 · productCarousel ×2 · **frenzyDealModule** · productCarousel ×4 · bannerModuleScroller 1115×211 · productCarousel · promoBannerModule 1125×372 + 548×175 ×2 + 548×165 ×2 (1 big + 2×2) · productCarousel · bannerModuleScroller 1115×448 (2-row tiles) · productCarousel · *listing* · PromoBrandVideo 276×172 · PromoVideo 347×195 · PromoMultiProduct ×2 · PromoProductsListBanner ×2 · PromoMultiProduct ×2
- **Category /fashion-women/:** bannerModule 1122×59 + 364×59 ×3 (**segment tabs** Women/Men/Kids) · **promoBannerModuleScroller 1115×301** · bannerModuleScroller 1115×311 (2-row tiles with "NEW Shop now") · productCarousel ("Brand of the day", 2 rows) · productCarousel ×3 · bannerModuleScroller 1115×652 · bannerModule 1125×191 + 365×191 ×3 · heroOfferListDesktop 1115×301 (coloured band, category-tagged offers) · productCarousel ("New Arrivals") · bannerModuleScroller 1115×374 (brand tiles with "Min 20% off / From AED 36") · **hpMegaDealDesktop 1115×320** (green band, countdown, category-tagged deal cards) · **"Explore more" listing** + sponsored widgets
- **Supermarket:** promoBannerSlider · bannerModuleScroller · productCarousel ×2 · heroOfferListDesktop 1115×587 · promoBannerSlider · productCarousel · bannerModuleScroller ×2 · productCarousel ×n · bannerModule 1125×242 + 156×242 ×7 · bannerModule 1125×284 + 183×284 ×6 (×3) · listing + sponsored
- **Campaign (Yellow Friday):** promoBannerSlider 1115×111 (thin) · **bannerModuleStrip ×10** (heights 234–836) · bannerModule 1115×255 · bannerModule 1115×637 · bannerModule 1085×207 ×3 · bannerModuleScroller 1115×285 · bannerModule 1122×69 + 121×69 ×9 (quick links) · bannerModule 1115×43 (thin strip) · listing + sponsored
- **Category listing / search:** PromoProductsListBanner (brand creative + 2 products, "Ad") · PromoMultiProduct (sponsored product tile, "Ad") · PromoBrandVideo · PromoVideo — repeated every few grid rows

## 3. Distinct widget types (17) → our blocks
| Noon widget | What it is | Our block |
|---|---|---|
| noonDynamicCarousel | Main hero carousel (large, autoplay, dots, pause) | `hero_carousel` |
| promoBannerSlider | Banner slider of any width (thin strip / half / full) | `banner_slider` |
| promoBannerModuleScroller | Multi-visible banner scroller with arrows | `banner_scroller` |
| promoBannerModule | Promo banner group (1, 2-up, 1 big + 2×2, stacked) | `promo_banner` |
| bannerModule | Generic image grid (1–10 columns; header image + N tiles); used for showcases, quick links, segment tabs, brand tiles, wide banners | `banner_grid` (layouts: `showcase_1x7`, `quick_links`, `segment_tabs`, `brand_tiles`, `n_up`, `wide`) |
| bannerModuleScroller | Horizontal tile scroller (1 or 2 rows; circles/squares; caption or offer text) | `tile_scroller` |
| bannerModuleStrip | Full-width campaign strip (stacked, any height) | `banner_strip` |
| hpMegaDealDesktop | Coloured band, title + countdown, category-tagged deal cards | `mega_deals` |
| heroOfferListDesktop | Coloured band with hero visual + category-tagged offer product cards | `hero_offer_list` |
| frenzyDealModule | Time-boxed limited-quantity deals (claimed % bar) | `frenzy_deals` |
| productCarousel | Product carousel (1–2 rows, title, view all) | `product_carousel` |
| itemCarousel | Compact item carousel (recently viewed / continue shopping) | `item_carousel` |
| PromoProductsListBanner | Sponsored brand banner + products | `sponsored_brand_banner` (ad) |
| PromoMultiProduct | Sponsored product tile in grid/carousel | `sponsored_product` (ad) |
| PromoBrandVideo | Sponsored brand video + products | `sponsored_brand_video` (ad) |
| PromoVideo | Sponsored video tile | `sponsored_video` (ad) |
| (listing) filter-container / plp-grid / searchHeader | Product listing engine with filters | `product_listing` zone |

## 4. Listing (PLP) details observed
- **Filters (left, 264px):** Fulfilled by (supermall "Direct from brand", express "Fulfilled by noon", Global "Sourced globally"), Arrives by, Gender, Category tree (expand/collapse, Clear), Brand (search + see all), Price (min/max inputs), Deals, Price drop, Product rating, Size, New arrivals, Item condition, Grade, Colour (swatches), Seller.
- **Header:** "100K+ Results for "<category> in UAE"", Sort by (Recommended …), **quick-filter chip row** (Deals ▾, Size ▾, Brand ▾, Price ▾, popular values such as Size: XL, brand names, sub-categories) with a scroll arrow.
- **Grid:** 5 columns (with sidebar). Card shows: image carousel dots, wishlist, add (+), **Ad** label, badges (Best Seller, Mega Deal ribbon), rating + count, price + was + % OFF, "Selling out fast" / "Lowest price in a year", coupon pill ("Extra 10% off"), fulfilment chips (express, supermall "Get in 1 hr", Global).
- **Sponsored interleave:** brand banner at top, then sponsored blocks roughly every 4 rows; infinite scroll / load more.
