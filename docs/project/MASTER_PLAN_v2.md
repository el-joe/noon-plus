# NAWY (ناوي) — Master Plan v2
> Multi-country commerce & services super-platform. **Rebuild of the existing Nawy Marketplace** with full feature parity, the new master-spec scope, and a Noon UAE–grade customer experience.
> Owner: BA / PM · v2 written 2026-11-14 after the updated SRS (`old_project_overview.md`). v1 kept at `docs/project/MASTER_PLAN_v1.md`.

---

## 0. Inputs and what changed in v2

| Input | Role in the plan |
|---|---|
| `old_project_overview.md` — **SRS v1.0 of the live Nawy Marketplace** (commit `c0fe74c1`) | The **parity baseline**. Every panel, page, lifecycle, rule, setting and scheduled job it lists must exist in the new system, unless §13 marks it dropped or replaced. |
| `project_docs.md` — master spec | Adds new scope: per-country DB isolation, brokers module, cross-promotion engine, creator-owned products, advertising, live media, 4 gateways, Figma-first, full test and documentation set. |
| Noon UAE live audit (`docs/reference-research/noon-homepage-audit.md`) | Customer UX reference: homepage structure, PDP, cart and checkout patterns. |
| User decisions in this session | Payment gateways per country (Thawani recommended for Oman). Homepage mirrors Noon's current structure, with **every section a page-builder block**. Images generated in Canva, minimal. |

**What v2 changes compared to v1**
1. Scope is now **"parity + new"**. The old SRS is richer than v1 assumed: the marketer v3 model, the contract engine, FBN inbound and storage fees, warranty, disputes, Nawi ad slots, coupon participation, app contexts, recorded radio channels, and 30+ scheduled jobs.
2. Panels follow the old subdomain model (`admin.`, `portal.`, `partner.`, `marketer.`, `travel-agency.`, `delivery.`, `carrier.`). Workspaces are tailored by **role membership**: product vendor or classified seller in `partner.`; influencer, affiliate or broker in `marketer.`.
3. The 8 **known gaps** in the old system (SRS §19) become explicit fixes (§13).
4. New items:
   - LiveKit SFU replaces the P2P mesh.
   - Configurable attribution models.
   - Commission clawback.
   - A unified contract engine for every registration type.
   - Data migration from the old database becomes an open decision.

---

## 1. Status snapshot (2026-11-14)

| Area | State |
|---|---|
| Env audit | ✅ PHP 8.3, Laravel 13.17, Next 16.4, Flutter, MySQL 8, Redis. Figma (pro), Canva, and Firecrawl are connected. Perplexity has no search tools. |
| Packages | ✅ Backend: sanctum, permission, activitylog, yajra datatables, brick/money, predis, plus panel JS (select2, datatables, chart.js, flatpickr, sortablejs, lucide). Website: next-intl, react-query, zod, embla, lucide-react. |
| Figma — design system & panels | ✅ 47 primitives, 32 semantic colour tokens (light/dark), spacing and radius, 26 text styles (EN Inter / AR Cairo), 51 icons, 13 core components, 11 panel-kit components, 8 panel shells, 3 admin templates (List, Payment gateways, Page builder). |
| Figma — website | 🟡 20 storefront and block components. Pages done: **Home v2 (Noon structure, 21 blocks)**, Category listing, PDP, Cart, Checkout (per-country payments), mobile Home, Login, OTP, Register, Account Overview, Orders. |
| Canva assets | 🟡 8 images (2 hero, 6 product). Policy: minimal generation. Full-resolution originals still need downloading (`docs/design/asset-inventory.md`). |
| Docs | ✅ Page-builder block registry, Noon homepage audit, component inventory, design coverage, Figma state ledger. |
| Code | ⬜ Laravel and Next.js are fresh skeletons. Flutter apps are fresh skeletons. |

Figma file: https://www.figma.com/design/d2MhBJmRddMrMSHfjJY8Y8

---

## 2. Team & RACI
| Role | Owns |
|---|---|
| **BA / PM** | This plan, the parity matrix (§4), PRD, role matrix, `FEATURE_MATRIX.md`, status files, acceptance |
| **Senior UI/UX** | Figma (panels: layouts and components; website: every page; apps: customer and delivery in full, others minimal), Canva asset pipeline |
| **DB Architect** | Central and country topology, ERDs, migrations review, indexes and query budgets, ledger integrity, old→new data migration mapping |
| **Senior Backend** | Domains, calculators, state machines, scheduled jobs, APIs, Blade panels |
| **Senior Frontend** | Next.js storefront from Figma, typed API client, i18n/RTL, page-builder renderer |
| **Senior QA** | Test strategy, parity test suite (one scenario per SRS lifecycle), Playwright E2E per panel, Flutter tests |
| **Problem Solver / QC** | Concurrency (stock locks, booking capacity, campaign budget locks, idempotent conversions and webhooks), attribution and fraud, DoD gate |

---

## 3. Actors and role memberships
One central identity (`users`) with **role memberships per country** (`role_memberships: user × role × country × status`). One person can be a customer, a vendor and a marketer.

