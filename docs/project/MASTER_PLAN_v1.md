# NAWY (ناوي) — Master Plan
> Multi-country commerce & services super-platform. Noon UAE is the primary UX reference; OpenSooq, Boutiqaat and Boksha are secondary references.
> Owner: BA / PM · Created 2026-11-14 · Living document. Update the status columns as work lands.

---

## 0. Inputs read & what changed vs. the old system

| Source | Takeaway |
|---|---|
| `old_project_overview.md` | The old "Nawy Marketplace" ran on one Laravel app with seven subdomain panels, Next.js storefront, and Flutter apps. It had **one shared DB filtered by `country_id`**, jQuery DataTables, and no formula layer. We keep the business modules and workflows (order lifecycle, money flow, marketer attribution, vendor onboarding, classified contracts, travel approval). We redesign the foundations. |
| `project_docs.md` | The new master spec. Adds: **per-country DB isolation**, brokers, cross-promotion policy engine, creator-owned products, ads (CPM/CPC), live media, 4 payment gateways (Thawani/Stripe/PayPal/PayTabs), double-entry ledger, full docs and test suites, Figma-first. **No Livewire. No Next.js for panels.** |
| `firecrawl.md` | A connector setup guide. Firecrawl is our **research tool** for the reference audits. |
| `logo.webp` | Brand **Nawy / ناوي**, primary amber `#F9BB25`, bilingual wordmark. Original branding; we copy no Noon assets. |

### Key differences old → new
1. Single DB + `country_id` → **central control-plane DB + one operational DB per country**
2. Ad-hoc money math → **centralized calculators + immutable double-entry ledger**
3. 7 panels → **10 panels**: adds broker, creator/influencer, classified-seller, and operations/supervisor panels. The partner panel becomes the vendor panel.
4. Payments: Tabby/Tamara → **gateway adapter layer** (Thawani, Stripe, PayPal, PayTabs; BNPL is a later adapter)
5. New modules: brokers, cross-promotion, creator-owned products, ads ranking, live media, vouchers/gift-card split.

---

## 1. Environment (Phase 0 — verified 2026-11-14)

| Item | Status |
|---|---|
| PHP 8.3.36, Composer 2.9.5 | ✅ |
| Laravel **13.17** skeleton in `backend/` (Pest 4, Boost, Pint). Fresh, only the users table exists | ✅ |
| Next.js **16.4.6** + React 19.3 + Tailwind 4.3 in `website/`. Fresh skeleton. ⚠ `AGENTS.md`: read `node_modules/next/dist/docs/` before coding | ✅ |
| Flutter at `~/flutter`. Fresh apps: `customer_app`, `delivery_app`, `vendor_app`, `marketer_app`, `supervisor_app`, `travel_agency_app` | ✅ |
| MySQL 8.0.46, Redis (PONG), Node 20.20, npm 10.8 | ✅ |
| Figma MCP: pro plan "Youssef Magdy's team" (`team::1303992898961658821`), full seat | ✅ |
| Canva MCP: connected, **no brand kit**. We create the Nawy kit from tokens | ✅ |
| Firecrawl: connected, reads noon.com and sell.noon.com | ✅ |
| Perplexity connector: only "import dashboard" tools, **no search**. Research falls back to Firecrawl | ⚠ |
| Noon Seller Lab (`login.noon.partners`): needs auth. **We will not bypass it.** Public docs only | ⚠ |

---

## 2. Team & RACI

| Role | Owns | Key deliverables |
|---|---|---|
| **BA / PM** (me) | Scope, phases, status, acceptance | This plan, PRD, role matrix, `FEATURE_MATRIX.md`, `PROJECT_STATUS.md` |
| **Senior UI/UX Designer** | Figma + Canva | Design system, website pages, panel layouts and components, app screens, asset inventory |
| **DB Architect / Optimizer** | Topology, schema, indexing, ledger | `database-topology.md`, ERDs, migrations review, query budgets, country provisioning |
| **Senior Backend (Laravel)** | Domains, APIs, panels (Blade) | Domain modules, calculators, `/api/v1`, OpenAPI, panels |
| **Senior Frontend (Next.js)** | Storefront | Typed API client, i18n/RTL, all customer pages from Figma |
| **Senior QA** | Test strategy, E2E | Pest feature/unit tests, Playwright E2E (web + panels), Flutter tests, `test-results.md` |
| **Problem Solver / QC** | Hard problems & gatekeeping | Concurrency (stock locks, booking capacity, idempotency), attribution fraud, reviews Definition-of-Done at each gate |

