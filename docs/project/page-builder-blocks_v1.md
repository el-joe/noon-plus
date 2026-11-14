# Page builder — block registry (single source of truth)
Used by: Figma (layer names `Block / <type> — <title>`), Admin → Content → Page builder (Blade + SortableJS), Laravel (`App\Domains\Content\PageBuilder`), Next.js (`features/home/blocks/<Type>.tsx`).

## Page model
`pages` (country DB): `id, slug ('home','campaign/...'), type (home|landing|category|brand), locale (en|ar), status (draft|scheduled|published), published_version_id`
`page_versions`: immutable JSON `{blocks:[…]}` + author + created_at → publish = pointer swap (instant rollback).
`page_blocks` (draft working copy): `page_id, position, type, settings JSON, data_source JSON, visibility JSON`.
Every homepage exists **per country × locale** (`/uae-en`, `/uae-ar`, `/omn-en` …). Copy-from-locale and copy-from-country actions exist.

## Common block settings (all types)
| Field | Notes |
|---|---|
| `title` / `subtitle` | per locale (EN/AR) |
| `view_all` | link (category, collection, campaign, search, URL) |
| `visibility.devices` | desktop · mobile web · app |
| `visibility.schedule` | starts_at / ends_at (country timezone) |
| `visibility.segments` | all · new customers · logged-in · noon-one-like members · custom segment |
| `background` | none · token colour · image |
| `ab_variant` | optional A/B key |
| `tracking_key` | analytics (impressions/clicks) |

## Block types
| Type | Purpose | Specific settings | Data source options |
|---|---|---|---|
| `hero_bento` | 3 tall + 2 stacked banners | slides[] {image_desktop, image_mobile, link, alt, schedule} for each slot, autoplay seconds | manual |
| `hero_slider` | classic full-width slider (alt) | slides[] | manual |
| `category_tiles` | rounded-square image shortcuts | items[] {label, image, link} max 20, arrows | manual · top categories |
| `panel_row` | 2–3 columns container | columns[] each = child block (`tile_grid`, `deal_grid`, `banner_stack`, `product_mini_grid`) | — |
| `tile_grid` | 2×2 image tiles with title/subtitle | items[4] | manual |
| `deal_grid` | 2×2 mini deals + countdown + All deals | flash_sale_id, show_countdown | flash sale · deals collection |
| `banner_stack` | 2 stacked banners | banners[2] | manual · ad slot |
| `product_carousel` | horizontal product list | card_density (compact/regular), max_items, sponsored_slots[] (positions), show_view_all | personalised · bestsellers · collection · category · campaign · coupon · flash sale · recently viewed · manual SKUs |
| `offer_cards` | coupon/offer carousel | items[] {image, title, code, link} | manual · active coupons |
| `banner_full` | wide banner | image desktop/mobile, link, `sponsored` flag (shows label + bills ad campaign) | manual · ad slot |
| `category_showcase` | title + 7 image tiles + view all | tiles[7] | manual · category children |
| `verticals` | Classifieds / Travel / Real estate cards | cards[] | manual |
| `creators_carousel` | influencer stores | max_items | top creators (country) |
| `brand_carousel` | brand logos | items[] | top brands |
| `seo_text` | rich SEO copy | html (sanitised), collapsed height | manual |
| `popular_searches` | keyword chips | max_items | search analytics · manual |
| `countdown_strip` | thin campaign bar | text, ends_at | campaign |

Global (not blocks, edited in Settings): header, category nav + membership pill, footer, consent bar, `live_media_floating` (scheduled media, spec §9.1).

## Rules
- Sponsored items/banners always render the **Sponsored** label (spec §7) and are filled by `SponsoredRanker`, never hard-coded in the block.
- Blocks resolve data server-side (Laravel `BlockResolver` per type, cached per country+locale+segment, invalidated on publish/catalog change). Next.js receives `{type, settings, data}`; unknown types are skipped.
- Validation per type via JSON schema; publish blocked if a required image/locale is missing.
