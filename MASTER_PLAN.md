# NAWY (ناوي) — Master Plan v3
> Multi-country commerce & services super-platform. **Rebuild of the existing Nawy Marketplace** with full feature parity, the master-spec scope, and a Noon UAE–grade customer experience on web and in the app.
> Owner: BA / PM · v3 written 2026-11-14 after re-reading all sources and a QC gap review. Previous versions: `docs/project/MASTER_PLAN_v1.md`, `docs/project/MASTER_PLAN_v2.md`.
> Live status (what is done and what is left): **`STATUS.md`**.

---

## 0. Inputs

| Input | Role |
|---|---|
| `old_project_overview.md` — SRS v1.0 of the live Nawy Marketplace (commit `c0fe74c1`) | **Parity baseline.** Every panel, page, lifecycle, rule, setting and scheduled job must exist unless §13 drops or replaces it. |
| `PORTAL_PAGES.md` — every page of the old seller portal | **Portal parity baseline** (§5a). New in v3. |
| `project_docs.md` — master spec | New scope: country isolation, brokers, cross-promotion, creator products, advertising, live media, gateways, Figma-first, tests and docs. |
| `firecrawl.md` | How research is done (Firecrawl MCP + Alexandria); Perplexity has no search tools here. |
| Reference audits (`docs/reference-research/`) | Noon home, page types and widgets, **Noon app (`noon-app-audit.md`, new)**, OpenSooq, Boksha, Boutiqaat. |
| User decisions in this session | See §17 decision log. |

### What changed in v3
1. **8 countries, not 4.** The old system and portal run Oman, UAE, KSA, Egypt, **Kuwait, Qatar, Bahrain, Jordan**. All 8 are provisioned from day 1, because the data migration (D1) brings live data for all of them (§4a).
2. **Country reference data** is now explicit: codes, URL slugs, currencies (3-decimal OMR/KWD/BHD/JOD), time zones, weekends, VAT/GST, holidays (§4a).
3. **Portal parity.** Every old portal page is in scope, page by page (§5a).
4. **Customer app = Noon app structure.** A super-app context switcher, per-context headers and tab bars, Noon section order and components (§14c).
5. **Panels: layouts per page type.** The "layouts + components" rule now means each panel has its key page layouts drawn, not only an empty shell (§14).
6. **Delivery code flow follows the old system.** The 4-digit code is shown to the agent. The customer reads their copy aloud, and the agent taps "Codes match". The agent never types it (§4, ADR 15).
7. **Added missing parity items:**
   - lifecycles (ADR 7)
   - jobs (§11)
   - settings and admin modules (§7a)
   - spec deviations, logged in §17
8. The status tracker is honest: M3–M5 are 🟡 until every page in `STATUS.md` is designed and checked.

---

## 1. Team & RACI
| Role | Owns |
|---|---|
| **BA / PM** | This plan, parity matrix, PRD, role matrix, FEATURE_MATRIX, STATUS.md, acceptance |
| **Senior UI/UX** (+ design teammates) | Figma: panels (layouts per page type + components), website (every page), portal (every page), customer app (full, Noon structure), delivery app (full), ops apps (minimal); Canva assets |
| **DB Architect** | Central + 8 country DBs, ERDs, migrations, indexes, ledger integrity, old→new migration mapping |
| **Senior Backend** | Domains, calculators, state machines, jobs, APIs, Blade panels |
| **Senior Frontend** | Next.js storefront and portal from Figma, typed API client, i18n/RTL, page-builder renderer |
| **Mobile** | Flutter apps (customer full, delivery full, ops minimal) |
| **Senior QA** | Parity suite, Playwright per panel, Flutter tests |
| **Problem Solver / QC** | Concurrency, attribution/fraud, gap reviews (like the v3 review), DoD gate |

Team workflow: design work is split across parallel teammates by Figma page (guide: `docs/design/figma-team-guide.md`). Each teammate screenshots and checks every frame, and the node IDs go into `docs/design/figma-state.json`.

---

## 2. Actors and role memberships
One central identity (`users`) with **role memberships per country** (`role_memberships: user × role × country × status`).

| Actor | Membership role | Workspace |
|---|---|---|
| Platform staff (super, operations, finance, marketing, vendor-relations, content, support) | `admin` + Spatie role | `admin.` |
| Account-manager admin | `admin` + restriction (assigned vendors only, finance hidden) | `admin.` (variant) |
| Product vendor (owner and team) | `vendor:product` | `partner.` → Seller Center |
| Classified seller (individual or business, "Open Souq") | `vendor:classified` | `partner.` → Marketplace |
| Influencer / Affiliate / Broker | `marketer:{influencer,affiliate,broker}` | `marketer.` → Creator Studio / Broker |
| Marketing agency (spec §5.4) | `marketer:agency` (manages several marketers) | `marketer.` (agency switcher) |
| Property owner lister (spec §5.7) | `vendor:classified` with property vertical | `partner.` Marketplace |
| Travel agency (owner and members) | `travel_agency` | `travel-agency.` |
| Shipping company supervisor (owner or normal) | `carrier_supervisor` | `carrier.` |
| Delivery agent | `delivery_agent` | `delivery.` + delivery app |
| Customer (guest or registered) | `customer` | Storefront + customer app |