Gate rule: a module moves to **Tested** only after QA signs off and QC confirms the DoD (§13).

---

## 3. Reference research findings (Firecrawl, 2026-11-14)

**Noon UAE homepage** (`/uae-en/`) had about 1,178 links. Page families found:
- Categories (15 top nav): Electronics, Beauty & Fragrance, Home & Kitchen, Grocery, Men's / Women's / Kids' Fashion, Mom & Baby, Toys, Sports & Outdoors, Health & Nutrition, Stationery, Books, Automotive, Music & Media.
- URL patterns: category `/{cc-lang}/{slug}/`, **campaign/store** `/{cc-lang}/{campaign-slug}/` (e.g. `yellow-friday-sale-offers`), **brand store** `/{cc-lang}/~{brand}/`, **product** `/{cc-lang}/{slug}/{SKU}/p/`, plus `/cart/` and `/wishlist/`.
- Theme colour `#feee00` (Noon yellow). Ours is amber `#F9BB25` plus a dark navy for contrast, so we stay recognisably different.
- Sub-brands linked: noon Food, noon Minutes (quick commerce), Supermall, affiliates.noon.com, help.noon.com, sell.noon.com, plus GCC country switch.

**Product detail page anatomy** (in order):
1. Breadcrumb
2. Gallery with thumbnails, carousel arrows, wishlist heart
3. Brand link, title, rating with count
4. Price, and merchandising badges ("#1 in category", "Lowest price in a year", "Selling out fast", "Best Seller")
5. Delivery options with ETA: express "Get it tomorrow" and quick "Get in 1 hr 19 mins"
6. Coupon chips with **Copy** (code plus % off)
7. Bank cashback and BNPL "Split in up to 6 payments"
8. Variant selector (pill buttons)
9. Frequently-bought-together bundle
10. Seller card: "Sold by X", rating, % positive, "Item as shown %", "Partner since 5+ Y", **"More offers from other sellers"**
11. Trust icons (warranty, secure payments)
12. Quantity stepper and Add to cart
13. Tabs: Overview (description, highlights, spec table) and Ratings & Reviews (score, 5→1 distribution bars)
14. "Previously browsed" and "Bestsellers in this category" carousels

**Footer:** help and email support, category link columns, Top Brands, Discover (blog, affiliate program, sub-brands), Popular searches, GCC country links, legal (Warranty, Terms of Use, Terms of Sale, Privacy, Consumer Rights, Sell with us, Careers), app-store badges, social links.

**sell.noon.com/uae-en:** Hero → Why join → "Ready to sell? Here's what you'll need" (business email, WhatsApp phone, Emirates ID or passport; if VAT-registered: CR plus POA; under AED 375k revenue: non-VAT declaration) → Fulfillment choice (FBN/FBP) → "Know your costs" (commissions, other costs, in-house ad suite) → Success story → Testimonials → Help → Learn more.

Full audits go to `docs/reference-research/*.md` in Phase 1. Next targets: Arabic (`/uae-ar/`) RTL check, search/PLP filters (rendered client-side, so Firecrawl will need `waitFor`/actions), cart, login/OTP, help centre, OpenSooq listing and detail pages, Boutiqaat influencer stores.

---

## 4. Architecture decisions (ADR summary)

### ADR-001 Modular monolith
One Laravel 13 app with `app/Domains/*` (Catalog, Vendors, Orders, Payments, Wallet, …) and `app/Shared/*`. Each domain holds Models / Services / Actions / Calculators / DTOs / Enums / Events / Policies / Http (Requests, Resources).