| Actor | Membership role | Workspace |
|---|---|---|
| Platform staff (super, operations, finance, marketing, vendor-relations, content, support) | `admin` + Spatie role | `admin.` |
| Account-manager admin | `admin` + restriction permission (assigned vendors only, finance hidden) | `admin.` |
| Product vendor (owner and team) | `vendor:product` (+ vendor-scoped roles) | `partner.` → Seller Center |
| Classified seller (individual or commercial) | `vendor:classified` | `partner.` → Marketplace workspace |
| Influencer / Affiliate / Broker (one or more) | `marketer:{influencer,affiliate,broker}` | `marketer.` → Creator Studio; broker workspace adds Properties, Leads, Special requests |
| Travel agency (owner and members) | `travel_agency` (+ agency roles; owner bypasses checks) | `travel-agency.` |
| Shipping company supervisor (owner or normal) | `carrier_supervisor` | `carrier.` |
| Delivery agent | `delivery_agent` | `delivery.` + delivery app |
| Customer (guest or registered) | `customer` | Storefront + customer app |
| System (scheduler, queue) | — | — |

---

## 4. Parity matrix — old SRS → new system
Legend: **K** keep the behaviour · **I** keep and improve · **R** replace the mechanism · **N** new (from spec) · **D** drop

| Module (SRS §) | Old capability | New decision |
|---|---|---|
| Catalog §5.1 | Products, variants, attributes, per-country settings, product requests, bestsellers job, highlights | **K** + barcode/GTIN, Q&A, comparison (**N**) |
| Vendor listings §5.2 | `draft→pending_review→active⇄paused/out_of_stock/rejected/archived`; contract and certification gates; virtual `quantity_available`; append-only `inventory_movements` | **K** |
| Platform listings §5.3 | "Nawy Listings" sold by the platform | **K** |
| Marketer listings §5.4 | Campaign, independent (bound to source) and own-FBN listings | **K** (creator-owned products per spec §5.5) |
| Cart & checkout §6 | 15-min stock lock, guest cart token merge, totals formula, max 1 platform + 1 vendor coupon, marketer contract gate, split into sub-orders | **K**. Formula moves into `CheckoutTotalsCalculator` (**I**). |
| Payments §6 | Strategy per gateway (Thawani in baisa inside its class), wallet, COD, BNPL Tabby/Tamara, gift card; pending expiry | **I**: unified `PaymentGateway` adapters (Thawani, Stripe, PayPal, PayTabs, Tabby, Tamara), per-country config with a **recommended** flag, webhook-verified, idempotent |
| Orders §7 | Sub-order state machine, master roll-up, history with actor, auto-complete 02:00 | **K** |
| Returns / refunds / disputes / warranty / reviews §7 | Full lifecycles (inspection outcomes, refund routes, 3-party warranty thread, review moderation) | **K** |
| Fulfilment & shipping §8 | Zones→methods→rates→slabs→surcharges; volumetric weight; ETA skips Friday and holidays; exceptional-zone subsidy; FBN inbound, storage clock, overage fees; transfers; packaging catalog and orders | **K** |
| Delivery §8 | Assignments with 4-digit OTP shown to the agent, SLA checks, shipments, COD settlement unlocks payout, carrier claims and scorecard | **K** + proof photo, signature, failed reasons, reschedule, return-to-origin (spec §8.2, **N**) |
| Finance §9 | Vendor payouts (schedules, approve→process), marketer pending→spendable, marketer-as-seller settlement, agent payouts, acquisition commissions, wallets per owner×currency, double-entry ledger, subscriptions | **I**: every balance derived from the ledger; calculators centralised; no float |
| Marketing §10 | Coupons (4 types × 4 scopes), coupon participation invitations, flash sales (event + submission machines, marketer bonus), banners, self-serve ad campaigns (CPC/CPM), Nawi ad slots and bookings, gift cards, vouchers, cart card offers, newsletter, push | **K** + `SponsoredRanker`, mandatory Sponsored label (**I**) |
| Marketers §11 | v3 types, onboarding, campaign origins A–F, wizard, statuses, availability rules, peer promotion, invitations with WhatsApp signed links, auto replacement, attribution (direct, last-click), referral matrix, samples, fees and budget, conversions, classified and travel specifics, chat, special requests, exclusive contracts, ad packages, directory | **K** all, + configurable attribution, clawback, and a **cross-promotion policy engine** that generalises peer promotion and monthly obligations (**N**, spec §5.6) |
| Classifieds & travel §12 | Classified lifecycle with contract before submission, dynamic categories and sections, inquiries, map, wanted ads; travel agency, package, booking and inquiry lifecycles | **K** + transactional seat capacity, booking vouchers, invoices (**N**) |
| Real-estate brokers (spec §5.7) | Brokers are a marketer type that handles special requests | **N**: properties, leads, follow-ups, lead analytics inside the broker workspace |
| Contracts §13 | 4 contract kinds plus marketer onboarding and exclusive contracts; editor or file templates; versions; click-wrap and notice consent; resolver with no cross-country fallback; evidence (hash, IP, UA, frozen copy) | **K**, and applied to **all** registrations (fixes gap 8) |
| Live streams §14 | WebRTC P2P mesh (30–50 viewers), recorded "radio" channels with synced playback, comments and likes | **R**: LiveKit SFU adapter. Recorded channels **K**. Homepage floating media (**N**). Admin-only creation **K** (as in the old SRS FR-LS-1), extended to all videos (ADR 11). |
| Content §15 | Page builder (blocks, visibility, analytics, revisions, copy to countries, scheduled), app contexts, static pages, portal content, blog, knowledge hub, help centre, FAQs, footer, tickets, notifications (DB, mail, Reverb, FCM, WhatsApp) | **I**: Noon-structure block registry (`docs/design/page-builder-blocks.md`), per country × locale |
| Roles §16 | Spatie per guard; tenant-prefixed vendor and agency roles | **I**: Spatie teams = country, plus tenant scoping |
| Jobs §17 | 30+ schedules | **K** (catalogue in §11) |
| Portal §4.2 | Join chooser, landing pages, advertise pages and inquiries, vendor registration funnel, legal pages, knowledge hub | **K** + 7 registration journeys (spec §11) |
| AI dashboard / AI credits | Image enhancement and video generation | **K** (provider adapter) |
| Retired: Influencer Deals, Secret Promotions, Affiliate Promo Codes | Docs only | **D** |