---

## 3. Parity matrix — old SRS → new system
Legend: **K** keep · **I** keep and improve · **R** replace the mechanism · **N** new · **D** drop

| Module (SRS §) | Old capability | Decision |
|---|---|---|
| Catalog §5.1 | Products, variants, attributes, per-country settings, product requests, bestsellers job, highlights | **K** + barcode/GTIN, Q&A, comparison (**N**) |
| Vendor listings §5.2 | Listing machine, contract and certification gates, virtual quantity, append-only movements | **K** |
| Platform listings §5.3 | "Nawy Listings" sold by the platform | **K** |
| Marketer listings §5.4 | Campaign, independent and own-FBN listings | **K** |
| Cart & checkout §6 | 15-min stock lock, guest cart merge, totals, 1 platform + 1 vendor coupon, marketer contract gate, sub-orders | **K** (`CheckoutTotalsCalculator`) |
| Payments §6 | Per-gateway strategy, wallet, COD, BNPL, gift card, pending expiry | **I**: unified adapters, per-country config with **recommended** flag, webhook-verified, idempotent |
| Orders §7 | Sub-order machine, master roll-up, history, auto-complete 02:00 | **K** |
| Returns / refunds / disputes / warranty / reviews §7 | Full lifecycles | **K** |
| Fulfilment & shipping §8 | Zones, methods, rates, slabs, volumetric weight, ETA skipping weekends and holidays, subsidies, FBN inbound and storage, transfers, packaging | **K**. ETA uses **per-country weekends and holidays** (§4a); the old system skipped Friday only. FBN (warehouses, inbound, storage, transfers, removals, fees) is **built** (2026-11-19, `App\Domains\Fulfilment`). |
| Delivery §8 | Assignments with a 4-digit code **shown to the agent; the customer reads it aloud and the agent matches it by eye**, SLA, COD settlement unlocks payout, carrier claims, scorecard | **K** (code flow unchanged) + proof photo, signature, failed reasons, reschedule, return-to-origin (**N**) |
| Finance §9 | Payouts, marketer balances, settlements, agent payouts, acquisition commissions, wallets, ledger, subscriptions | **I**: ledger-derived balances, central calculators |
| Marketing §10 | Coupons, coupon participation, flash sales, banners, ad campaigns, ad slots, gift cards, vouchers, card offers, newsletter, push | **K** + `SponsoredRanker` |
| Marketers §11 | Types, onboarding, origins A–F, wizard, invitations (WhatsApp signed links), replacement, attribution, samples, fees, conversions, chat, special requests, exclusive contracts, ad packages, directory | **K** + configurable attribution, clawback, cross-promotion engine (**N**) |
| Classifieds & travel §12 | Classified lifecycle with contract, dynamic categories, inquiries, map, wanted ads; travel lifecycles | **K** + seat capacity locking, vouchers, invoices (**N**) |
| Real-estate brokers (spec §5.7) | — | **N** properties, leads, follow-ups |
| Contracts §13 | 4 kinds + onboarding + exclusive; editor or file templates; versions; click-wrap; evidence | **K** and applied to all registrations |
| Live streams §14 | P2P mesh, recorded channels, comments, likes; admin creates | **R** native WebRTC one-to-many from the admin studio + Reverb signalling (D18, 2026-11-19; LiveKit removed); admin-only for all media (ADR 11) |
| Content §15 | Page builder, app contexts, static pages, portal content, blog, knowledge hub, help centre, FAQs, footer, tickets, notifications | **I**: Noon-structure block registry; app contexts drive the app's super-app switcher (§14c) |
| Roles §16 | Spatie per guard, tenant-prefixed roles | **I**: Spatie teams = country + tenant scoping |
| Jobs §17 | 30+ schedules | **K** + new jobs (§11) |
| Portal §4.2 / PORTAL_PAGES | Join chooser (4 sections, per-country toggles), vendor 5-step wizard, Open Souq, travel 2-step wizard, marketers, seller info, advertising ×6, Ads Knowledge Hub, Seller Help Center, blog, static, legal | **K** page-for-page (§5a) + broker and shipping registration journeys (**N**) |
| SRS extras | Vendor API & tokens, weight calculator, seller score / strikes / hold / blacklist, vendor zone alerts, portal analytics, advertise inquiries, celebrity monthly report, in-app documentation module, credential emails, customer QR regenerate, device revoke, public wishlist groups, carrier owner-supervisor rule | **K** (each gets a module owner in §7) |
| AI dashboard / credits | Image enhancement, video generation | **K** (adapter) |
| Retired: Influencer Deals, Secret Promotions, Affiliate Promo Codes | Docs only | **D** |