### ADR-002 Country isolation (DB per country)
- **Central DB `nawy_central`** holds countries, locales, currencies, domains, `users` (global identity: phone/email/password/2FA), `role_memberships` (user × role × country), admins, onboarding applications, gateway configs (encrypted), global settings, audit of central actions, and FX rates.
- **Country DBs `nawy_{cc}`** (`nawy_uae`, `nawy_egy`, `nawy_ksa`) hold all operational data: catalog, listings, orders, wallets and ledger, classifieds, travel, ads, shipments, campaigns, notifications, and country audit log.
- **Resolution:** the `ResolveCountry` middleware sets `CountryContext` from (a) the API header `X-Country` plus a URL prefix (`/uae-en`), or (b) the panel subdomain `vendor.uae.nawy.test`. It then switches the `country` DB connection, cache prefix, queue name and storage disk prefix.
- **Cross-country identity:** one global user. A customer may shop in several countries, but each country DB keeps its own profile, addresses, wallet and orders, keyed by `central_user_id`.
- **Global admin:** a super admin picks a country in the panel, and context switches explicitly and is audited.
- **New country** = insert into `countries`, then run `php artisan country:provision {cc}` (creates the DB, runs `database/migrations/country`, seeds).
- **Migrations:** `database/migrations/central` and `database/migrations/country`. `php artisan country:migrate --all`.
- **Tests:** a dedicated isolation suite (a user in UAE gets 404/403 on EGY resources, no cross-DB joins).

### ADR-003 Money
`DECIMAL(19,4)` columns plus a `Money` value object (brick/math BigDecimal, HALF_UP to the currency minor unit). **There is no float anywhere.** All math lives in `app/Domains/*/Calculators`. Amounts are never summed across currencies.

### ADR-004 Ledger
Double-entry ledger: `ledger_accounts` (owner_type/owner_id/purpose/currency) plus `ledger_entries` (immutable rows grouped by `ledger_transactions` with an idempotency key, a unique constraint, and debits equal to credits). Wallet balances (available, pending, reserved, withdrawable) are **derived and cached**, never written directly. Affiliate earnings, own-product sales, and broker commissions each use separate account purposes.

### ADR-005 Auth
- Panels: session guards per panel on central `users`, plus a role-membership check for the current country.
- API: **Sanctum** tokens (web customers, Flutter apps), with phone OTP plus password, device list, and login history.
- Permissions: **spatie/laravel-permission with teams = country**.

### ADR-006 Payments
`PaymentGateway` contract (`createPayment`, `verify`, `handleWebhook`, `refund`) with drivers for Thawani, Stripe, PayPal and PayTabs, plus `country_gateway_configs` (encrypted creds, sandbox/live). Webhooks are signature-checked, stored in `payment_webhook_events` (unique provider event id), and processed by a job. **A success URL never marks a payment paid.**

**Per-country gateways shown to the customer (user requirement, 2026-11-14):**
- The admin enables gateways per country at **Admin → Country → Payment Gateways**. For each country × gateway they set:
  - on/off and sandbox/live
  - credentials (encrypted, never sent to clients)
  - supported currencies, and min/max amount
  - display name (EN/AR) and logo
  - sort order, plus an **"is_recommended" flag**
  - fee rule (fixed + %, passed to `PaymentFeeCalculator`)
- `GET /api/v1/checkout/payment-methods` returns **only the gateways enabled for the current country** that also support the order currency and amount, along with internal methods (wallet, gift card, COD if enabled). The list is sorted with the recommended gateway first.
- The storefront and customer app render this list at checkout with a "Recommended" badge, and the customer picks one. Secret keys stay server-side. Clients only receive a redirect/session URL or a public key (Stripe publishable key) when the provider needs it.
- Default seed (editable):

| Country | Currency | Gateways (order) | Recommended |
|---|---|---|---|
| **Oman** | OMR (3 decimals) | **Thawani**, PayTabs, Stripe, PayPal | **Thawani** |
| UAE | AED | Stripe, PayTabs, PayPal | Stripe |
| KSA | SAR | PayTabs, Stripe, PayPal | PayTabs |
| Egypt | EGP | PayTabs, Stripe | PayTabs |

- Thawani is OMR-only and Oman-only, so the adapter rejects other currencies. The `Money` VO handles OMR's **3 minor digits** (baisa).
- Figma includes the checkout payment-method selector (recommended badge, provider logos, disabled/unsupported states) and an admin gateway-config screen.
- Tests cover these cases:
  - Each country only lists its own gateways.
  - Disabled gateways are hidden.
  - A currency/amount mismatch is hidden.
  - A duplicate webhook is idempotent.
  - A success redirect without verification is not paid.

### ADR-007 Search
Phase 1 uses MySQL fulltext behind a `SearchEngine` contract. Meilisearch is the target (per-country index `{cc}_products`) once the catalog passes 50k items. Ads ranking is a separate `SponsoredRanker` service.

### ADR-008 Realtime & media
Laravel Reverb for notifications, chat, shipment updates and the dispatch map. Live audio/video uses a **LiveKit** adapter (self-host or cloud) behind a `LiveMediaProvider` contract.

