# Page builder · block reference thumbnails

One PNG per block type in `BlockRegistry` (57), served from `backend/public/images/page-blocks/{block_type}.png`
(URL `/images/page-blocks/{block_type}.png`). The page builder shows it at the top of the settings panel and on each card in the *Add section* gallery.

Figma file `d2MhBJmRddMrMSHfjJY8Y8`. Sources, in order of preference:
1. **Page `37:2` Page builder · Components** — each `Spec / …` frame holds a `Block / …` component; the component (not the spec frame with its annotation) is exported.
2. **Page `15:4` Web · Home & discovery** — Home v2 reference frame `30:1208` for blocks with no spec frame (`hero_bento`, `category_tiles`, `deal_grid`, `tile_grid`, `seo_text`, `popular_searches`).
3. **Page `241:2` Page builder · block thumbnails** — new frames composed for the six category-page blocks (group K), drawn from `docs/design/reference/category-page-target.webp` with the file's tokens and product photos.

Export: PNG, ~1200 px wide (scale 1200 / node width; nodes ≤ 1115 px wide are exported at 1×, the two 448 px panel_row children at 1×). Transparent corners where the block has rounded corners — show on a `color/bg/subtle` card with `object-fit: contain`.

To refresh a thumbnail: `download_assets` (or `get_screenshot`, `contentsOnly`) on the node id below and overwrite the file.