---

## 4a. Countries & locale reference (new in v3)
All 8 countries are provisioned on day 1. Each has its own country DB (`nawy_{code}`) and storefront market.

| Country | ISO2 | Portal / DB code | Store markets | Currency (decimals) | Time zone | Weekend | VAT/GST | Default gateways (★ recommended) |
|---|---|---|---|---|---|---|---|---|
| UAE | AE | `uae` | `uae-en`, `uae-ar` | AED (2) | Asia/Dubai (UTC+4) | Sat–Sun | VAT 5% | Stripe★, PayTabs, PayPal, Tabby, Tamara |
| Oman | OM | `omn` | `omn-en`, `omn-ar` | OMR (3) | Asia/Muscat (UTC+4) | Fri–Sat | VAT 5% | **Thawani★**, PayTabs, Stripe, PayPal |
| Saudi Arabia | SA | `ksa` | `ksa-en`, `ksa-ar` | SAR (2) | Asia/Riyadh (UTC+3) | Fri–Sat | VAT 15% | PayTabs★, Stripe, PayPal, Tabby, Tamara |
| Egypt | EG | `egy` | `egy-en`, `egy-ar` | EGP (2) | Africa/Cairo (UTC+2/+3 DST) | Fri–Sat | VAT 14% | PayTabs★, Stripe |
| Kuwait | KW | `kwt` | `kwt-en`, `kwt-ar` | KWD (3) | Asia/Kuwait (UTC+3) | Fri–Sat | none | PayTabs★, Stripe |
| Qatar | QA | `qat` | `qat-en`, `qat-ar` | QAR (2) | Asia/Qatar (UTC+3) | Fri–Sat | none | PayTabs★, Stripe |
| Bahrain | BH | `bhr` | `bhr-en`, `bhr-ar` | BHD (3) | Asia/Bahrain (UTC+3) | Fri–Sat | VAT 10% | PayTabs★, Stripe |
| Jordan | JO | `jor` | `jor-en`, `jor-ar` | JOD (3) | Asia/Amman (UTC+3) | Fri–Sat | GST 16% | PayTabs★, Stripe |

Rules:
- **Single code table.** One `countries` row holds all codes. The storefront uses `/{market}` (e.g. `uae-en`). The portal and help/ads sites use `/{code}/…` (e.g. `/omn/helpcenter`). URLs without a prefix redirect to the session country, or to the first active country (as in the old portal).
- **Rates and tax are settings, not hard-coded.** VAT/GST rates, weekends and the **holiday calendar** are per-country settings. Delivery ETA, SLA and payout schedules use them.
- **Admin time.** The admin panel shows Oman time. Every country date-time is entered in local time with the "= Oman time" label (ADR 14).
- **Local payment methods.** KNET (Kuwait), BenefitPay (Bahrain), NAPS/QPay (Qatar) and eFAWATEERcom (Jordan) are **open decision D6**. They can be added as `PaymentGateway` adapters.

---

## 5. Panels and subdomains
Pattern `{panel}.{base}` with an in-panel country switch (configurable to `{panel}.{cc}.{base}` in the `domains` table). Local base `nawy.test`. Public portal: **`business.{base}`** (was `portal.`).

| Host | Workspace(s) | Figma |
|---|---|---|
| `admin.` | Admin v2 IA (SRS groups + Countries & locales + System health + Live & videos) | Shell ✅ · page layouts 🟡 |
| `business.` | Public portal (§5a) | 🟡 |
| `partner.` | Seller Center / Marketplace (by `vendor.type`) | Shells ✅ · layouts 🟡 |
| `marketer.` | Creator Studio / Broker / Agency | Shells ✅ · layouts 🟡 |
| `travel-agency.` | Travel Partner | Shell ✅ · layouts 🟡 |
| `carrier.` | Shipping company + Ops/supervisor dispatch | Shells ✅ · layouts 🟡 |
| `delivery.` | Delivery agent web | Shell ✅ · layouts 🟡 |
| root `/{market}` | Next.js storefront | Figma 🟡 |

Every panel host has sign-in, forgot and reset password pages.

Mobile apps: `customer_app` (full, Noon structure), `delivery_app` (full), `vendor_app`, `marketer_app`, `supervisor_app`, `travel_agency_app` (minimal; one shared sign-in with role switch).