### ADR-009 Panels UI stack
Blade, Tailwind 4, vanilla JS ES modules (Vite), Select2, **yajra/laravel-datatables** (server-side), Chart.js, Flatpickr, SortableJS for the page builder. A shared `<x-ui.*>` component kit mirrors the Figma panel components. **No Livewire, no React in panels.**

### ADR-010 Storefront
Next.js 16 App Router, TypeScript, Tailwind 4, `next-intl` (en/ar, RTL), TanStack Query, a typed fetch client generated from OpenAPI, `[country-locale]` route segment (`/uae-en`), SSR for SEO pages, and ISR for home and category pages.

---

## 5. Domains, subdomains & panels

Pattern (configurable in `domains` table): `{panel}.{country}.{base}`. Local base: **`nawy.test`**.

| Panel | Host (local) | Users | Figma scope |
|---|---|---|---|
| Admin (central + country ops) | `admin.nawy.test` | staff roles | layout + components |
| Sell with us (acquisition + onboarding wizard) | `sell.nawy.test/{cc-lang}` | prospects | **full pages** (public site) |
| Vendor | `vendor.{cc}.nawy.test` | sellers + staff | layout + components |
| Creator (influencer/marketer, incl. own products) | `creator.{cc}.nawy.test` | marketers | layout + components |
| Classifieds seller | `market.{cc}.nawy.test` | C2C/B2C sellers | layout + components |
| Broker | `broker.{cc}.nawy.test` | brokers/agencies | layout + components |
| Travel operator | `travel.{cc}.nawy.test` | agencies | layout + components |
| Shipping company | `shipping.{cc}.nawy.test` | carriers | layout + components |
| Delivery supervisor / ops | `ops.{cc}.nawy.test` | dispatchers | layout + components |
| Storefront API | `api.nawy.test/v1` | web + apps | — |
| Customer website (Next.js) | `nawy.test/{cc}-{lang}` | customers | **full pages** |

Production needs a wildcard DNS `*.nawy.com` plus `*.{cc}.nawy.com` and wildcard TLS (DNS-01).

---

## 6. Figma plan (Design-first)

The design team (UI/UX lead) works in **three Figma files**, all on the Nawy team plan:

### File A — `Nawy · Design System & Panels`
1. **Foundations:** variables (colour primitives → semantic tokens, light theme), type scale (Inter for EN, **Cairo** for AR — IBM Plex Sans Arabic is not available in Figma), spacing 4-pt, radii, shadows, grid (12-col 1440 / 8-col 768 / 4-col 375), breakpoints, icon set (Lucide).
2. **Core components** with variants: Button, IconButton, Input, Select (Select2 look), Checkbox/Radio/Switch, Textarea, DatePicker, FileUpload, Badge/Status, Tabs, Breadcrumb, Pagination, Alert/Toast, Modal, Drawer, Dropdown, Tooltip, Avatar, Empty/Loading/Error states.
3. **Panel kit:** Sidebar (grouped modules, collapsible), Topbar (country switcher, search, notifications, profile), Page header, KPI stat card, Chart card, **DataTable** (toolbar, filters, bulk actions, row actions, pagination), Filter bar, Form section, Wizard stepper, Timeline/activity, Kanban card.
4. **Panel layouts:** one shell per panel (10), each with its own nav IA, plus 3 template pages (List page, Form page, Detail page) showing how components compose.

### File B — `Nawy · Customer Website` (Desktop 1440 + Mobile 375, EN and AR/RTL for key templates)
Header/mega-menu/footer, Home (page-builder blocks), Category, Subcategory/PLP + filters, Search + suggestions, PDP, Brand store, Deals, Flash sale, Campaign landing, Cart (+empty), Checkout (address → shipping → payment → review), Payment failed, Order confirmation, Login, Register, OTP, **Account**: dashboard, orders, order detail/tracking, returns, refunds, wallet, vouchers, gift cards, wishlist (products/listings/properties), classified inquiries, trip bookings, notifications, addresses, profile, security, support. **Classifieds**: home, listing results, detail, seller profile, post-ad flow. **Travel**: home, destination, trip detail, booking flow. **Creators**: influencer profile/store, affiliate product. **Real estate**: results, property detail, broker profile. Help centre, static/legal page, 404/500. **Sell with us** landing + type selector + wizard + status pages.