---

## 5. Panels and subdomains (final)
Pattern `{panel}.{base}` with the country chosen inside the panel. Production may use `{panel}.{cc}.{base}`, configurable in the `domains` table. Local base: `nawy.test`.

| Subdomain | Workspace(s) | Figma shell |
|---|---|---|
| `admin.` | Central + country operations (SRS §4.1 groups: Overview, Catalog, Inventory & Fulfilment, Orders & Disputes, Users & Vendors, Marketers & Campaigns, Marketing & Ads, Classifieds, Contracts, Travel, Delivery & Logistics, Finance, Content, Platform Settings, Support, Documentation) | Admin ✅ (IA will be widened to SRS groups) |
| `portal.` | Sell-with-us: join chooser, landing, advertise, registration wizards, legal, knowledge hub | ⬜ full pages (website-style) |
| `partner.` | **Seller Center** (product vendor) / **Marketplace** (classified seller); nav driven by `vendor.type` | Seller Center ✅, Marketplace ✅ |
| `marketer.` | **Creator Studio** (influencer/affiliate) / **Broker** workspace; nav driven by job types | Creator Studio ✅, Broker ✅ |
| `travel-agency.` | Travel Partner | ✅ |
| `carrier.` | Shipping company + supervisor dispatch | Shipping ✅, Ops ✅ |
| `delivery.` | Delivery agent web panel | ⬜ shell to add |
| root `/{cc}-{lang}` | Next.js storefront | in progress |

Mobile apps (repo folders):
- `customer_app`: **full**
- `delivery_app`: **full**
- `vendor_app` (old partner_app), `marketer_app`, `supervisor_app` (old carrier_app), `travel_agency_app`: **minimal ops**

---

## 6. Architecture decisions (ADR summary)
1. **Modular monolith.** Laravel 13 with `app/Domains/*` and `app/Shared/*`. Each domain holds Models, Services, Actions, Calculators, StateMachines, DTOs, Enums, Events, Jobs, Policies, and Http (Requests, Resources).
2. **Country isolation.** Central DB `nawy_central` holds identity, memberships, countries, domains, gateway credentials (encrypted), settings, contract templates (with a country column) and central audit. Country DBs (`nawy_uae`, `nawy_omn`, `nawy_ksa`, `nawy_egy`) hold all operational data. `CountryContext` switches the DB connection, cache prefix, queue and storage prefix. Cross-country reporting goes through a nightly ETL into `nawy_reporting`.
3. **Money.** `DECIMAL(19,4)` and a `Money` value object (brick/money).
   - A **rounding policy table** per calculator: half-up to the currency's minor unit (OMR has 3 decimals). It replaces the old FLOOR/ROUND mix.
   - Amounts are never summed across currencies.
   - Every fee, commission, settlement term and influencer fixed amount is snapshotted.
4. **Ledger.** Double-entry `ledger_transactions` and `ledger_entries` with idempotency keys. Wallets (owner × currency, with pending / spendable / reserved / frozen buckets) are projections. `wallet_transactions` are append-only.
5. **Auth.** Guards per panel, all on central `users` plus memberships. Sanctum for the storefront and apps. Devices, sessions and login history. Signed URLs for WhatsApp invitation responses.
   - **Customers sign in and register by EMAIL (user decision 2026-11-14).**
     - Email + password, or a passwordless 6-digit **email code** (10-minute expiry, 5 attempts then a 15-minute lock, throttled).
     - Google and Apple sign-in.
     - A new account must verify its email before checkout.
   - The **mobile number is optional** and is used only for delivery updates and courier contact. It is never a login identifier for customers.
   - Business accounts (vendors, marketers, agencies, carriers, agents) keep email + password. Phone/WhatsApp OTP remains available for delivery-agent device login and for marketer WhatsApp invitation links.