## 5a. Portal pages (parity with PORTAL_PAGES.md)
| Section | Pages |
|---|---|
| Join | `/join` chooser: 4 sections (Product seller, Open Souq, Travel agency, Marketer → influencer/affiliate/broker) + country. Per-country admin toggle `join_option_{section}_enabled`. |
| Vendors | Landing; **5-step wizard** (1 Account · 2 Store · 3 Contact & address · 4 Documents · 5 Agreement & e-signature); success; application status (in review / approved / rejected with reason / more documents) |
| Open Souq | Landing (`/companies/open-souq`); sign-up uses the vendor wizard |
| Travel agencies | Landing; **2-step wizard** (Owner account · Agency details with licence and logo); success |
| Marketers | Overview + Influencer, Affiliate, Broker pages; WhatsApp signed-link invitation page |
| Seller info | FAQ, How it works, Fulfillment (FBM vs FBN), Smart tools |
| Advertising (`/{code}/advertise/…`) | Sellers, Brands, Advertisers, Product ads, Display ads, Request form |
| Ads Knowledge Hub (`/{code}/adsupport`) | Home, Collection, Article (10 collections, 12 articles seeded from the old DB) |
| Seller Help Center (`/{code}/helpcenter`) | Home, Search (EN+AR, top 30 by views), Category, Article |
| Blog | List (featured, 4 categories, tag/search, 12 per page), Post (+3 related) |
| Static | About, Terms, Contact, Privacy, Return, Shipping, Refund (audience `portal`/`both`, country override) |
| Legal | Seller agreement per country (`?country=`), AR/EN file viewer and download |
| Switchers | `/language/{ar|en}`, `/country/{code}` (session) |
| New (spec §11) | Broker and shipping-partner registration journeys |

---

## 6. Architecture decisions (ADR summary)
1. **Modular monolith.** Laravel 13 with `app/Domains/*` and `app/Shared/*`.
2. **Country isolation.**
   - Central DB `nawy_central` holds identity, memberships, countries (with the §4a reference data), domains, gateway credentials (encrypted), settings, contract templates (with a country column) and audit.
   - **8 country DBs** (`nawy_uae`, `nawy_omn`, `nawy_ksa`, `nawy_egy`, `nawy_kwt`, `nawy_qat`, `nawy_bhr`, `nawy_jor`) hold operational data.
   - `CountryContext` switches the DB, cache, queue and storage prefix.
   - A nightly ETL loads `nawy_reporting`.
3. **Money.**
   - `DECIMAL(19,4)` + brick/money.
   - Rounding is half-up to the currency's minor unit: **3 decimals for OMR, KWD, BHD and JOD**, 2 for the others.
   - Amounts are never summed across currencies. Fees and terms are snapshotted.
4. **Ledger.** Double-entry with idempotency keys. Wallets (owner × currency: pending / spendable / reserved / frozen / **promotional**) are projections.
5. **Auth.**
   - Guards per panel on central users + memberships. Sanctum for the storefront and apps.
   - Customers sign in **by email** (password or a 6-digit email code) or with Google/Apple. Email must be verified before checkout.
   - The phone number is optional and used for delivery only (deviation from spec §17.1, see §17).
   - Business accounts use email + password, with **optional 2FA**. Login history and device revoke for everyone.
   - Signed URLs for WhatsApp invitations.
6. **Payments.**
   - `PaymentGateway` adapters: Thawani, Stripe, PayPal, PayTabs, Tabby, Tamara (+ D6 local methods). Internal methods: wallet, COD, gift card.
   - `country_gateway_configs` holds enabled, mode, credentials, currencies, min/max, sort, `is_recommended` and fee rule. Seeds are in §4a.
   - Payments are confirmed by webhook or server verification only.
7. **State machines** (one class each, guarded, history with actor):
   - **Catalog & listings:** listing, product status, product request, product certification.
   - **Orders:** master order roll-up, sub-order, shipment, delivery assignment, return, refund, dispute, warranty claim, review.
   - **Money:** payout, wallet withdrawal, delivery payout, COD settlement, storage-fee invoice, subscription.
   - **Marketing:** campaign, invitation, conversion, sample, coupon invitation, coupon participation request, ad campaign, ad booking, ad-package subscription, flash-sale event, flash-sale submission, gift card.
   - **Classifieds & travel:** classified, package, booking, inquiry.
   - **Logistics:** inbound, transfer, packaging order, carrier claim.
   - **Applications & accounts:** vendor application, onboarding document, change request, marketer global status, agency status.
   - **Contracts:** contract template, customer contract, marketer-seller contract, exclusive contract.
   - **Content & support:** special request, page/banner/blog schedule, ticket, stream.
8. **Contract engine.**
   - Templates, resolvers per kind, and signatures with evidence (frozen copy, SHA-256, IP, user agent).
   - No cross-country fallback. Applies to every registration.