### File C — `Nawy · Mobile Apps` (375×812)
- **Customer app, full:** onboarding, auth/OTP, home, categories, search, PLP, PDP, cart, checkout, orders and tracking, returns, wallet, vouchers and gift cards, classifieds, travel, creators, brokers, notifications, live media player, profile and settings.
- **Delivery agent app, full:** login/OTP, availability toggle, today's tasks, task detail, navigation hand-off, pickup confirm (scan), delivery (customer OTP, signature, POD photo), failed reasons and reschedule, RTO, COD collect, COD reconciliation, daily summary, notifications, support.
- **Minimal ops apps** (vendor, creator, supervisor, travel): dashboard KPIs, list with status update, notifications, and 1–2 key actions each (e.g. vendor: accept/ship order and stock edit; supervisor: assign driver).

Clickable prototypes cover purchase, seller registration, trip booking, and delivery completion. The coverage report lives in `docs/design/design-coverage.md`.

### Canva (minimal image generation)
We create the **Nawy Brand Kit** first. Assets stay intentionally lean:
- 4 home hero banners (desktop 1440×400 and mobile 750×600)
- 6 campaign tiles
- 12 category tiles
- 4 vertical heroes (Classifieds, Travel, Real-estate, Creators)
- 3 app onboarding slides
- 5 empty-state illustrations

Exports are WebP, saved under `website/public/assets/{area}/` and `backend/public/assets/`, and logged in `docs/design/asset-inventory.md`. Product images use neutral demo photos per category, with no duplicates across categories.

---

## 7. Data model (DB Architect — first cut)

**Central:**
- `countries`, `locales`, `currencies`, `exchange_rates`, `domains`
- `users`, `user_devices`, `login_history`, `otp_codes`
- `roles`/`permissions` (spatie, team=country), `role_memberships`
- `onboarding_types`, `onboarding_steps`, `onboarding_applications`, `application_documents`, `application_events`
- `payment_gateways`, `country_gateway_configs`, `settings`, `central_audit_logs`

**Country (per DB):**
- *Catalog:* `categories` (nested set, commission %), `attributes`, `attribute_values`, `category_attribute`, `brands`, `products` (owner_type: platform|vendor|creator), `product_variants` (SKU, barcode), `product_media`, `product_specs`
- *Sellers:* `sellers` (type: vendor|creator|classified|broker|travel|shipping), `seller_staff`, `seller_documents`, `seller_wallets→ledger`
- *Offers & stock:* `offers` (seller listing of a variant: price, sale price, condition, fulfilment FBN/FBP), `warehouses`, `stock_levels`, `stock_reservations` (TTL 15 min), `stock_movements`
- *Cart/orders:* `carts`, `cart_items`, `orders` (master), `sub_orders` (per seller), `order_items`, `order_status_history`, `returns`, `return_items`, `refunds`, `invoices`
- *Promotions:* `coupons`, `coupon_redemptions`, `flash_sales`, `flash_sale_offers`, `vouchers`, `gift_cards`, `gift_card_transactions`
- *Finance:* `ledger_accounts`, `ledger_transactions`, `ledger_entries`, `payout_requests`, `settlements`, `payments`, `payment_attempts`, `payment_webhook_events`
- *Affiliate:* `campaigns`, `campaign_members`, `affiliate_links`, `affiliate_clicks`, `conversions`, `attribution_policies`, `cross_promo_policies`, `cross_promo_participations`
- *Classifieds:* `classified_categories`, `classified_listings`, `listing_attributes`, `listing_media`, `listing_reports`, `conversations`, `messages`, `boosts`
- *Real estate:* `properties` (extends listing), `leads`, `lead_followups`
- *Travel:* `destinations`, `trips`, `trip_departures` (capacity, `SELECT … FOR UPDATE`), `trip_addons`, `bookings`, `booking_travelers`
- *Ads:* `ad_campaigns`, `ad_placements`, `ad_creatives`, `ad_impressions` (aggregated daily), `ad_clicks`
- *Shipping:* `shipping_companies`, `zones`, `shipping_rates`, `shipments`, `shipment_events`, `drivers`, `assignments`, `cod_collections`, `cod_remittances`
- *Content & support:* `pages` (page-builder JSON blocks per locale), `banners`, `media_schedules`, `live_sessions`, `reviews`, `questions`, `wishlists`, `notifications`, `tickets`, `audit_logs`

Indexing rule: every list query in a panel gets a composite index matching its filter and sort. QC reviews each `EXPLAIN` before a module is marked Tested.

