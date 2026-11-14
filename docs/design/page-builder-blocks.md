# Page builder — block registry v2 (single source of truth)
Based on the live Noon UAE audit (`docs/reference-research/noon-page-builder-audit.md`: 17 Noon widget types across 7 page types) plus the old Nawy page builder (SRS §15) and our new modules. v1 is archived in `docs/project/page-builder-blocks_v1.md`.
Used by Figma (page "Page builder · Components", layer names `Block / <type> — <variant>`), Admin → Content → Page builder, Laravel (`Content\PageBuilder\Blocks\*` + `BlockResolver`), Next.js (`features/page-builder/blocks/<Type>.tsx`) and Flutter (`blocks/<type>.dart`).

## 1. Page templates (what an admin can build)
Every page exists **per country × locale** and has a status `draft → scheduled → published → archived`, with append-only revisions and copy to other countries or locales.

| Template | URL pattern | Zones |
|---|---|---|
| `home` | `/{cc}-{lang}` | `main` (blocks) |
| `store_landing` | `/{cc}-{lang}/{store}` (e.g. /electronics, /supermarket) | `main` |
| `category` | `/{cc}-{lang}/{category-path}` | `header` (category_header + sub-category nav when the category has children) + `top` (blocks above the listing, 1115 wide beside filters) + **`listing`** (subcategory_chips + product_listing with sponsored interleave) + `bottom` (seo, popular searches) |
| `campaign` | `/{cc}-{lang}/{campaign-slug}` | `top` (strips, grids…) + `listing` (campaign products) |
| `brand_store` | `/{cc}-{lang}/~{brand}` | `header` (brand hero) + `top` + `listing` |
| `seller_store` / `marketer_store` | `/{cc}-{lang}/seller/{id}`, `/marketer/{slug}` | `header` (profile) + `top` + `listing` |
| `search` (system) | `/{cc}-{lang}/search?q=` | `listing` only (sponsored by keyword) |
| `vertical_landing` | `/classified`, `/classified/{section}`, `/travel`, `/real-estate` | `main` + vertical listing |
| `app_context` (mobile) | App top strip contexts (Main, Super Mall, Food…) | `main` (mobile-tuned blocks) |

## 2. Block library
Legend: **E** = editor-placed · **A** = ad/sponsored (filled by `SponsoredRanker` from paid ad campaigns, slots and bookings; always labelled "Ad"/"Sponsored") · **S** = system/personalised.

### A. Hero & sliders
| # | Type | Noon widget | Layout variants | Key settings |
|---|---|---|---|---|
| 1 | `hero_carousel` E | noonDynamicCarousel | large (909×452 desktop / 750×600 mobile), full | slides[] {image_d, image_m, link, alt, schedule, segment}, autoplay s, dots/arrows, pause |
| 2 | `hero_bento` E | composite (carousel + slider + promo modules) | 3-tall + 2-stacked; carousel + side stack | slots[] each = slide list |
| 3 | `banner_slider` E | promoBannerSlider | thin strip (≈110h), half, full (≈240–290h) | slides[], height preset, width (full/half) |
| 4 | `banner_scroller` E | promoBannerModuleScroller | 2–4 visible, arrows | items[] |

### B. Banners & grids
| # | Type | Noon widget | Variants | Settings |
|---|---|---|---|---|
| 5 | `promo_banner` E | promoBannerModule | single, 2-up, **1 big + 2×2**, stacked 2, side panel | items[] {image, link}, optional title |
| 6 | `banner_grid` E | bannerModule | `n_up` (2–6), `showcase_1x7` (header + 7 tiles), `brand_tiles` (header + 10), `quick_links` (8–10 small tiles), `segment_tabs` (Women/Men/Kids), `wide` (1 full-width), `two_by_two` | header {title, image, view_all}, tiles[] {image, label, caption, link} |
| 7 | `banner_strip` E | bannerModuleStrip | full width, any height; stackable | image_d, image_m, link, height, hotspots[] (multiple links on one image) |
| 8 | `banner_full` E/A | bannerModule (wide) | organic or **sponsored** (ad slot) | image, link, `sponsored`, ad_slot_key |
| 9 | `countdown_strip` E | thin campaign bar | — | text, ends_at, link |