9. **Search.** `SearchEngine` (MySQL fulltext → Meilisearch per country) + `SponsoredRanker`. Search analytics and popular searches are stored per country.
10. **Real-time.** Reverb, Horizon. Notification channels: DB, mail, broadcast, FCM, WhatsApp, SMS. Templates are per country and language.
11. **Live media: admin-only (decided 2026-11-14).**
    - ~~LiveKit SFU~~ → native WebRTC one-to-many P2P from the host's browser, signalling relayed over Reverb private channels, viewer cap `live.webrtc_max_viewers` (owner decision D18, 2026-11-19; docs/deploy/webrtc.md) + recorded channels.
    - Only admins create, upload, schedule and publish streams, recorded channels and every video (Admin → Content → Live & videos).
    - Creators, stores and brands are tags only.
    - Sponsored video creatives are uploaded by admin on the booking.
    - **Exception:** listing media (product and classified photos plus a short listing video) stays with the listing owner.
    - **Spec conflict noted:** spec §5.4's "promotional media library for marketers" becomes an admin-curated library that marketers can only browse and share links from.
12. **Panels UI.** Blade, Tailwind 4, vanilla JS, Select2, Yajra DataTables, Chart.js, Flatpickr, SortableJS. `<x-ui.*>` mirrors Figma. No Livewire, no React.
13. **Storefront & portal.** Next.js 16 App Router: `[market]` storefront, `business` portal app (or route group), SSR/ISR, next-intl RTL, page-builder renderer, typed OpenAPI client.
14. **Time & timezones.**
    - UTC storage. Per-country time zones as in §4a. Admin displays Oman time.
    - Country date-times are entered in local time with an "= Oman time" label.
    - `CountryClock` also exposes weekends and holidays.
15. **Delivery code (parity).**
    - The 4-digit code is generated per assignment and shown in the agent app and in the customer app/email.
    - The customer reads it aloud and the agent taps **"Codes match"**. Proof photo or signature is added for COD/high-value items.
16. **App contexts.** The `app_contexts` admin module (SRS §15) defines the customer app's super-app switcher: Main, Super Mall, Food, Minutes (15-minute), Souq (classifieds), Travel, Property, Live. Each context has its own header theme, home layout (page builder `app_context` template) and bottom tab bar, per country.

---

## 7. Domain modules (backend)
`Countries (+Locales, Domains, Calendars, Tax), Identity (+2FA, Devices, LoginHistory), Customers, Catalog, Listings, Inventory, Warehouses (FBN), Packaging, Cart, Checkout, Payments, Orders, Shipments, Returns, Refunds, Disputes, Warranty, Reviews, Shipping (+WeightCalculator), Delivery, Carriers, Finance (Ledger, Wallets, Payouts, Settlements, Subscriptions, AcquisitionCommissions, Reconciliation), Coupons, CouponParticipation, FlashSales, Banners, Advertising (AdCampaigns, AdSlots, AdBookings, AdPackages, SponsoredRanking), GiftCards, Vouchers (incl. service and trip vouchers), Membership (Nawy One), Marketers (Profiles, Jobs, Rules, Contracts, Agencies), Campaigns (Wizard, Invitations, Samples, Fees, Budget, Attribution, Conversions, Clawback, CrossPromotion, ContentSubmissions), Classifieds (+SavedSearches, Compare, AbuseReports, Expiry), RealEstate (Properties, Leads, FollowUps), Travel (+Availability, OptionalServices, Modifications, OperatorSettlement), Contracts, Documents, SellerPerformance (Score, Strikes, Hold, Blacklist), VendorApi (Tokens), Content (PageBuilder, AppContexts, Blog, KnowledgeHub, HelpCenter, FAQs, StaticPages, Footer, MediaLibrary), LiveMedia, Support (Tickets, Chat), Notifications (+Templates), Analytics (+SearchAnalytics, PortalAnalytics, CelebrityReport), AI, Portal (Join, Funnel, AdvertiseInquiries, Applications), Ops (SystemHealth, QueueMonitor, FailedJobs, ApiLogs, SecurityEvents, Backups)`

## 7a. Settings catalogue (must exist; M2 writes the full list)
- **Join and onboarding:** `join_option_{section}_enabled` per country; document-types matrix (country × business type × category: mandatory / optional / N/A); onboarding-step builder per registration type.
- **Commerce:** free storage days (30); return window per category; COD fee; free-shipping threshold; coupon stacking (1 platform + 1 vendor); cart lock minutes (15).
- **Marketing:** campaign fee refund policy; attribution model and window; clearing days.
- **Country:** weekend and holidays; VAT/GST rates; currency rounding.
- **Membership:** Nawy One price per country.
- **Old-system keys:** all SRS §20 keys.

---

## 8. Marketers & campaigns
Unchanged from v2 (pay models, commission basis, origins A–F, fees, invitations, attribution with last/first/linear, conversions with clawback, cross-promotion engine). Added in v3:
- Campaign marketplace with applications.
- Content submission and approval.
- Performance rankings.
- Coupon-ID attribution and marketer coupons.
- Marketing-agency accounts.
- Broker documents.