---

## 8. Calculators (single source of truth)
`ProductPriceCalculator`, `FlashSalePriceCalculator`, `CouponCalculator`, `DiscountCalculator`, `TaxCalculator`, `ShippingFeeCalculator`, `PaymentFeeCalculator`, `CheckoutTotalsCalculator` (orchestrates the others), `VendorCommissionCalculator`, `AffiliateCommissionCalculator`, `BrokerCommissionCalculator`, `TripPriceCalculator`, `RefundCalculator`, `SettlementCalculator`, `AdvertisingBudgetCalculator`, `WalletBalanceCalculator`. Each has a docblock covering inputs, outputs, rounding and assumptions, plus 100% unit-test coverage.

---

## 9. API (v1)
- `/api/v1/public/*`: home blocks, catalog, search, PDP, classifieds, travel, creators, brokers, CMS
- `/api/v1/customer/*`: auth, cart, checkout, orders, returns, wallet, wishlist, bookings, inquiries, notifications
- `/api/v1/driver/*`, `/api/v1/vendor/*`, `/api/v1/creator/*`, `/api/v1/supervisor/*`: mobile ops
- `/api/v1/webhooks/{gateway}`

Envelope: `{ data, meta, errors }`. Validation errors return 422 with a field map. Lists use cursor/page pagination. `Idempotency-Key` is required on checkout, payment, payout and wallet calls. Country comes from the `X-Country` + `Accept-Language` headers. Rate limits are per route group. The OpenAPI spec lives at `docs/api/openapi.yaml` (generated with `dedoc/scramble`).

---

## 10. Phased roadmap & milestones

| # | Phase | Main owner | Exit criteria |
|---|---|---|---|
| **M0** | Env audit + this plan | PM | ✅ this file |
| **M1** | Discovery: full reference audits (Noon EN/AR, seller, OpenSooq, Boutiqaat, Boksha), PRD, role matrix, page inventory, risks, open decisions | PM + UX | docs/reference-research + docs/project complete |
| **M2** | Architecture docs + ERDs + ADRs | DB Arch + BE | docs/architecture complete, QC review |
| **M3** | **Figma A**: tokens + core components + panel kit + 10 panel shells | UX | Components with variants and auto-layout |
| **M4** | **Figma B**: website (all templates, EN + AR key pages, mobile web) + Canva assets | UX | Coverage report ≥ 100% of page inventory |
| **M5** | **Figma C**: customer app (full), delivery app (full), minimal ops apps | UX | Coverage report |
| **M6** | Laravel foundation: central/country DB, provisioning cmd, CountryContext, auth (guards, Sanctum, OTP), spatie perms, panel shell layouts + Blade UI kit, API envelope, audit log, queues (Redis), Reverb | BE + DB | Isolation tests green |
| **M7** | Core commerce: catalog, offers, inventory/reservations, cart, checkout totals, orders/sub-orders, shipping calc, coupons, flash sales + admin/vendor screens + APIs | BE | E2E 1–4 green |
| **M8** | Payments & finance: gateway adapters, webhooks, ledger, wallet, gift cards, vouchers, refunds, settlements/payouts | BE + QC | E2E 12,13,15,16,17 green, ledger integrity tests |
| **M9** | Storefront Next.js: shell, i18n/RTL, home builder, PLP/search, PDP, cart, checkout, account | FE | Web E2E purchase journey |
| **M10** | Marketplaces: classifieds, real estate/brokers, travel (capacity locking), affiliate/creator + attribution + cross-promo, ads | BE + FE | E2E 5–9 |
| **M11** | Shipping & delivery: carriers, zones, shipments, dispatch, driver API, COD; live media and scheduled floating media | BE | E2E 10,11,14 |
| **M12** | Sell-with-us portal + all onboarding wizards + remaining panel pages | BE | Each panel's role tests green |
| **M13** | Flutter: customer app (full), delivery app (full), minimal vendor/creator/supervisor/travel | Mobile | Flutter widget/integration tests |
| **M14** | Full QA: security, perf, RTL, cross-country isolation; deployment docs | QA + QC | `test-results.md`, `KNOWN_ISSUES.md` honest |

The build order inside each module is **migration → model → calculator/service → policy → API → panel screen → storefront/app → tests → docs**.

---