| # | Block type | Group · kind | Figma node | Source | File | Size (px) |
|---|---|---|---|---|---|---|
| 1 | `hero_carousel` | hero · editor | `37:12` | Spec / hero_carousel — large (page 37:2) | `backend/public/images/page-blocks/hero_carousel.png` | 1200×431 |
| 2 | `hero_bento` | hero · editor | `30:1276` | Home v2 › Block / hero_bento (page 15:4) | `backend/public/images/page-blocks/hero_bento.png` | 1200×367 |
| 3 | `banner_slider` | hero · editor | `37:48` | Spec / banner_slider — full (page 37:2) | `backend/public/images/page-blocks/banner_slider.png` | 1200×273 |
| 4 | `banner_scroller` | hero · editor | `37:70` | Spec / banner_scroller — 3 visible (page 37:2) | `backend/public/images/page-blocks/banner_scroller.png` | 1200×302 |
| 5 | `promo_banner` | banners · editor | `37:105` | Spec / promo_banner — 1 big + 2×2 (page 37:2) | `backend/public/images/page-blocks/promo_banner.png` | 1200×401 |
| 6 | `banner_grid` | banners · editor | `37:182` | Spec / banner_grid — showcase_1x7 (page 37:2) | `backend/public/images/page-blocks/banner_grid.png` | 1200×246 |
| 7 | `banner_strip` | banners · editor | `37:336` | Spec / banner_strip — full width + hotspots (page 37:2) | `backend/public/images/page-blocks/banner_strip.png` | 1200×216 |
| 8 | `banner_full` | banners · editor | `37:350` | Spec / banner_full — sponsored (page 37:2) | `backend/public/images/page-blocks/banner_full.png` | 1200×183 |
| 9 | `countdown_strip` | banners · editor | `37:366` | Spec / countdown_strip — thin bar (page 37:2) | `backend/public/images/page-blocks/countdown_strip.png` | 1200×61 |
| 10 | `tile_scroller` | navigation · editor | `39:149` | Spec / tile_scroller — circles (page 37:2) | `backend/public/images/page-blocks/tile_scroller.png` | 1200×160 |
| 11 | `category_tiles` | navigation · editor | `30:1319` | Home v2 › Block / category_tiles — Shop by category (page 15:4) | `backend/public/images/page-blocks/category_tiles.png` | 1200×210 |
| 12 | `category_showcase` | navigation · editor | `39:332` | Spec / category_showcase — header + 7 (page 37:2) | `backend/public/images/page-blocks/category_showcase.png` | 1200×246 |
| 13 | `brand_carousel` | navigation · editor | `39:401` | Spec / brand_carousel — brand cards + offer (page 37:2) | `backend/public/images/page-blocks/brand_carousel.png` | 1200×254 |
| 14 | `segment_tabs` | navigation · editor | `37:280` | Spec / banner_grid — segment_tabs (page 37:2) | `backend/public/images/page-blocks/segment_tabs.png` | 1200×61 |
| 15 | `quick_links` | navigation · editor | `37:255` | Spec / banner_grid — quick_links (page 37:2) | `backend/public/images/page-blocks/quick_links.png` | 1200×69 |
| 16 | `product_carousel` | products · editor | `39:436` | Spec / product_carousel — 1 row · sponsored slot (page 37:2) | `backend/public/images/page-blocks/product_carousel.png` | 1200×371 |
| 17 | `product_grid` | products · editor | `39:1344` | Spec / product_grid — 5 cols × 2 (page 37:2) | `backend/public/images/page-blocks/product_grid.png` | 1200×672 |
| 18 | `item_carousel` | products · system | `39:1658` | Spec / item_carousel — recently viewed (page 37:2) | `backend/public/images/page-blocks/item_carousel.png` | 1200×173 |
| 19 | `mega_deals` | products · editor | `39:1156` | Spec / mega_deals — coloured band + countdown (page 37:2) | `backend/public/images/page-blocks/mega_deals.png` | 1200×330 |
| 20 | `hero_offer_list` | products · editor | `39:1238` | Spec / hero_offer_list (page 37:2) | `backend/public/images/page-blocks/hero_offer_list.png` | 1200×366 |
| 21 | `frenzy_deals` | products · editor | `39:1288` | Spec / frenzy_deals — claimed bar + timer (page 37:2) | `backend/public/images/page-blocks/frenzy_deals.png` | 1200×328 |
| 22 | `deal_grid` | products · editor | `30:1446` | Home v2 › panel_row › Child / deal_grid — Mega deals (page 15:4) | `backend/public/images/page-blocks/deal_grid.png` | 448×476 |
| 23 | `offer_cards` | products · editor | `39:1679` | Spec / offer_cards — coupon cards (page 37:2) | `backend/public/images/page-blocks/offer_cards.png` | 1115×262 |
| 24 | `tile_grid` | products · editor | `30:1423` | Home v2 › panel_row › Child / tile_grid — More reasons to shop (page 15:4) | `backend/public/images/page-blocks/tile_grid.png` | 448×408 |
| 25 | `panel_row` | containers · container | `39:1718` | Spec / panel_row — 3 columns (page 37:2) | `backend/public/images/page-blocks/panel_row.png` | 1115×220 |
| 26 | `section_band` | containers · container | `39:1733` | Spec / section_band — coloured band with children (page 37:2) | `backend/public/images/page-blocks/section_band.png` | 1115×244 |
| 27 | `sponsored_brand_banner` | ads · ad | `40:1190` | Spec / sponsored_brand_banner (page 37:2) | `backend/public/images/page-blocks/sponsored_brand_banner.png` | 1115×210 |
| 28 | `sponsored_product` | ads · ad | `40:1216` | Spec / sponsored_product (page 37:2) | `backend/public/images/page-blocks/sponsored_product.png` | 1115×304 |
| 29 | `sponsored_brand_video` | ads · ad | `40:1375` | Spec / sponsored_brand_video (page 37:2) | `backend/public/images/page-blocks/sponsored_brand_video.png` | 1115×266 |
| 30 | `sponsored_video` | ads · ad | `40:1406` | Spec / sponsored_video (page 37:2) | `backend/public/images/page-blocks/sponsored_video.png` | 1115×286 |
| 31 | `ad_slot` | ads · ad | `40:1507` | Spec / ad_slot — booked slot (page 37:2) | `backend/public/images/page-blocks/ad_slot.png` | 1115×180 |
| 32 | `product_listing` | listing · listing | `40:1656` | Spec / product_listing — results bar + chips + grid (page 37:2) | `backend/public/images/page-blocks/product_listing.png` | 1115×764 |
| 33 | `verticals` | content · editor | `40:2003` | Spec / verticals — 4 cards (page 37:2) | `backend/public/images/page-blocks/verticals.png` | 1115×72 |
| 34 | `creators_carousel` | content · system | `40:2044` | Spec / creators_carousel (page 37:2) | `backend/public/images/page-blocks/creators_carousel.png` | 1115×136 |
| 35 | `classified_carousel` | content · editor | `40:2089` | Spec / classified_carousel (page 37:2) | `backend/public/images/page-blocks/classified_carousel.png` | 1115×272 |
| 36 | `travel_packages_carousel` | content · editor | `40:2126` | Spec / travel_packages_carousel (page 37:2) | `backend/public/images/page-blocks/travel_packages_carousel.png` | 1115×272 |
| 37 | `properties_carousel` | content · editor | `40:2163` | Spec / properties_carousel (page 37:2) | `backend/public/images/page-blocks/properties_carousel.png` | 1115×272 |
| 38 | `video_block` | content · editor | `40:2200` | Spec / video_block (page 37:2) | `backend/public/images/page-blocks/video_block.png` | 1115×300 |
| 39 | `live_streams_strip` | content · system | `40:2210` | Spec / live_streams_strip (page 37:2) | `backend/public/images/page-blocks/live_streams_strip.png` | 1115×196 |
| 40 | `html` | content · editor | `40:2306` | Spec / html · seo_text · popular_searches — content trio (page 37:2) | `backend/public/images/page-blocks/html.png` | 1115×90 |
| 41 | `seo_text` | content · editor | `31:2354` | Home v2 › Block / seo_text (page 15:4) | `backend/public/images/page-blocks/seo_text.png` | 1200×142 |
| 42 | `popular_searches` | content · editor | `31:2359` | Home v2 › Block / popular_searches (page 15:4) | `backend/public/images/page-blocks/popular_searches.png` | 1200×114 |
| 43 | `app_download` | content · editor | `40:2239` | Spec / app_download (page 37:2) | `backend/public/images/page-blocks/app_download.png` | 1115×130 |
| 44 | `newsletter_signup` | content · editor | `40:2259` | Spec / newsletter_signup (page 37:2) | `backend/public/images/page-blocks/newsletter_signup.png` | 1115×76 |
| 45 | `vendor_spotlight` | content · editor | `40:2273` | Spec / vendor_spotlight (page 37:2) | `backend/public/images/page-blocks/vendor_spotlight.png` | 1115×66 |
| 46 | `subcategory_tiles` | subcategories · editor | `46:1721` | Spec / subcategory_tiles (page 37:2) | `backend/public/images/page-blocks/subcategory_tiles.png` | 1115×434 |
| 47 | `subcategory_hub` | subcategories · editor | `46:1792` | Spec / subcategory_hub (page 37:2) | `backend/public/images/page-blocks/subcategory_hub.png` | 1115×314 |
| 48 | `subcategory_chips` | subcategories · listing | `46:1905` | Spec / subcategory_chips (page 37:2) | `backend/public/images/page-blocks/subcategory_chips.png` | 1115×56 |
| 49 | `category_header` | headers · header | `46:1943` | Spec / category_header (page 37:2) | `backend/public/images/page-blocks/category_header.png` | 1115×168 |
| 50 | `brand_header` | headers · header | `46:1957` | Spec / brand_header (page 37:2) | `backend/public/images/page-blocks/brand_header.png` | 1115×332 |
| 51 | `campaign_hero` | headers · header | `46:2008` | Spec / campaign_hero (page 37:2) | `backend/public/images/page-blocks/campaign_hero.png` | 1115×255 |
| 52 | `category_hero` | hero · editor | `241:3` | Composed thumbnail (page 241:2) | `backend/public/images/page-blocks/category_hero.png` | 1115×278 |
| 53 | `category_promo_tiles` | banners · editor | `241:19` | Composed thumbnail (page 241:2) | `backend/public/images/page-blocks/category_promo_tiles.png` | 1115×320 |
| 54 | `category_brand_tiles` | navigation · editor | `241:42` | Composed thumbnail (page 241:2) | `backend/public/images/page-blocks/category_brand_tiles.png` | 1115×230 |
| 55 | `category_gift_band` | products · editor | `241:76` | Composed thumbnail (page 241:2) | `backend/public/images/page-blocks/category_gift_band.png` | 1115×300 |
| 56 | `category_deal_columns` | products · editor | `241:115` | Composed thumbnail (page 241:2) | `backend/public/images/page-blocks/category_deal_columns.png` | 1115×332 |
| 57 | `sponsored_brand_showcase` | ads · ad | `241:182` | Composed thumbnail (page 241:2) | `backend/public/images/page-blocks/sponsored_brand_showcase.png` | 1115×262 |

## Notes
- `segment_tabs` and `quick_links` use the `banner_grid` spec variants of the same name (`37:280`, `37:255`); `banner_grid` itself uses the `showcase_1x7` variant (`37:182`).
- `html`, `seo_text`, `popular_searches`: the spec page has one shared "content trio" component (`40:2306`), used for `html`; `seo_text` and `popular_searches` use their own Home v2 blocks (`31:2354`, `31:2359`).
- `tile_scroller` uses the circles variant (`39:149`); the squares / 2-row variant is the component inside spec frame `39:187` if a second image is ever needed.
- `category_brand_tiles` (K) is composed (`241:42`) rather than reusing `banner_grid — brand_tiles`, because it shows product images with offer captions as in the category target.
- Group K frames on page `241:2`: `241:3` category_hero, `241:19` category_promo_tiles, `241:42` category_brand_tiles, `241:76` category_gift_band, `241:115` category_deal_columns, `241:182` sponsored_brand_showcase.