## 9. Contracts & documents
Kinds: `seller_platform`, `seller_category`, `seller_marketer`, `customer_purchase`, `marketer_onboarding`, `exclusive`, `travel_agency`, `broker`, `shipping_company`, `trip` (customer ↔ agency).
Document requirements are per country × type. Mobile click-wrap is enforced.

## 10. Finance
As in v2 (vendor payout net, marketer-as-seller settlement, agent payouts, acquisition commissions, subscriptions), plus:
- Payment reconciliation and gateway reports.
- Promotional credits with expiry.
- Membership billing.
- Clawback recovery.
- Suspicious-transaction flags.

## 11. Scheduled jobs (country-aware, idempotent)
- **Every minute:** stock-lock release, recorded-stream sync, **travel seat-hold expiry**.
- **Every 5 minutes:** flash-sale transitions, banner, page and blog schedulers, paid-ad scheduler, pending-payment expiry, **scheduled homepage media**.
- **Every 15 minutes:** SLA breach, coupon invitation close, promotion expiry.
- **Hourly:** campaign stock monitor, ad-package expiry, contracts apply-effective, analytics cache, **saved-search alerts**.
- **Daily:**

  | Time | Job |
  |---|---|
  | 00:00 | Ad budget reset |
  | 00:15 / 00:30 / 00:45 | Coupon / gift card / exclusive-contract expiry |
  | 01:00, 01:15 | FBN overage, then flag |
  | 02:00 | Auto-complete |
  | 03:00 | Warranty expiry |
  | 03:15 | Approve conversions |
  | 03:20 | Settle marketer sales |
  | 03:30 | Release commissions |
  | 03:45 | Complete bookings + **agency campaign-fee retry** |
  | 04:00 | End campaigns |
  | 04:30 | Analytics prune |
  | 23:30 | COD settlements |

  Not tied to a time slot:
  - Activate fulfilled coupon invitations.
  - **Classified listing expiry and renewal reminders.**
  - **Gift-card scheduled email delivery.**
  - **Nightly reporting ETL.**
  - **Search index rebuild.**
  - **Clawback recovery.**
  - **Membership renewals.**
- **Weekly / monthly:**
  - Vendor payouts.
  - FBN inbound reminder.
  - Bestseller ranking.
  - Acquisition commissions.
  - FBN storage invoices.
  - **Subscription invoices and past_due handling.**
  - **Cross-promotion obligation evaluation.**
  - **Celebrity monthly report.**
- **Delayed:** campaign auto-approve, invitation timeout.

## 12. Data model additions
v2 list (see `docs/project/MASTER_PLAN_v2.md` §12 and §14b), plus:
- **Country reference:** `country_calendars` (weekends, holidays), `tax_rates`.
- **App and portal:** `app_contexts` (+ tabs, theme), `join_options`, `onboarding_steps`, `application_audits`.
- **Seller and partner tools:** `seller_scores` (+ strikes, holds), `vendor_api_tokens`, `wishlist_groups`.
- **Membership and wallet:** `memberships` (+ plans, invoices), `promotional_credits`.
- **Notifications:** `notification_templates`.
- **Analytics and ops:** `search_queries`, `api_logs`, `security_events`.
- **Classifieds:** `classified_reports`, `listing_renewals`.
- **Travel:** `travel_optional_services`, `booking_modifications`.
- **Campaigns:** `content_submissions`, `marketing_agencies`.

## 13. Fixes for the old system's known gaps (SRS §19)
Unchanged from v2: attribution models, docs regenerated, retired features dropped, mobile click-wrap, clawback, configurable campaign-fee refund, LiveKit, unified contract engine.

---

## 14. Design plan (Figma) — scope
One file: https://www.figma.com/design/d2MhBJmRddMrMSHfjJY8Y8. The page-by-page list and status live in `STATUS.md` and `docs/design/design-coverage.md`.
- **Website:** every customer page and state, desktop + mobile web. AR/RTL rules are shown on key pages.
- **Portal:** every page in §5a.
- **Panels:** shell + **key page layouts per panel**:
  - **Admin:** dashboard, approvals, order detail, config grids, contract editor, finance, countries & locales, system health, live & videos, page builder, gateways.
  - **Seller Center:** dashboard, order detail, products list, create listing, campaign wizard, payout statement, FBN inbound.
  - **Creator Studio:** overview, campaign detail, invitations, wallet, cross-promotion.
  - **Marketplace:** my ads, create ad, mark sold, inquiries.
  - **Broker:** leads pipeline, special requests, properties.
  - **Travel Partner:** dashboard, departures and capacity, package editor, bookings.
  - **Shipping:** overview, shipments, COD reconciliation, claims.
  - **Ops:** live dispatch, failed deliveries.
  - **Delivery web:** my day, earnings, COD.