6. **Payments.** `PaymentGateway` contract with adapters for Thawani, Stripe, PayPal, PayTabs, Tabby and Tamara, plus internal methods (wallet, COD, gift card).
   - `country_gateway_configs` holds: enabled, mode, credentials, currencies, min/max amount, sort order, `is_recommended`, and fee rule.
   - `GET /api/v1/checkout/payment-methods` returns only what is valid for the current country, currency and amount.
   - Payments are confirmed only by webhook or by server-side verification. Unique `payment_webhook_events`.
   - Seed: Oman → **Thawani (recommended)**, PayTabs, Stripe, PayPal; UAE → Stripe★, PayTabs, PayPal (+ Tabby/Tamara); KSA → PayTabs★, Stripe, PayPal (+ Tabby/Tamara); Egypt → PayTabs★, Stripe.
7. **State machines.** One class per lifecycle, with transition guards. Every transition writes history with the actor. Lifecycles: listing, order, sub-order, return, refund, dispute, warranty claim, review, payout, campaign, invitation, conversion, sample, classified, package, booking, inquiry, inbound, transfer, packaging order, ad campaign, ad booking, flash-sale event and submission, coupon invitation, contract template, subscription, ticket, stream.
8. **Contract engine.** Templates (editor or file, versions, scheduled publish, notice and click-wrap). Resolvers per kind. Signatures with evidence (frozen copy, SHA-256, IP, UA, method). Private disk. The resolver never falls back to another country.
9. **Search.** `SearchEngine` contract: MySQL fulltext first, Meilisearch per-country indexes later. `SponsoredRanker` is a separate service.
10. **Real-time and messaging.** Reverb (notifications, chat, dispatch map, stream signalling). Queue on Redis via Horizon. Notification channels: DB, mail, broadcast, FCM, WhatsApp, SMS.
11. **Live media.** `LiveMediaProvider` adapter → LiveKit (SFU, recording). Recorded channels with `synced` or `free` playback stay. coturn is still required.
    - **Admin-only ownership (decided 2026-11-14).** Live streams, recorded channels and all videos (shoppable videos, replays, brand stories, sponsored video creatives) are created, uploaded, scheduled and published **only in the admin panel** (Admin → Content → Live & videos, country-scoped, times in country local time with the Oman label).
    - Vendors, classified sellers, influencers, affiliates and brokers **cannot** upload videos or start a stream. No partner/marketer panel has a Live or Videos menu.
    - Admin can **tag** a stream or video with a guest host (creator), a store or brand, and pinned products. Tagged items then appear on that creator's boutique, store or brand page as "featured by Nawy".
    - Sponsored video ads: the advertiser books the slot; admin uploads and approves the creative on the ad booking.
    - Exception: product and classified-ad media galleries (photos and a short listing video) stay with the listing owner and go through normal listing moderation.
12. **Panels UI.** Blade, Tailwind 4, vanilla JS modules, Select2, Yajra DataTables, Chart.js, Flatpickr, SortableJS. `<x-ui.*>` components mirror Figma. No Livewire, no React.
13. **Storefront.** Next.js 16 App Router, `[market]` segment (`uae-en`), SSR/ISR, next-intl RTL, page-builder renderer (`features/home/blocks/*`), typed client generated from OpenAPI.

14. **Time & timezones (user decision).**
    - Store everything in UTC. Each country has its own timezone (UAE Asia/Dubai, Oman Asia/Muscat, KSA Asia/Riyadh, Egypt Africa/Cairo with DST).
    - **The admin panel displays Oman time (Asia/Muscat).**
    - Country-scoped date-times (flash sales, schedules, coupons, campaigns, ad bookings, streams) are entered in the **country's local time**. A small label shows the **Oman equivalent**, e.g. "Egypt 10:00 PM (Nov, UTC+2) → = 12:00 AM next day Oman time".
    - Implemented by `CountryClock` and the `<x-ui.datetime-input>` / `<x-ui.datetime>` components. Spec: `docs/project/timezones.md`.

---

## 7. Domain modules (backend)
`Countries, Identity, Customers, Catalog, Listings, Inventory, Warehouses (FBN), Packaging, Cart, Checkout, Payments, Orders, Returns, Refunds, Disputes, Warranty, Reviews, Shipping, Delivery, Carriers, Finance (Ledger, Wallets, Payouts, Settlements, Subscriptions, AcquisitionCommissions), Coupons, CouponParticipation, FlashSales, Banners, Advertising (AdCampaigns, AdSlots, AdBookings, SponsoredRanking), GiftCards, Vouchers, Marketers (Profiles, Jobs, Rules, Contracts), Campaigns (Wizard, Invitations, Samples, Fees, Budget, Attribution, Conversions, CrossPromotion), Classifieds, RealEstate (Properties, Leads), Travel, Contracts, Documents, Content (PageBuilder, AppContexts, Blog, KnowledgeHub, HelpCenter, FAQs, StaticPages, Footer), LiveMedia, Support, Notifications, Analytics, AI, Portal (Funnel, AdvertiseInquiries)`