## 11. Repository layout (target)
```
noon-plus/
  MASTER_PLAN.md  PROJECT_STATUS.md  FEATURE_MATRIX.md  IMPLEMENTATION_CHECKLIST.md  KNOWN_ISSUES.md  CHANGELOG.md
  docs/{project,reference-research,architecture/decisions,design,api,testing,deployment,operations}
  backend/                Laravel 13 — app/Domains/*, app/Shared/*, app/Panels/{Admin,Vendor,...}/Http
                          routes/{panels/*.php, api/v1/*.php}, database/migrations/{central,country}
                          resources/views/{components/ui, panels/*, sell/*}, resources/js/{core,modules,panels}
  website/                Next.js 16 — src/{app/[market],features/*,components/ui,lib/api,i18n,config}
  customer_app/ delivery_app/ vendor_app/ marketer_app/ supervisor_app/ travel_agency_app/   Flutter (Riverpod + dio + go_router + flutter_secure_storage)
```
Flutter decision: **Riverpod** throughout, with a shared Dart package `packages/nawy_core` (API client, auth, models, theme) consumed by all six apps.

---

## 12. Dependencies to add (approved via this plan)
- **Backend:** `laravel/sanctum`, `laravel/reverb`, `spatie/laravel-permission`, `spatie/laravel-activitylog`, `yajra/laravel-datatables-oracle`, `brick/money`, `dedoc/scramble`, `intervention/image`, `predis/predis` (or phpredis), and stripe/paypal SDKs per adapter.
  - JS: `select2`, `jquery` (Select2/DataTables dependency only), `datatables.net-dt`, `chart.js`, `flatpickr`, `sortablejs`, `lucide`.
- **Website:** `next-intl`, `@tanstack/react-query`, `zod`, `clsx`, `lucide-react`, `embla-carousel-react`, Playwright and Vitest.

---

## 13. Definition of Done (QC gate)
1. Documented requirements
2. Figma frames exist
3. Migrations, indexes and constraints are in place
4. Services and APIs work
5. Policies and authorization are enforced
6. The dashboard and the storefront/app are connected
7. Journeys work end to end
8. Validation and errors are handled
9. Automated tests are green
10. Docs are updated

Money features also need ledger-integrity tests. Payments also need verified webhook and duplicate-webhook tests.

---

## 14. Risks & open decisions
| # | Item | Proposal |
|---|---|---|
| R1 | DB-per-country raises ops cost and complicates cross-country reports | Nightly ETL into a central reporting schema. **Reports never query live country DBs together.** |
| R2 | Noon search/PLP render client-side, which Firecrawl may miss | Use Firecrawl with `waitFor`, screenshots, and the built-in browser |
| R3 | Seller Lab is private | Rely on public docs and our product decisions, labelled as such |
| R4 | Gateway sandbox keys not yet provided | Build adapters plus fake drivers. Real keys come from the owner via `.env`/admin UI |
| R5 | Scope is very large | Strict milestone gates. Panels get layout and components in Figma, not every page |
| D1 | Brand domain (`nawy.com`?) | Base domain is configurable. Local is `nawy.test` |
| D2 | Launch countries | Seed UAE (AED), **Oman (OMR, Thawani recommended)**, Egypt (EGP), KSA (SAR). UAE is the default storefront (`nawy_uae`, `nawy_omn`, `nawy_egy`, `nawy_ksa`) |
| D3 | BNPL (Tabby/Tamara) | Out of the first wave, added later as an adapter |

---

## 15. Status tracker
| Milestone | Status |
|---|---|
| M0 Env audit & master plan | ✅ Done |
| M1 Discovery docs | 🟡 Researching (Noon home, PDP, category, seller landing audited) |
| M3 Figma A — Design system & panels | ✅ Foundations + 51 icons + 13 core components + 11 panel-kit components + 8 panel shells + 2 page templates → [file](https://www.figma.com/design/d2MhBJmRddMrMSHfjJY8Y8), see docs/design/component-inventory.md. Open: detail template, RTL mirror |
| M4 Figma B — Website | 🟡 Same Figma file (pages "Web · …"). 12 storefront components + Home (desktop+mobile), Category listing, Product detail, Cart, Checkout designed with 6 Canva product images. See docs/design/design-coverage.md. Next: account, auth, verticals, sell-with-us, RTL |
| M6 Laravel foundation | 🟡 Packages installed (sanctum, permission, activitylog, yajra datatables, brick/money, predis + panel JS libs). Next: central/country DB + CountryContext |
| Others | ⬜ Not started |