- **Apps:**
  - Customer app full in the **Noon app structure** (§14c).
  - Delivery app full.
  - Ops apps minimal: sign-in + role switch, plus vendor, marketer, supervisor and travel screens.
- **Canva:** minimal, with approval each time.

## 14a. Page builder
51 blocks in 10 groups and the templates `home`, `store_landing`, `category`, `campaign`, `brand_store`, `seller_store`, `marketer_store`, `search`, `vertical_landing`, `app_context`. Spec: `docs/design/page-builder-blocks.md`.

## 14b. Marketplace & creator references
OpenSooq / Boksha / Boutiqaat features on the current Nawy layout (see v2 §14b). Those pages are designed.

## 14c. Customer app — Noon structure (new in v3)
Source: `docs/reference-research/noon-app-audit.md` (layout and behaviour only, no Noon assets). Figma page **"App · Customer v2 (Noon structure)"**.
- **Shell:**
  - Super-app **context switcher** (Nawy, Super Mall, Food, Minutes, Souq, Travel, Property, Live). The header theme changes per context.
  - Header: address line (or ETA in Minutes) + pill search with camera.
  - **Main tab bar:** Home · Categories · Deals · Account · Cart (with badge).
- **Home section order:**
  1. Hero carousel with a peek and dots.
  2. Sponsored strip.
  3. 2-row category circles.
  4. Ad banner.
  5. Recommended carousel.
  6. Offers (coupon chips).
  7. Mega deals with countdown and 2×2 grid.
  8. Bestsellers.
  9. 2-up promo tiles.
  10. More reasons to shop.
  11. Nawy Live strip.
  12. Previously browsed.
  13. Category showcases.
  14. Popular searches.
- **Discovery:**
  - Categories tab (left rail + subcategory grids + top brands).
  - Search overlay (recent thumbnails, trending chips) and suggestions with a bold prefix.
  - Results with a sponsored brand block, count + grid/list toggle, and a sticky chip bar.
  - Full-height filter sheet (facet rail, live "Show N results"), sort sheet.
  - Deals tab, brand store, seller store.
- **Product page:**
  - Gallery, price and VAT, bestseller rank, coupon chips, variants, delivery date with cut-off countdown, sponsored alternative.
  - BNPL and cashback, protection plans, trust chips, summary tabs, seller card, frequently bought together, ratings histogram and reviews.
  - Sticky qty + Add to cart; added-to-cart sheet; reviews, write review, Q&A.
- **Cart & checkout:**
  - Free-delivery progress, shipments grouped by delivery type, save for later, coupon sheet (1 + 1 rule), sticky summary.
  - Checkout (address, delivery per shipment, payment methods per country, credits), address map picker, 3-D Secure wait, payment failed, order placed.
- **Account:**
  - Account tab with the Nawy One card, orders, order detail with invoice, tracking with the delivery code, return wizard.
  - Nawy Credits (incl. promotional), Nawy One membership, wishlist groups, notifications, addresses, saved cards (tokens), security & devices, country & language (8 countries), chats, contracts, gift cards.
- **Contexts:** Super Mall, Minutes (3-column grid, floating cart pill, order-again toggle), Food (restaurant cards), Souq, Travel, Property, Nawy TV vertical video feed.
- **Auth & system:** welcome (email + Apple/Google), email code, create account, permissions, offline / error / force update, toasts.
- **Components:** context switcher, product card (Grid, Carousel, Sponsored, List), mini grocery card, section header, chip (default/active/dropdown/trending), coupon chip, floating cart pill, toast, tab bar, bottom sheets.

---