---

## 8. Marketers & campaigns (critical rules carried over)
- **Pay models:**
  - Influencer: a fixed amount per campaign, settled at `done`.
  - Affiliate: percentage × basis per sale.
  - Broker: fixed + percentage per sale, plus special requests.
  - Effective type is resolved per invitation (SRS §11.1).
- **Commission basis:** product line price excluding VAT, after discounts. Classified: the sold price. Travel: the booking total.
- **Origins A–F, wizard, statuses:** as in SRS §11.3–11.5. Auto-approve after 36 h. Budget must be strictly greater than the influencer fixed total. An unlimited budget requires an end date. Minimum stock rule. Samples plan.
- **Fees:** campaign fee = fixed + variable per country × category × fulfilment type. Charged when the campaign goes live. A pending fee is voided on reject or cancel. Commission fee = % × gross + fixed, capped. Resolution order: profile category → profile vertical → platform default.
- **Invitations:** 12 h timeout, fresh 10-char code, `/r/{code}` link and QR. Signed WhatsApp accept/reject. Auto replacement by fairness ordering. Invite-more.
- **Attribution:** direct listing credit → configurable model (`last_click` default; **first_click and linear implemented**, fixes gap 1). Window 7 days. Referral validity matrix with grace days. Non-product verticals are credited only for clicks on the same item.
- **Conversions:** idempotent per source. `pending → approved` by job → pending balance → spendable after clearing days. Reversal on cancel or return. **Clawback of paid conversions** as a negative wallet balance or recovery entry (fixes gap 5).
- **Cross-promotion engine (N):** admin-defined policies per country:
  - eligibility, account age, profile completion, verification
  - categories, minimum products, participation windows, obligations (e.g. 1 peer promotion per owner per month)
  - commission allocation, fraud rules, penalties, exemptions, suspend/reinstate
  - Peer promotion is one policy type inside it. A participation dashboard per marketer.

---

## 9. Contracts & documents
- **Kinds:** `seller_platform` (Seller Agreement), `seller_category`, `seller_marketer` (collaboration, including open-call), `customer_purchase` (purchase or rental), `marketer_onboarding` (optionally required for customers), `exclusive`, plus **new**: `travel_agency`, `broker`, `shipping_company` (all registrations use the engine).
- **Documents** per country and type (mandatory / optional / not applicable): vendor docs, product certifications, marketer IDs, agent docs, classified attachments, package contracts, transfer proofs.
- **Mobile click-wrap enforced** in the vendor app (fixes gap 4).

---

## 10. Finance (formulas centralised in calculators)
- **Vendor payout net** = price − category commission − fees − subsidy share − FBN storage and outbound fees − pending campaign fees − sample commissions (`SettlementCalculator`). Schedules: weekly, biweekly or monthly. Admin approve → process. COD sub-orders are eligible only after remittance.
- **Marketer-as-seller settlement** (daily 03:20): gross − coupon − category commission − VAT on commission − gateway share − peer promoter commission − shipping contribution − refunds.
- **Delivery agent payouts.** **Acquisition commissions** (monthly band). **Subscriptions** (trialing → active → past_due → cancelled | expired).
- **Campaign fee refund policy** becomes a configurable setting (default: no refund after go-live, matching current behaviour; fixes gap 6 by making it explicit).

---

## 11. Scheduled jobs (carried over, Laravel scheduler + Horizon)
- **Every minute:** stock-lock release, recorded-stream sync.
- **Every 5 minutes:** flash-sale transitions, banner, page and blog schedulers, paid-ad scheduler, pending-payment expiry.
- **Every 15 minutes:** SLA breach, coupon invitation close, promotion expiry.
- **Hourly:** campaign stock monitor, ad-package expiry, contracts apply-effective, analytics cache.
- **Daily:** ad budget reset 00:00, coupon / gift card / exclusive-contract expiry 00:15 / 00:30 / 00:45, FBN overage 01:00 and flag 01:15, auto-complete 02:00, warranty expiry 03:00, approve conversions 03:15, settle marketer sales 03:20, release commissions 03:30, complete bookings 03:45, end campaigns 04:00, analytics prune 04:30, COD settlements 23:30.
- **Weekly / monthly:** vendor payouts, FBN inbound reminder, bestseller ranking, acquisition commissions, FBN storage invoices.
- **Delayed:** campaign auto-approve, invitation timeout.

Every job must be idempotent and country-aware (it runs for each country connection).

---