### C. Category / brand navigation
| # | Type | Noon widget | Variants | Settings |
|---|---|---|---|---|
| 10 | `tile_scroller` E | bannerModuleScroller | circle / rounded square / card; **1 or 2 rows**; caption or **offer caption** ("From AED 36", "Min 20% off"); "NEW" sticker | items[] {image, label, caption, sticker, link}; source manual · category children · top brands |
| 11 | `category_tiles` E | bannerModuleScroller (home) | 12 square tiles + arrows | as tile_scroller |
| 12 | `category_showcase` E | bannerModule 1×7 | header + 7 tiles + VIEW ALL | tiles[7]; source manual · category children |
| 13 | `brand_carousel` E | bannerModule brand tiles | logos / brand cards with offer | items[]; source top brands |
| 14 | `segment_tabs` E | bannerModule text tiles | 2–5 tabs (Women/Men/Kids, Main/Supermall) | tabs[] {label, link or target page} |
| 15 | `quick_links` E | bannerModule small tiles | 6–10 icon/text chips | items[] |

### D. Products & deals
| # | Type | Noon widget | Variants | Settings |
|---|---|---|---|---|
| 16 | `product_carousel` E/S | productCarousel | 1 row / **2 rows**; regular / compact density; with optional left banner | source: personalised · bestsellers · new arrivals · collection · category · brand · campaign · coupon · flash sale · price drop · manual SKUs; max_items; **sponsored_slots[]** |
| 17 | `product_grid` E | (campaign grids) | 4–6 cols × N rows | same sources |
| 18 | `item_carousel` S | itemCarousel | compact | recently viewed · continue shopping · buy again |
| 19 | `mega_deals` E | hpMegaDealDesktop | coloured band, countdown, category-tagged deal cards (4–6) | flash_sale_id or deals collection, band colour, show countdown |
| 20 | `hero_offer_list` E | heroOfferListDesktop | coloured band + hero visual + tagged offer cards scroller | hero image, offers source, tag per card |
| 21 | `frenzy_deals` E | frenzyDealModule | limited-quantity deals with claimed % bar and timer | flash sale / frenzy event id |
| 22 | `deal_grid` E | home "Mega deals" 2×2 | 2×2 mini + countdown + All deals | flash_sale_id |
| 23 | `offer_cards` E | bannerModuleScroller (offers) | coupon cards (image, title, code) | items[] or active coupons |
| 24 | `tile_grid` E | bannerModule 2×2 | "More reasons to shop" | items[4] {image, title, subtitle} |

### E. Layout containers
| # | Type | Purpose | Settings |
|---|---|---|---|
| 25 | `panel_row` | 2–3 columns, each a child block (tile_grid, deal_grid, promo_banner…) | columns[] with width ratio |
| 26 | `section_band` | Coloured or background-image band wrapping child blocks with a title | bg colour/image, title, children[] |

### F. Sponsored / ads (placed by rules, never hard-coded products)
| # | Type | Noon widget | Where | Billing |
|---|---|---|---|---|
| 27 | `sponsored_brand_banner` A | PromoProductsListBanner | top of listing/search; between grid rows | ad campaign (CPC/CPM) or booking |
| 28 | `sponsored_product` A | PromoMultiProduct | grid positions and carousel `sponsored_slots` | CPC |
| 29 | `sponsored_brand_video` A | PromoBrandVideo | listing and search | CPM (creative uploaded by admin) |
| 30 | `sponsored_video` A | PromoVideo | listing and search | CPM (creative uploaded by admin) |
| 31 | `ad_slot` A | Nawi ad slots (old system) | any page position bound to a slot key | fixed day/week/month, CPM, CPC |

### G. Listing zone
| # | Type | Purpose | Settings |
|---|---|---|---|
| 32 | `product_listing` | Filters sidebar + results header + sort + quick-filter chips + grid (4–5 cols) + infinite scroll or pagination | scope (category, brand, campaign, search, seller), default sort, visible filter groups (fulfilled by, arrives by, gender, category tree, brand, price, deals, price drop, rating, size, new arrivals, condition, grade, colour, seller), **interleave rules** (sponsored brand banner at top; sponsored block every N rows; sponsored product positions) |