## 15. Roadmap (milestones)
| # | Milestone | Exit criteria |
|---|---|---|
| M0 | Env + plan v1 | ✅ |
| M1 | Plan v2 → **v3** + parity matrix + gap review | ✅ (this file) |
| M2 | Discovery docs: PRD, role-permission matrix, lifecycle catalogue (ADR 7 list), settings catalogue (§7a), country reference (§4a), risk register, FEATURE_MATRIX, route→frame coverage map | docs/project complete |
| M3 | Figma A — design system, panel shells, kit, **panel page layouts** | ✅ 2026-11-14 (49 panel layouts + templates) — owner review |
| M4 | Figma B — website (all pages and states) + **portal (all §5a pages)** | ✅ 2026-11-14 (website + 45 state/extra frames; portal 50+ pages) — owner review |
| M5 | Figma C — **customer app (Noon structure)**, delivery app, ops apps | ✅ 2026-11-14 (customer v2 54 screens, delivery 17, ops 18) — owner review |
| M6 ✅ | Laravel foundation: central + **8 country DBs**, provisioning, CountryContext (+ calendars, tax), identity, memberships, guards, 2FA, panel shells + `<x-ui>` kit, API envelope, audit, Horizon, Reverb, settings service, scheduler | isolation tests green for all 8 countries |
| M7 | Catalog, listings, inventory, FBN, cart, checkout, orders, shipments, shipping calc (per-country weekends and holidays), coupons, flash sales | purchase E2E |
| M8 | Payments (6 adapters + D6), ledger, wallets (incl. promotional), refunds, payouts, settlements, subscriptions, membership, gift cards, vouchers, reconciliation | ledger + webhook tests, 3-decimal currency tests |
| M9 | Marketers & campaigns, agencies, contracts engine, documents, onboarding-step builder | campaign E2E A–F, clawback |
| M10 | Classifieds (expiry, saved searches, abuse), real estate/brokers, travel (capacity, modifications), advertising, coupon participation | vertical E2Es |
| M11 | Delivery, carriers, COD, claims, scorecard, live media, page builder, **app contexts**, CMS, notification templates | delivery E2E (code-match flow) |
| M12 | Portal (§5a) + all panel pages + panel auth + system health | role tests per panel |
| M13 | Next.js storefront + portal (RTL, page-builder renderer) | web E2E |
| M14 | Flutter apps (customer Noon structure, delivery, ops) | app tests |
| M15 | Data migration cutover for all 8 countries + hardening, security (retention, backups), performance, docs tree (spec §23) | reconciliation 100%, sign-off |

Build order inside each module: migration → model → calculator/service → state machine → policy → API → panel → storefront/app → legacy migration step + reconciliation test → tests → docs.

## 16. Testing
- **Parity suite:** one test per SRS lifecycle and rule, plus every job.
- **E2E:** spec E2E 1–18, campaign origins, contract gates, COD unlock, FBN fees.
- **Isolation tests across 8 countries. Money tests for all 8 currencies** (3-decimal OMR, KWD, BHD, JOD).
- **Calendar tests:** weekends and holidays per country, Egypt DST.
- **Tools:** Playwright per panel and portal; Vitest + Playwright for Next.js; Flutter widget and integration tests.
- **Clickable prototypes for the 9 key journeys** (spec §15.3).

---

## 17. Decisions, deviations & risks
| # | Item | Status |
|---|---|---|
| D1 | Migrate data from the old live DB | ✅ Approved. `docs/project/data-migration-plan.md`. Now covers all 8 countries. |
| D2 | Launch countries | ✅ **All 8 on day 1** (v3). Previously 4 + "later". |
| D3 | Subdomains | `{panel}.{base}` + in-panel country switch; portal at `business.` (configurable) |
| D4 | BNPL | Tabby, Tamara (where available) |
| D5 | Campaign fee refund | No refund after live, configurable |
| D6 | Local payment methods (KNET, BenefitPay, NAPS/QPay, eFAWATEERcom) | ❓ Owner to confirm. Adapters are ready to add. |
| D7 | VAT/GST rates and weekends in §4a | ❓ Finance to confirm. They are settings, not code. |
| D8 | **Travel and classified bookings take no payment** | ✅ Owner decision (2026-11-14): the customer fills in name, email and phone and submits. The agency or seller confirms and settles payment outside Nawy. Seat capacity and holds still apply. Guests can book. **Open Souq update (D12, 2026-11-18):** a classified viewing / reserve request is a record only (no confirm, status or status mails); the seller contacts the customer outside Nawy. **Travel (D11):** no commission and no marketer-campaign fees. |
| D9 | **Customer sign-in: email code, password, Google and Apple** | ✅ Owner decision (2026-11-15): customers can sign in with an email code (the default), with an optional password (set, change, forgot/reset by email), or with Google and Apple sign-in. Social accounts link by verified email. The owner approved installing `laravel/socialite` (2026-11-15) for Google and Apple sign-in. If Apple needs it, a Socialite community provider package also requires approval. |
| DV1 | **Deviation:** customers sign in by email, not phone + OTP (spec §17.1) | User decision 2026-11-14 |
| DV2 | **Deviation:** panels are designed as layouts + components (key page types), not every panel page (spec §15.2) | User brief |
| DV3 | **Deviation:** marketer media library is admin-curated (spec §5.4) | ADR 11 |
| DV4 | Delivery code: agent sees it, customer reads it aloud (SRS), not agent-types | Parity kept |
| R1 | Scope size | Milestone gates; parity first |
| R2 | 8 DBs make reporting and migration heavier | Reporting ETL; per-country migration jobs |
| R3 | Canva exposes only 200px previews | Owner downloads originals |
| R4 | Gateway sandbox keys | Fake drivers until provided |
| R5 | Noon app screens behind login could not be verified | Designed from the audit plus standard patterns; review with the owner |