## 12. Data model additions (country DB) beyond v1
`product_requests, product_certifications, size_guides, admin_listings, marketer_listings, inventory_movements, cart_inventory_locks, warranty_plans, warranty_purchases, warranty_claims(+messages), disputes(+messages), fbn_inbounds, inventory_transfers, storage_fee_daily, storage_fee_invoices, packaging_items, packaging_orders, exceptional_zones, shipping_subsidies, delivery_assignments, cod_settlements, carrier_claims, carrier_scores, marketer_profiles, marketer_job_types, marketer_rules, marketer_campaigns, campaign_invitations, referral_clicks, conversions, campaign_samples, campaign_fees, cross_promo_policies, cross_promo_participations, special_requests, exclusive_contracts, ad_packages(+subscriptions), ad_slots, ad_bookings, ad_campaigns, coupon_participation_invitations(+requests), cart_card_offers, contract_templates(+versions, assignments) [central], vendor_contracts, marketer_seller_contracts, customer_contracts, marketer_contract_acceptances, document_types [central], vendor_documents, change_requests, acquisition_commission_rules(+payouts), subscriptions(+plans, invoices), streams(+comments, likes), app_contexts, pages(+versions, blocks), portal_funnel_events, advertise_inquiries, ai_jobs, ai_credits, conversations(+messages)`

---

## 13. Fixes for the old system's known gaps (SRS §19)
| # | Gap | Fix in v2 |
|---|---|---|
| 1 | Only direct and last-click attribution | `AttributionModel` strategy: last_click, first_click, linear; set per country or campaign |
| 2 | Outdated campaign audit doc | New docs generated from code and kept in `docs/` |
| 3 | Retired features still in docs | Dropped |
| 4 | No mobile click-wrap | Vendor app blocks on a pending mandatory agreement (API `consent_required` flag) |
| 5 | Paid conversions not clawed back | Clawback entry: negative pending, then recovery from the next earnings, ledger-mirrored |
| 6 | Paid campaign fee never refunded | Configurable refund policy with an audit trail |
| 7 | P2P live-stream scale | LiveKit SFU |
| 8 | Marketer and travel registration on the old contract flow | Unified contract engine for every registration |

---

## 14. Design plan (Figma)
One file holds the design system, panels, website and apps (pages are grouped).

**Website.** Done:
- Home v2 (Noon structure, 21 blocks)
- PLP, PDP, Cart, Checkout, mobile Home
- Login, OTP, Register
- Account Overview, Orders

Remaining:
- Order detail and tracking (with delivery OTP), Returns, Disputes, Warranty claims and my warranties, Bookings, My contracts, Chats with marketers, Special requests, Tickets, Notifications, Payments and saved cards, Wallet ("Nawy Credits"), Gift cards, Vouchers, QR code, Security and devices, Addresses, Profile
- Search results, brand, deals, flash sale, campaign landing
- Payment failed, order confirmation, empty states
- Classifieds (home, section, find, detail with map, inquiry, contract acceptance)
- Travel (home, detail, booking, inquiry)
- Marketers directory and profile, referral landing
- Real estate (results, detail, broker), gift cards, live streams, static, help, 404
- AR/RTL key pages

**Portal (sell-with-us)**, all pages: join chooser, landings, advertise pages, registration wizards (vendor 4-step + documents + agreement; travel; marketer; broker; shipping), legal, knowledge hub.

**Panels.** Layouts and components only:
- Widen the Admin IA to the SRS groups.
- Add the Delivery-agent shell.
- New kit components: state timeline, contract signature box, campaign wizard stepper, budget meter, fee quote card, referral card (code, QR, link), sample tracker, map panel.

**Apps.**
- Customer app: full.
- Delivery app: full (assignments, OTP display, pickup, POD photo, signature, failed reasons, reschedule, return-to-origin, COD, earnings, wallet).
- Minimal ops: vendor (with click-wrap), marketer, supervisor, travel.

**Canva.** Minimal: only what the 8 existing images cannot cover, with approval each time.

---

## 14a. Page builder (Noon-grade) — all components
Source: live audit of 7 Noon page types and 17 widget types (`docs/reference-research/noon-page-builder-audit.md`). Full spec: **`docs/design/page-builder-blocks.md` (registry v2)**.

**Page templates**:
- `home`
- `store_landing` (category root, widgets only)
- **`category`**: filter sidebar + widget zone `top` + `listing` zone with sponsored interleave + `bottom`. It mirrors Noon `/beauty`, `/fashion-women`, `/supermarket`. **If the category has children, the header gets `category_header` + `subcategory_tiles` (image tiles with counts) and the listing gets a sticky `subcategory_chips` bar.**
- `campaign`: banner strips + listing
- `brand_store`, `seller_store`, `marketer_store`
- `search` (system)
- `vertical_landing` (classifieds, travel, real estate)
- `app_context` (mobile)

Every page exists per country × locale, with schedule, revisions and copy.