### H. Content, marketplace & engagement
| # | Type | Notes |
|---|---|---|
| 33 | `verticals` | Classifieds / Travel / Real estate / Creators cards |
| 34 | `creators_carousel` | Top creators (country) |
| 35 | `classified_carousel` | Latest or featured classified ads (category, section) |
| 36 | `travel_packages_carousel` | Packages by destination or category |
| 37 | `properties_carousel` | Featured properties (broker listings, sponsored allowed) |
| 38 | `video_block` | Organic video (brand story, live replay), picked from the admin media library |
| 39 | `live_streams_strip` | Live now / scheduled streams, created only in Admin → Content → Live & videos |
| 40 | `html` | Sanitised rich HTML (legacy migration target) |
| 41 | `seo_text` | Collapsible SEO copy |
| 42 | `popular_searches` | Keyword chip cloud (search analytics or manual) |
| 43 | `app_download` | App badges + QR |
| 44 | `newsletter_signup` | Email/WhatsApp opt-in |
| 45 | `vendor_spotlight` | Old system block: featured sellers |

Global (Settings, not blocks): header, category nav + membership pill, footer, consent bar, `live_media_floating` (scheduled media, spec §9.1), app contexts.

### I. Sub-category navigation (Nawy addition — Noon shows only a text tree + chips)
| # | Type | Purpose | Settings |
|---|---|---|---|
| 46 | `subcategory_tiles` | Image tiles of the category's children with **product counts**; "All <category>" first; 2 rows then "Show all (n)"; selected child gets an amber ring | source: category children (auto, sorted by admin order or by item count); image per category; max rows |
| 47 | `subcategory_hub` | Grouped cards for deep trees: each child with image + top 4–6 grandchildren + View all | depth 2; max links per card |
| 48 | `subcategory_chips` | Sticky chip bar above the listing: All + children with counts; active chip filled; overflow arrow | sticky on/off |

**Rule:** a `category` page whose category **has children** automatically gets `category_header` + `subcategory_tiles` in the header zone and `subcategory_chips` above the listing. Admins can switch to `subcategory_hub` or hide them. Leaf categories show only the left category tree.

### J. Page headers (template header zone)
| # | Type | Purpose |
|---|---|---|
| 49 | `category_header` | Dark band: breadcrumb, name, item count, description, optional banner image |
| 50 | `brand_header` | Brand/seller/marketer store header: cover, logo, Official badge, rating, followers, Follow, store tabs (Home · All products · Deals · New arrivals · Reviews) |
| 51 | `campaign_hero` | Campaign hero: title art, subline, live countdown (server `ends_at`, country time), section jump chips |

All schedule fields in every block use the country-time date-time input with the Oman-time label (`docs/project/timezones.md`).

## 3. Common settings (every block)
- Title and subtitle (EN/AR)
- view_all link
- visibility: devices (desktop, mobile web, app), schedule (starts and ends, country timezone), segments (all, new, logged in, members, custom), A/B variant
- background (none, token, image)
- spacing (top, bottom)
- tracking_key (impressions and clicks, shown in block analytics)

## 4. Rules
1. **Ads are always labelled** ("Ad" on tiles, "Sponsored" on banners) and come from `SponsoredRanker`. Editors only reserve positions (`sponsored_slots`, interleave rules, ad_slot keys).
2. Data resolves server-side per block (`BlockResolver`), cached per country + locale + segment, and invalidated on publish or on catalog/price change.
3. Each block type has a JSON schema. Publish is blocked when a required image or locale is missing, or a source is empty, unless a fallback is set.
4. Next.js and Flutter skip unknown types (forward compatibility). Old-system blocks map through `block_type_map` (migration MG8).
5. Images use the asset pipeline: desktop and mobile crops, WebP/AVIF, `img/*` slot names as in Figma.


## Video and live ownership
Every video and live source used by blocks (video_block, live_streams_strip, shoppable_video_feed, sponsored_video, sponsored_brand_video, home floating media) comes from the **admin media library** (Admin → Content → Live & videos). Vendors and marketers cannot upload. See MASTER_PLAN ADR 11.


## Responsive rules (mobile web and app)
- Carousels and scrollers become horizontal swipe rows with 2.3 visible items.
- Product grids: 2 columns under 768px, 3 at tablet, 4–6 at desktop.
- Banners with a side panel stack vertically; hotspots become tappable chips.
- Filters move into a bottom sheet behind a `Filters (n)` pill.
- Sponsored tiles keep the `Ad` label at every size.
- RTL: every horizontal layout is mirrored; text uses `ar/*` styles (Cairo).