**51 blocks in 10 groups** (A–H below, plus I sub-category navigation: subcategory_tiles, subcategory_hub, subcategory_chips; and J page headers: category_header, brand_header, campaign_hero):
| Group | Blocks |
|---|---|
| Hero & sliders | hero_carousel, hero_bento, banner_slider, banner_scroller |
| Banners & grids | promo_banner (single, 2-up, 1+2×2, stacked), banner_grid (n_up, showcase_1x7, brand_tiles, quick_links, segment_tabs, wide, 2×2), banner_strip (with hotspots), banner_full (organic or sponsored), countdown_strip |
| Category & brand nav | tile_scroller (1–2 rows, circle/square, offer captions, stickers), category_tiles, category_showcase, brand_carousel, segment_tabs, quick_links |
| Products & deals | product_carousel (1–2 rows, sponsored slots), product_grid, item_carousel, mega_deals, hero_offer_list, frenzy_deals, deal_grid, offer_cards, tile_grid |
| Layout | panel_row, section_band |
| Sponsored (ads) | sponsored_brand_banner, sponsored_product, sponsored_brand_video, sponsored_video, ad_slot. Always labelled; filled by SponsoredRanker from ad campaigns, slots and bookings. |
| Listing zone | product_listing: 15 filter groups, quick-filter chips, sort, 4–5 column grid, infinite scroll, interleave rules |
| Content & marketplace | verticals, creators_carousel, classified_carousel, travel_packages_carousel, properties_carousel, video_block, live_streams_strip, html, seo_text, popular_searches, app_download, newsletter_signup, vendor_spotlight |

**Deliverables:**
- **Figma:** page "Page builder · Components" with every block and variant (52 components). Templates built: Category v2, **Category with sub-categories**, **Store landing**, **Campaign**, **Brand store**.
- **Admin:** builder with a block library grouped as above, zones per template, and an inspector per block schema.
- **Backend:** `Content\PageBuilder` registry + `BlockResolver` per type + JSON schemas.
- **Next.js and Flutter:** one renderer per block type.

---

## 14b. Marketplace & creator pages — OpenSooq / Boksha / Boutiqaat references
Audits: `docs/reference-research/opensooq-audit.md`, `boksha-audit.md`, `boutiqaat-audit.md`.
**Layout rule (user decision): every one of these pages uses the current Nawy layout** (same header, category bar, footer, filter sidebar, cards, tokens and page-builder blocks). The references give features and content, not visual layout.

| Area | Pages (templates) | Key features (from references + SRS) |
|---|---|---|
| **Classifieds (OpenSooq)** | classified home (`vertical_landing`), section/results (`category` with classified listing), **ad detail**, **post-ad wizard** (contract → category → attributes → photos/video → location → price → preview → boost), seller/shop page (`seller_store`), wanted ads, saved searches, compare | 15 verticals; dynamic attribute filters (about 25 for cars) + verified user/business, with photos/video, shop ads; save search + alerts; card shows image count, key attributes, city, time, verified badge, Chat, Compare; detail has attributes, map, ad ID, seller box (member since, rating, response time, other ads, shop), phone reveal (logged), chat, safety tips, report, similar ads; owner upsells (feature, republish, boost) |
| **Modest fashion & size guides (Boksha)** | modest-fashion vertical (abayas, jalabiyas, kaftans) on the `category`/`store_landing` templates; designer shops (`seller_store`); PDP size-guide drawer | Size-guide template engine (`apparel_alpha`, `abaya_height_length`: height cm/ft → length and sleeve in inches, `shoes`, custom; unit switch); **made-to-order** fulfilment (lead time, per-category measurement form, note to designer, stored on the order item, return-policy override); "Find my size"; ready-to-ship vs made-to-order badge |
| **Creators: influencer / affiliate / broker (Boutiqaat)** | creator directory `/creators`; **creator boutique** `/creator/{slug}` (`marketer_store`); affiliate page; **broker profile** `/broker/{slug}`; **Nawy TV** `/tv` | Boutique header (cover, avatar, verified, followers, socials, Follow, referral code copy); tabs Picks · Own products · Videos · Reviews; Exclusive badge + creator code; boutique picks carousels on home and category pages; shoppable videos and live replays **curated by admin** (creators are tagged as guests; they never upload); broker header with licence no., rating, response time, lead form with contract gate, special-request CTA |

**New blocks for the registry:**
- `classified_results` (listing variant with classified cards and attribute filters)
- `classified_ad_card`
- `seller_box`
- `safety_tips`
- `saved_search_cta`
- `post_ad_cta`
- `size_guide_drawer`
- `measurement_form`
- `creator_directory_grid`
- `creator_boutique_header` (a `brand_header` variant)
- `boutique_picks_carousel`
- `shoppable_video_feed` (source: admin media library only)
- `broker_lead_form`

**Data additions:**
- `size_guide_templates` (+ columns)
- `product.fulfilment_mode = made_to_order` (+ lead_days)
- `category_measurement_fields`
- `order_item_measurements`
- `saved_searches` (+ alert channel)
- `listing_compare` (session)
- `phone_reveals` (log)
- `media_videos` (admin-owned; optional tags: guest creator, store, brand; + pinned products). Replaces `creator_videos`.
- `stream_guests` (stream ↔ creator tag, admin-assigned)
- `creator_follows`
- `seller_follows`

**Figma to-do (M4):**
- classified home, results, ad detail, post-ad wizard, shop page
- modest-fashion category, PDP with size guide drawer and measurement form
- creator directory, creator boutique, broker profile, Nawy TV
- mobile variants

---

## 15. Roadmap (milestones)
| # | Milestone | Exit criteria |
|---|---|---|
| M0 | Env + plan v1 | ✅ |
| M1 | **Plan v2 + parity matrix** | ✅ this file |
| M2 | Discovery docs: PRD, role-permission matrix (per guard), lifecycle catalogue (all state machines), settings catalogue, risk register | docs/project complete |
| M3 | Figma A (design system & panels) | ✅ incl. Admin v2 IA, delivery shell, kit v2, Live & videos template |
| M4 | Figma B (website + portal) | ✅ (see docs/design/design-coverage.md) |
| M5 | Figma C (apps) | ✅ customer 18 + 3 RTL, delivery 10, ops 4 |
| M6 | Laravel foundation: central/country DBs + provisioning, CountryContext, identity and memberships, guards, Spatie teams, panel shells + `<x-ui>` kit, API envelope, audit, Horizon, Reverb, settings service, scheduler skeleton | Isolation tests green |
| M7 | Catalog, listings, inventory, warehouses/FBN, cart, checkout, orders and state machines, shipping calculation, coupons, flash sales | Purchase E2E (single and multi-seller) |
| M8 | Payments (6 adapters, per-country), ledger, wallets, refunds, payouts, settlements, subscriptions, gift cards, vouchers | Ledger integrity + duplicate-webhook tests |
| M9 | Marketers & campaigns (full §8), contracts engine (§9), documents | Campaign E2E for origins A–F, attribution, conversions, clawback |
| M10 | Classifieds, real estate/brokers, travel (capacity locking), advertising (ad campaigns, slots, bookings, sponsored ranking), coupon participation | Vertical E2Es |
| M11 | Delivery, carriers, COD, claims, scorecard; live media (LiveKit + recorded channels); page builder + app contexts + CMS | Delivery E2E, scheduled media E2E |
| M12 | Portal (sell-with-us) + all panel pages | Role tests per panel |
| M13 | Next.js storefront (all pages, page-builder renderer, RTL) | Web E2E suite |
| M14 | Flutter apps | App tests |
| M15 | **Data migration cutover** (MG9: 2 dry runs, reconciliation 100%, UAT, freeze → migrate → DNS switch, rollback ready) + QA hardening, security, performance, deployment docs | Zero unexplained reconciliation differences; signed off by BA, Finance and QC |

Migration tasks MG1–MG8 run **inside** M6–M13: each domain ships with its migration step and reconciliation test (data-migration-plan §11).

Build order inside each module: migration → model → calculator/service → state machine → policy → API → panel screen → storefront/app → **legacy migration step + reconciliation test** → tests → docs.

---

## 16. Testing
- **Parity suite:** one feature test per SRS lifecycle and rule (FR-CAT, LST, CHK, MKT, CLS, LS…), plus every scheduled job (time-travel tests).
- **Spec E2E 1–18** (project_docs §21.5), plus campaign origins A–F, peer and cross-promotion, contract gates (click-wrap, customer purchase contract), COD unlock of payout, FBN storage-fee deduction.
- **Isolation tests:** no cross-country reads. **Money tests:** rounding per currency (OMR 3dp), never mixing currencies.
- **Playwright per panel; Vitest + Playwright for Next.js; Flutter widget and integration tests.**

---

## 17. Risks & open decisions
| # | Item | Proposal / question |
|---|---|---|
| D1 | **Migrate data from the old live Nawy DB** | ✅ **Approved (2026-11-14).** Plan: `docs/project/data-migration-plan.md`. Source `/var/www/marketplace` @ c0fe74c1, DB `marketplace_platform_live`, 356 tables (inventory: `docs/project/migration/old-table-inventory.csv`). Snapshot-only and re-runnable ETL (MG1–MG9) attached to M6–M15. Owner questions: plan §12. |
| D2 | Launch countries | UAE, Oman, KSA, Egypt seeded (old system also supports KWD, QAR, BHD, JOD, which can be added through provisioning) |
| D3 | Subdomain pattern | `{panel}.{base}` with an in-panel country switch (like the old system) vs `{panel}.{cc}.{base}`. Default: the former; configurable. |
| D4 | BNPL providers | Tabby and Tamara stay (they exist in the old system) |
| D5 | Campaign fee refund default | Keep "no refund after live", configurable |
| R1 | Scope size (parity + new) | Milestone gates; parity first, new features second inside each module |
| R2 | DB per country makes reports and migration harder | Reporting ETL; migration scripts per country |
| R3 | Canva only exposes 200px previews to the API | Owner downloads the originals once |
| R4 | Gateway sandbox keys | Fake drivers until keys are provided |

---

## 18. Status tracker
| Milestone | Status |
|---|---|
| M0 Env & plan v1 | ✅ |
| M1 Plan v2 & parity matrix | ✅ |
| M2 Discovery docs | 🟡 (Noon homepage audit, **Noon page-types & widget audit, block registry v2 (51 blocks, 10 groups)**, data-migration plan + old table inventory done) |
| M3 Figma A | ✅ (2026-11-14) |
| M4 Figma B | ✅ website, portal, verticals (2026-11-14) |
| M5 Figma C | ✅ customer, delivery, ops apps (2026-11-14) |
| M6–M15 | ⬜ |
