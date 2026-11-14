# Nawy (ناوي) — Product Requirements Document (PRD)
> M2 discovery set · v1.0 · 2026-11-14 · Owner: BA / PM
> Plan: `MASTER_PLAN.md` v3 (decisions are binding). Parity baseline: `old_project_overview.md` (SRS v1.0, cited as **SRS §x**). New scope: `project_docs.md` (master spec, cited as **Spec §x**). Portal baseline: `PORTAL_PAGES.md`.
> Companion files: `role-permission-matrix.md`, `lifecycle-catalogue.md`, `settings-catalogue.md`, `country-reference.md`, `risk-register.md`, `FEATURE_MATRIX.md`, `route-frame-map.md`.

---

## 0. How to read this document

| Convention | Meaning |
|---|---|
| **FR-XXX-n** in **bold** | Requirement ID reused **verbatim from the SRS** (e.g. FR-CAT-1, FR-CHK-4, FR-LS-8). Behaviour must match the SRS text. |
| FR-XXX-n (plain) | New ID created in this PRD. Numbering continues after the last SRS ID of the same prefix. |
| Parity tag | **K** keep · **I** keep and improve · **R** replace the mechanism · **N** new · **D** drop (MASTER_PLAN §3). |
| Milestone | Backend milestone that delivers the rule (MASTER_PLAN §15). Panels land in M12, web in M13, apps in M14 unless stated. |
| "Proposed" | The sources are silent; this PRD makes a documented, configurable product decision (Spec §25). To confirm at owner review. |

---

## 1. Product vision and goals

**Vision.** Rebuild the live Nawy Marketplace as one multi-country commerce and services super-platform: product commerce, classifieds (Open Souq), real estate, travel, creators/affiliates/brokers, logistics and finance — with full parity to the old system, the master-spec scope, and a Noon UAE–grade experience on web and in the app (MASTER_PLAN header, Spec §1).

| # | Goal | Measure of success (exit evidence) |
|---|---|---|
| G1 | **Parity.** Every panel, page, lifecycle, rule, setting and job of the old system exists unless MASTER_PLAN §3 drops it. | Parity suite: one test per SRS lifecycle, rule and job (MASTER_PLAN §16). |
| G2 | **8 countries live on day 1** with strict data isolation. | Isolation tests green for UAE, Oman, KSA, Egypt, Kuwait, Qatar, Bahrain, Jordan (M6 exit). |
| G3 | **Money integrity** across 8 currencies (4 with 3 decimals). | Ledger integrity, webhook idempotency and 3-decimal money tests (M8 exit). |
| G4 | **Noon-grade customer UX** on web (Next.js) and app (Flutter, Noon app structure). | Web E2E (M13) and app tests (M14); designs per `route-frame-map.md`. |
| G5 | **Lossless migration** of live data for all 8 countries. | Reconciliation 100% (M15 exit). |
| G6 | **New verticals and engines**: brokers/real estate, cross-promotion, creator selling, advertising ranking, LiveKit media, gateways. | Vertical E2Es (M10), campaign E2E A–F with clawback (M9). |

## 2. Users and personas

Actors and their role memberships come from MASTER_PLAN §2 and SRS §3. Full permissions: `role-permission-matrix.md`.

| Persona | Membership role | Workspace / channel | Primary jobs-to-be-done |
|---|---|---|---|
| Platform staff (super, operations, finance, marketing, vendor-relations, content, support) | `admin` + Spatie role | `admin.` | Run countries, approve, moderate, pay out, configure. |
| Account-manager admin | `admin` + restriction | `admin.` (variant) | Manage only assigned vendors; finance hidden. |
| Product vendor (owner, team) | `vendor:product` | `partner.` Seller Center, vendor app | List, fulfil, promote, get paid. |
| Classified seller (individual / business, Open Souq) | `vendor:classified` | `partner.` Marketplace | Post ads, answer inquiries, mark sold. |
| Property owner lister | `vendor:classified` (property vertical) | `partner.` Marketplace | Post property ads (Spec §5.7). |
| Influencer / Affiliate / Broker | `marketer:{influencer,affiliate,broker}` | `marketer.` Creator Studio / Broker, marketer app | Accept campaigns, share links, sell own products, handle leads. |
| Marketing agency | `marketer:agency` | `marketer.` with agency switcher | Manage several marketers (Spec §5.4). |
| Travel agency (owner, members) | `travel_agency` | `travel-agency.`, travel app | Publish trips, manage bookings and capacity. |
| Shipping company supervisor (owner / normal) | `carrier_supervisor` | `carrier.` Shipping + Ops, supervisor app | Run agents, dispatch, COD. |
| Delivery agent | `delivery_agent` | `delivery.` + delivery app | Pick up, deliver with code match, collect COD. |
| Customer (guest / registered) | `customer` | Storefront `/{market}` + customer app | Shop, book, inquire, follow creators. |
| Prospective partner (guest) | — | `business.` portal | Learn, choose a join path, register. |
| System (scheduler / queue) | — | — | Timed transitions, settlements (SRS §17). |

## 3. Scope summary by module

| # | Module | SRS § | Spec § | Parity | Milestone |
|---|---|---|---|---|---|
| 4.1 | Countries, locales, domains, calendars, tax | §2.3 | §4, §4.1 | I/N | M6 |
| 4.2 | Identity, auth, memberships, devices | §2.2, §16 | §17.1 | I | M6 |
| 4.3 | Catalog | §5.1 | §5.1 | K+N | M7 |
| 4.4 | Listings and inventory | §5.2–5.4 | §5.1, §5.5 | K | M7 |
| 4.5 | Cart and checkout | §6 | §5.1 | K | M7 |
| 4.6 | Payments | §6 | §13 | I | M8 |
| 4.7 | Orders, returns, refunds, disputes, warranty, reviews | §7 | §5.1 | K | M7 / M8 |
| 4.8 | Fulfilment, shipping, FBN, packaging | §8, §4.1.3 | §8 | K | M7 |
| 4.9 | Delivery and carriers | §4.6, §4.7, §8 | §8 | K+N | M11 |
| 4.10 | Finance (ledger, wallets, payouts, settlements, subscriptions, membership) | §9 | §6.3 | I | M8 |
| 4.11 | Platform marketing (coupons, flash sales, banners, gift cards, vouchers, card offers, newsletter, push) | §10 | §5.8, §6 | K | M7 / M8 |
| 4.12 | Advertising (ad campaigns, ad slots, bookings, packages, sponsored ranking) | §10, §11.13 | §7 | K+N | M10 |
| 4.13 | Marketers and campaigns | §11 | §5.4–5.6 | K+N | M9 |
| 4.14 | Classifieds (Open Souq) | §12.1 | §5.2 | K+N | M10 |
| 4.15 | Real estate and brokers | — | §5.7 | N | M10 |
| 4.16 | Travel | §12.2 | §5.3 | K+N | M10 |
| 4.17 | Contracts and documents | §13 | §11 | K | M9 |
| 4.18 | Live media | §14 | §9 | R | M11 |
| 4.19 | Content (page builder, app contexts, CMS, help, blog) | §15 | §3.1 B | I | M11 |
| 4.20 | Support, chat, notifications | §15, §11.13 | §17.2, §17.3 | K | M11 |
| 4.21 | Seller portal (`business.`) | §4.2 | §11 | K+N | M12 |
| 4.22 | Seller performance, vendor API, tools | §4.3 (Performance, API) | §12.2 | K | M7 / M12 |
| 4.23 | Search and discovery | §4.8 | §17.4 | I | M7 / M13 |
| 4.24 | Analytics, AI, reporting | §4.1.1 | §12.1 | K | M11 / M12 |
| 4.25 | Ops and system health | §4.1.14 (Activity Log) | §12.1 Admin & ops | N | M12 |
| 4.26 | Customer app (Noon structure), delivery app, ops apps | §4.9 | §14.4 | I | M14 |
| 4.27 | Data migration | — | §22 Phase 14 | N | M15 |

---

## 4. Functional requirements by module

### 4.1 Countries, locales and domains (MASTER_PLAN §4a, ADR 2, ADR 14)
**Goal.** Run 8 isolated country operations from one central control plane.

| ID | Requirement | Source | Tag |
|---|---|---|---|
| FR-CTY-1 | Provision 8 countries on day 1: UAE, Oman, KSA, Egypt, Kuwait, Qatar, Bahrain, Jordan. Each has its own operational DB `nawy_{code}` and two store markets (`{code}-en`, `{code}-ar`). | MP §4a, D2 | N |
| FR-CTY-2 | One `countries` row holds ISO2, portal/DB code, market slugs, currency and decimals, time zone, weekend, tax. Full data: `country-reference.md`. | MP §4a | N |
| FR-CTY-3 | `CountryContext` resolves the country from a trusted source (market slug, panel country switch, token membership) and switches DB connection, cache prefix, queue and storage prefix. Isolation must not rely on `country_id` filters alone. | ADR 2, Spec §4.1 | N |
| FR-CTY-4 | Storefront URLs are `/{market}` (e.g. `/uae-en`). Portal, help and ads sites use `/{code}/…` (e.g. `/omn/helpcenter`). URLs without a prefix redirect to the session country, else the first active country. | MP §4a, PORTAL_PAGES | K |
| FR-CTY-5 | Domains are `{panel}.{base}` with an in-panel country switch, configurable to `{panel}.{cc}.{base}` in the `domains` table. Portal host `business.{base}`. | MP §5, D3 | I |
| FR-CTY-6 | VAT/GST rates, weekends and holiday calendars are per-country **settings**, never code. `CountryClock` exposes them. Delivery ETA, SLA and payout schedules use them. | MP §4a, ADR 14 | N |
| FR-CTY-7 | All timestamps are stored in UTC. The admin panel displays Oman time (Asia/Muscat). Country date-times are entered in local time with an "= Oman time" helper label. Other panels show the account's country time. | ADR 14, `timezones.md` | N |
| FR-CTY-8 | Admin can launch or deactivate a country and set per-country payment methods, shipping and VAT (SRS §4.1.14 Countries). Adding a country must not require code changes. | SRS §4.1.14, Spec §4.1 | K |
| FR-CTY-9 | Cities CSV import and currency rates screens are kept. Amounts are never summed across currencies; rates are for display/reporting only. | SRS §4.1.14, §2.3 | K |
| FR-CTY-10 | A nightly ETL loads `nawy_reporting` for cross-country reports. | ADR 2 | N |

### 4.2 Identity, authentication and memberships (ADR 5, SRS §2.2, §16, Spec §17.1)
**Goal.** One central identity with role memberships per country; safe sign-in for every actor.

| ID | Requirement | Source | Tag |
|---|---|---|---|
| FR-IDN-1 | One central `users` identity with `role_memberships (user × role × country × status)`. A person may hold several approved roles (e.g. vendor + influencer). | MP §2, Spec §17.1 | I |
| FR-IDN-2 | **Customers sign in by email**: password or a 6-digit email code, or Google/Apple. Email must be verified before checkout. Phone is optional and used for delivery only. *(Deviation DV1 from Spec §17.1 phone+OTP.)* | ADR 5, DV1 | R |
| FR-IDN-3 | Business accounts (all panels) sign in with email + password, with optional 2FA (TOTP proposed). | ADR 5 | I |
| FR-IDN-4 | Every panel host has sign-in, forgot-password and reset-password pages. | MP §5 | K |
| FR-IDN-5 | Login history and device revoke for every actor (customer "Security Settings and Manage Devices", SRS §4.8; admin "Customers → revoke devices", SRS §4.1.5). | ADR 5 | K |
| FR-IDN-6 | Guards per panel on central users + memberships; Sanctum tokens for storefront and apps. Guest cart uses `X-Cart-Token`. | ADR 5, **FR-CHK-1** | I |
| FR-IDN-7 | Signed URLs let a marketer accept/reject a WhatsApp invitation without logging in. | ADR 5, SRS §11.7 | K |
| FR-IDN-8 | Credentials and passwords are emailed to newly created accounts (client requirement 9). Proposed: email a set-password link rather than a plain password. | SRS §15 | I |
| FR-IDN-9 | Admin can impersonate admins (SRS §4.1.5 Admins) and reset passwords; every impersonation is audited. | SRS §4.1.5 | K |
| FR-IDN-10 | Accounts can be suspended, banned, reactivated (customers) and suspended/blacklisted (vendors); suspended memberships cannot sign in to that workspace. | SRS §4.1.5 | K |
| FR-IDN-11 | Customer QR code (account) and admin "regenerate QR". | SRS §4.1.5, §4.8 | K |
| FR-IDN-12 | Ops apps share one sign-in with a role switch across the user's memberships. | MP §5, §14 | N |

### 4.3 Catalog (SRS §5.1, Spec §5.1)
**Goal.** One shared catalog that many sellers list against.

| ID | Requirement | Source | Tag |
|---|---|---|---|
| **FR-CAT-1** | A product has variants, attributes, images and per-country settings. Status: `draft → active → discontinued | restricted`. | SRS §5.1 | K |
| **FR-CAT-2** | A vendor or marketer can only list an existing product. Missing products go through **Product Requests** (`pending → approved | rejected | cancelled`). | SRS §5.1 | K |
| **FR-CAT-3** | Bestsellers are computed from `total_sold` by a rankings job. Product highlights pin products to featured spots. | SRS §5.1 | K |
| FR-CAT-4 | Barcode / GTIN on variants, unique per country catalog; SKU management. | MP §3, Spec §5.1 | N |
| FR-CAT-5 | Product Q&A: customers ask, sellers/admin answer, admin moderates. | MP §3, Spec §5.1 | N |
| FR-CAT-6 | Product comparison (up to 4 products, same leaf category, proposed). | MP §3, Spec §5.1 | N |
| FR-CAT-7 | Nested category tree. Per category: sale commission %, attributes, allowed shipping methods, campaign sample quantities (platform, influencer, affiliate), `min_stock_for_campaign`, individual-contract switch, Marketers tab (affiliate defaults), return window (MP §7a). | SRS §4.1.2, §20.2 | K |
| FR-CAT-8 | Brands (logos), attributes and values, custom pages / category landings, size guides (vendor-owned), warranty plans scoped to parent categories. | SRS §4.1.2, §4.3 | K |
| FR-CAT-9 | Product certifications: required to list certain products; vendor uploads, admin approves or rejects (ListingCertificationGate). | SRS §4.1.5, **FR-LST-1** | K |
| FR-CAT-10 | Product images and video; recently viewed; recommendations; frequently bought together. | Spec §5.1, §3.1 D | N |

### 4.4 Listings and inventory (SRS §5.2–5.4)
| ID | Requirement | Source | Tag |
|---|---|---|---|
| **FR-LST-1** | Before the first listing in a category or product that requires a contract, the vendor must sign it (ListingContractGate). Required product certifications must be approved (ListingCertificationGate). | SRS §5.2 | K |
| **FR-LST-2** | Fulfilment model: `fbm`, `fbn` or `cross_dock`. An FBN listing is orderable only while `quantity_available > 0` in a platform warehouse. | SRS §5.2 | K |
| **FR-LST-3** | `quantity_available` is a virtual column (on hand − reserved) and is never written directly. Every stock change writes an append-only `inventory_movement`. | SRS §5.2 | K |
| FR-LST-4 | Vendor listing lifecycle `draft → pending_review → active ⇄ paused`, `out_of_stock` (auto at stock 0), `rejected`, `archived`. See `lifecycle-catalogue.md` §1.1. | SRS §5.2 | K |
| FR-LST-5 | Platform ("Nawy Listings") offers sold by the platform: `active ⇄ paused`, `out_of_stock`, `archived`. Promotable through platform campaigns with no campaign fee. | SRS §5.3 | K |
| FR-LST-6 | Marketer listings of three kinds: campaign listing (auto on acceptance, carries referral code), independent listing (must bind to the cheapest active vendor or admin listing for the same variant and country, vendor preferred; refused if none), own FBN listing (influencer is seller of record, `source_listing_id = NULL`). Campaign listings pause/archive/resume with their campaign. | SRS §5.4 | K |
| FR-LST-7 | Ownership is explicit on every listing and sub-order: platform, vendor or marketer. Marketing commission and product-sale profit are separate balances. | Spec §5.5 | K |
| FR-LST-8 | Listing media (product photos and a short listing video) stays with the listing owner — the only exception to admin-only media. | ADR 11 | K |
| FR-LST-9 | Promo badges, condition, multiple warehouses per vendor; inventory screens with low-stock and out-of-stock views. | SRS §4.3, §4.4 | K |

### 4.5 Cart and checkout (SRS §6)
| ID | Requirement | Source | Tag |
|---|---|---|---|
| **FR-CHK-1** | Adding to the cart soft-locks inventory for 15 minutes (`cart_inventory_locks`, released every minute by a job). A guest cart uses `X-Cart-Token` and merges into the customer cart on login. | SRS §6 | K |
| **FR-CHK-2** | Calculation per currency: subtotal + shipping (zone, method, volumetric weight, slabs, surcharges) − exceptional-zone subsidy − coupons (at most 1 platform + 1 vendor) + COD fee + tax/VAT + warranty plan (+ gateway fee). At or above the free-shipping threshold, shipping is free and absorbed by the platform. Implemented once in `CheckoutTotalsCalculator`. | SRS §6, MP §3 | K |
| **FR-CHK-3** | Payment methods per country: card (gateway adapters), wallet, COD, BNPL (Tabby/Tamara), gift card. Order payment status `pending → authorized → captured | failed | refunded | partially_refunded`; pending payments expire (every 5 min). | SRS §6 | I |
| **FR-CHK-4** | Marketer contract gate: if a cart line comes from a marketer whose contract is *required* and has a published version, the customer accepts it before checkout. | SRS §6 | K |
| **FR-CHK-5** | Checkout creates one order, one sub-order per seller (vendor, platform or marketer) and N items. Settlement is at sub-order level. | SRS §6 | K |
| **FR-CHK-6** | Attribution is recorded per order item at checkout (SRS §11.8). | SRS §6 | K |
| FR-CHK-7 | Email-verification gate before checkout (DV1). | ADR 5 | N |
| FR-CHK-8 | Cart UX (Noon structure): free-delivery progress, shipments grouped by delivery type, save for later, coupon sheet enforcing 1 + 1, sticky summary; checkout with address map picker, delivery per shipment, per-country payment methods with the **recommended** gateway first, credits, 3-D Secure wait, payment failed with retry, order placed. | MP §14c, Spec §3.1 E | N |
| FR-CHK-9 | Cart card offers (pay with a specific card) are shown in the cart. | SRS §4.1.7 | K |
| FR-CHK-10 | Delivery date with cut-off countdown on PDP and checkout, computed from per-country weekends and holidays. | MP §14c, FR-CTY-6 | I |

### 4.6 Payments (ADR 6, Spec §13)
| ID | Requirement | Source | Tag |
|---|---|---|---|
| FR-PAY-1 | One `PaymentGateway` interface with adapters: Thawani, Stripe, PayPal, PayTabs, Tabby, Tamara (+ D6 local methods: KNET, BenefitPay, NAPS/QPay, eFAWATEERcom — pending decision). Internal methods: wallet, COD, gift card. | ADR 6, D6 | I |
| FR-PAY-2 | `country_gateway_configs`: enabled, mode (sandbox/production), encrypted credentials, currencies, min/max, sort, `is_recommended`, fee rule. Seeds per `country-reference.md`. | ADR 6 | I |
| FR-PAY-3 | A payment is successful only after webhook signature verification or server-side verification; never from a redirect. Webhooks are idempotent (duplicate delivery creates no second financial transaction). | ADR 6, Spec §13, E2E 16 | I |
| FR-PAY-4 | Thawani takes amounts in baisa inside its adapter only; all other code uses `brick/money` in the currency's minor unit. | SRS §6 | K |
| FR-PAY-5 | Admin "Payment Gateways": credentials, test connection, webhook logs; refunds and partial refunds where supported; failed-payment retry (E2E 15); reconciliation and gateway reports. | SRS §4.1.14, MP §10 | I |
| FR-PAY-6 | Saved cards are provider tokens only; no raw PAN/CVV stored. | Spec §19 | K |

### 4.7 Orders, returns, refunds, disputes, warranty, reviews (SRS §7)
| ID | Requirement | Source | Tag |
|---|---|---|---|
| FR-ORD-1 | Sub-order machine `placed → confirmed → processing → packed → shipped → out_for_delivery → delivered → completed`; `cancelled` (customer before shipped, vendor with score penalty, admin any stage); `delivered → returned → refunded`. History with actor; events `SubOrderShipped`, `SubOrderDelivered`, `SubOrderReturned`. | SRS §7.1 | K |
| FR-ORD-2 | Auto-complete daily at 02:00: delivered sub-orders become `completed` once the return window has passed; this unlocks vendor payout and marketer conversion approval. | SRS §7.1 | K |
| FR-ORD-3 | Master order status derived from sub-orders (roll-up). | SRS §7.2 | K |
| FR-ORD-4 | Cancellation releases inventory, starts a refund and reverses the marketer conversion (paid-out conversions go to clawback, FR-MKT-14). | SRS §7.3, MP §13 | I |
| FR-ORD-5 | Returns with inspection outcome deciding refund and restock per item; return wizard (4 steps) for customers. | SRS §7.4 | K |
| FR-ORD-6 | Refunds: card back to card (5–10 days), wallet instant, COD to customer wallet. | SRS §7.5 | K |
| FR-ORD-7 | Disputes with resolution `favor_customer / favor_seller / split / no_action`. | SRS §7.6 | K |
| FR-ORD-8 | Warranty: standard (vendor) and extended plans (bought at checkout, platform-covered, expiry daily 03:00); claims by repair/replace/refund with a three-party thread. Vendor sees claims only on own listings (admin-listing claims → 403). | SRS §7.7, §4.3 | K |
| FR-ORD-9 | Reviews `pending → published | flagged | auto_flagged | hidden | rejected`; vendor replies moderated by admin; ratings histogram, write review, my reviews. | SRS §7.8 | K |
| FR-ORD-10 | Admin can view/change status, assign shipping, mark shipped/delivered, force-cancel, refund, flag fraud, move a sub-order between states. | SRS §4.1.4 | K |
| FR-ORD-11 | Customer order detail with invoice (PDF), summary, cancel, tracking with the delivery code. | SRS §4.8, MP §14c | K |

### 4.8 Fulfilment, shipping, FBN and packaging (SRS §8, §4.1.3)
| ID | Requirement | Source | Tag |
|---|---|---|---|
| FR-SHP-1 | Shipping model: zones (groups of cities) → methods per zone → rates (base + per kg) → weight slabs → FBN surcharges. Billable weight = max(actual, volumetric). | SRS §8 | K |
| FR-SHP-2 | ETA skips **per-country weekends and holidays** (old system skipped Friday only). | MP §3, FR-CTY-6 | I |
| FR-SHP-3 | Exceptional zones / subsidy: vendor opts in per zone; customer sees free delivery; vendor share deducted from payout; platform share is a P&L cost. | SRS §8 | K |
| FR-SHP-4 | FBN: inbound `draft → submitted → approved → shipped → received / rejected`; free-storage clock from receipt (default 30 days); daily overage at 01:00, flag overstored unpaid at 01:15, monthly invoice deducted from payout. | SRS §8, §4.1.3 | K |
| FR-SHP-5 | Inventory transfers between warehouses; vendor storage limits; shipping surcharges per warehouse and per city. | SRS §4.1.3, §4.3 | K |
| FR-SHP-6 | Packaging catalog and vendor packaging orders (delivery fee never subsidised). | SRS §4.1.3, §4.3 | K |
| FR-SHP-7 | Weight calculator tool for vendors. | SRS §4.3 | K |
| FR-SHP-8 | Vendor zone alerts (admin settings). | SRS §4.1.14 | K |
| FR-SHP-9 | Shipments `label_created → picked_up → in_transit → out_for_delivery → delivered | failed | returned`, + reschedule and return-to-origin (N). | SRS §8, Spec §8.2 | K+N |

### 4.9 Delivery and carriers (SRS §4.6, §4.7, §8; ADR 15)
| ID | Requirement | Source | Tag |
|---|---|---|---|
| FR-DLV-1 | Assignment `assigned → accepted → picked_up → delivered | failed`, from vendor (FBM) or FBN warehouse; auto-assign or manual with a live map; SLA breach check every 15 min. | SRS §4.6, §8 | K |
| FR-DLV-2 | **Delivery code (parity, ADR 15, DV4).** A 4-digit code is generated per assignment and **shown to the agent** (app and web) and to the customer (app/email/order tracking). The customer reads their code aloud; the agent compares and taps **"Codes match"**. The agent never types the code. | SRS §4.6, ADR 15 | K |
| FR-DLV-3 | Proof photo or signature is required for COD and high-value items (threshold setting). | ADR 15 | N |
| FR-DLV-4 | Failed-delivery reasons, reschedule, return-to-origin workflow. | MP §3, Spec §8.2 | N |
| FR-DLV-5 | COD: agent collects; settlement generated daily at 23:30 (`pending → settled | disputed`); admin "mark settled" sets `cod_remittance_confirmed = true` and unlocks the vendor payout; vendor sees an amber notice until then. | SRS §8, §4.6 | K |
| FR-DLV-6 | Agents: availability toggle, documents verified by admin before activation, vehicle, live GPS, earnings, wallet and withdrawals. | SRS §4.6, §4.1.11 | K |
| FR-DLV-7 | Agents access only their assigned shipments' data. | Spec §8.2 | K |
| FR-DLV-8 | Carrier panel: agents (no cap), supervisors (only an owner-level supervisor creates supervisors), assignments/unassigned, reports. | SRS §4.7 | K |
| FR-DLV-9 | Carrier claims `submitted → under_review → approved / rejected → compensated`; carrier scorecard with trend. Multiple carriers per country; fallback rules. | SRS §4.1.11, Spec §8.1 | K |
| FR-DLV-10 | Shipping company panel adds (Spec §8.1/§12.7): service zones, pricing, pickup requests, exceptions, returns, COD reconciliation, settlements, invoices, users & permissions. | Spec §12.7 | N |
| FR-DLV-11 | Delivery agent payouts: generate → approve → process (per-delivery rates + bonuses). | SRS §9 | K |

### 4.10 Finance (SRS §9, MP §10, Spec §6.3)
| ID | Requirement | Source | Tag |
|---|---|---|---|
| FR-FIN-1 | Double-entry ledger with idempotency keys; every financial event writes balanced rows in one transaction group. No wallet balance changes without a ledger transaction. | ADR 4, SRS §9 | I |
| FR-FIN-2 | Wallets per owner × currency with balances **pending / spendable / reserved / frozen / promotional**, as projections of the ledger. Owners: customer, vendor, delivery agent, travel agency, marketer. Admin can adjust (with authorization) and freeze. | ADR 4, SRS §9 | I |
| FR-FIN-3 | Vendor payout: eligible sub-orders (completed, or delivered past return window; COD only once remitted) → statement on the vendor schedule (weekly Mon 02:00, biweekly 1st & 15th, monthly 1st) → admin approve → process; hold or recalculate. Net = price − category commission − fees − subsidy share − FBN fees − pending campaign fees − sample commissions (`SettlementCalculator`). Nothing pays out automatically. | SRS §9 | K |
| FR-FIN-4 | Marketer commission: approved → pending → (clearing days) → spendable → withdrawal (`pending → approved → processed | rejected`). | SRS §9, §11.11 | K |
| FR-FIN-5 | Marketer-as-seller settlement daily 03:20 (gross − coupon − category commission − VAT on commission − gateway share − peer promoter commission − shipping contribution − refunds). | SRS §9 | K |
| FR-FIN-6 | Acquisition commissions monthly (1st 02:00): rate within a monthly min/max sales band for a date range; "My Acquisition Commissions" for the admin. | SRS §4.1.5, §9 | K |
| FR-FIN-7 | Vendor subscriptions (`trialing → active → past_due → cancelled | expired`), plans priced per country, monthly invoices. | SRS §9 | K |
| FR-FIN-8 | Nawy One membership: price per country, billing, renewals. | MP §7a, §10 | N |
| FR-FIN-9 | Promotional credits with expiry (separate `promotional` balance). | MP §10 | N |
| FR-FIN-10 | Payment reconciliation and gateway reports; suspicious-transaction flags. | MP §10, Spec §19 | N |
| FR-FIN-11 | Money: `DECIMAL(19,4)` + brick/money; half-up to the currency minor unit (**3 dp for OMR, KWD, BHD, JOD**, 2 dp otherwise). Never sum across currencies; snapshot fees and terms. | ADR 3 | I |
| FR-FIN-12 | Admin finance screens: payouts, delivery payouts, transactions, ledger, COD settlements, financial reports (export), warranty purchases, wallets, subscriptions/plans/invoices. | SRS §4.1.12 | K |

### 4.11 Platform marketing (SRS §10, Spec §5.8, §6)
| ID | Requirement | Source | Tag |
|---|---|---|---|
| FR-PRM-1 | Coupons: percentage, fixed_amount, free_shipping, BOGO; scopes platform, vendor, category, product; eligibility rules (min order, max discount, usage limits, per-customer, first order, country, seller). At most 1 platform + 1 vendor coupon per order. Expired daily 00:15. Vendor creates own coupons. | SRS §10, Spec §5.8 | K |
| FR-PRM-2 | Coupon participation: admin opens a paid invitation (min fee, capacity, expiry); vendors and marketers request with fee ≥ min; admin approves (wallet charged) or confirms bank transfer → `paid`; reject refunds a paid fee. Expired invitations close every 15 min; fulfilled ones activate daily. | SRS §10 | K |
| FR-PRM-3 | Flash sales: event and submission machines (lifecycle catalogue §5); marketer invites with flash-sale bonus; prices revert at the end; configurable display names (Mega Deals, Daily Deals…); max per-customer quantity; countdowns. Times entered in country local time. | SRS §10, Spec §5.8 | K |
| FR-PRM-4 | Banners: placement-based, scheduled every 5 min, audience (gender, device), link type, duplicate and bulk actions. | SRS §10, §4.1.7 | K |
| FR-PRM-5 | Gift cards: admin and customer-purchased; fixed denominations and custom amounts; unique secure codes never shown in logs; recipient email delivery (scheduled); partial redemption; expiry daily 00:30; cancel, extend, adjust. | SRS §10, Spec §6.2 | K+N |
| FR-PRM-6 | Vouchers: single/multi-use; product, category, service, trip, seller, platform, country vouchers; expiry, redemption, cancellation, reporting. | SRS §4.1.7, Spec §6.1 | I |
| FR-PRM-7 | Newsletter (subscribers, campaigns) and push broadcasts. | SRS §10 | K |
| FR-PRM-8 | "Promotion expiry" job every 15 min (`promotion:expire-items`). | SRS §17 | K |

### 4.12 Advertising (SRS §10, Spec §7)
| ID | Requirement | Source | Tag |
|---|---|---|---|
| FR-ADV-1 | Self-serve ad campaigns (CPC/CPM; keyword, product, category targeting) for vendors; approve/reject/pause/resume; fraud alerts; daily budget reset 00:00. | SRS §10 | K |
| FR-ADV-2 | Nawi Ads slots: placement, dimensions, pricing (fixed daily/weekly/monthly, CPM, CPC), max concurrent bookings. | SRS §4.1.7 | K |
| FR-ADV-3 | Paid ad bookings by vendors **and marketers**: dates, availability calendar, quote, creative review, pay (wallet). **Sponsored video creatives are uploaded by admin on the booking** (ADR 11). | SRS §10, ADR 11 | K/R |
| FR-ADV-4 | Ad packages for marketers (wallet = active at once; bank transfer = pending until admin approves); hourly expiry. | SRS §11.13 | K |
| FR-ADV-5 | `SponsoredRanker`: configurable ranking service (not in SQL or UI); every paid result carries a "Sponsored" label. | MP §3, Spec §7 | N |
| FR-ADV-6 | Advertisers also include classified sellers, brokers and travel operators (Spec §7); placements on search, category, classified, property, travel, campaign and brand pages. | Spec §7 | N |
| FR-ADV-7 | Advertise inquiries from the portal request form appear in admin. | PORTAL_PAGES §8 | K |

### 4.13 Marketers and campaigns (SRS §11, MP §8, Spec §5.4–5.6)
| ID | Requirement | Source | Tag |
|---|---|---|---|
| **FR-MKT-1** | A marketer cannot accept an invitation unless `global_status = active` and they accepted the current onboarding contract version. | SRS §11.2 | K |
| **FR-MKT-2** | Admin configures per-marketer profile commission rules per category or vertical (influencer fixed, affiliate %, broker fixed + %, optional fee override); else platform defaults (Marketer Settings, country × vertical × category). | SRS §11.2 | K |
| FR-MKT-3 | Types and pay: influencer = one fixed amount per campaign settled at `done`; affiliate = % × basis per sale; broker = fixed + % per sale and special requests. Effective type resolved per invitation. Basis: product line excl. VAT after discounts; classified sold price; travel booking total. | SRS §11.1 | K |
| FR-MKT-4 | Origins A–F (vendor, travel agency, platform, marketer request, peer promotion, open-to-affiliates join) with their initial status and approver. | SRS §11.3 | K |
| FR-MKT-5 | Campaign wizard (same for all owners/verticals): marketers, open to affiliates, max budget (blank = unlimited → end date required; budget strictly > influencer fixed total), end date, ad types; validates source and **stock after samples ≥ `min_stock_for_campaign`**; plans samples; snapshots fee with live quote; reserves influencer amounts; collaboration contract signing; schedules auto-approve. | SRS §11.4 | K |
| FR-MKT-6 | Campaign lifecycle and availability rules (stock/classified/travel) per `lifecycle-catalogue.md` §5.1; hourly stock monitor; daily end-date completion at 04:00; done when budget is used up. | SRS §11.5 | K |
| FR-MKT-7 | Peer promotion rules (`assertPeerPromotionAllowed`): own FBN listing, both influencers, no self-promotion, active and stock ≥ min, one per (promoter, owner) per calendar month with row lock. Promoter earns per sale under profile rule; owner pays it. | SRS §11.6 | K |
| FR-MKT-8 | Invitations: 12 h timeout, fresh 10-char code, `/r/{code}`, QR, WhatsApp signed accept/reject, resend cooldown; automatic replacement with fairness ordering; invite more; marketer-originated request flow. | SRS §11.7 | K |
| FR-MKT-9 | Attribution: direct listing credit → configurable model **last_click (default), first_click, linear** (fixes SRS §19.1); window 7 days; referral validity matrix with grace days; non-product verticals credited only for clicks on the same item. | SRS §11.8, MP §8, §13 | I |
| FR-MKT-10 | Samples: platform, influencer and affiliate samples; address, dispatch, receipt; zero-priced commission line on the seller statement. | SRS §11.9 | K |
| FR-MKT-11 | Campaign fee formula (fixed + variable per country × category × FBM/FBN/FBP; classified/travel per country × vertical), charged at go-live from wallet else pending; vendor pending → payout line; agency pending → nightly retry. Refund after live governed by setting `campaign_fee_refund_policy` (default: no refund — D5). | SRS §11.10, D5 | I |
| FR-MKT-12 | Commission fee = % × gross + fixed (capped at gross), resolved profile category → profile vertical → platform default; influencer fixed snapshot → reserve → apply → release → settle. | SRS §11.10 | K |
| FR-MKT-13 | Conversions idempotent per source; approval job 03:15 per type; release after clearing days 03:30 with ledger group; reversal on cancel/return/booking cancel/un-sold. | SRS §11.11 | K |
| FR-MKT-14 | **Clawback** of already-paid conversions as a recovery entry / negative balance, with recovery job. | MP §13 (SRS §19.5) | N |
| FR-MKT-15 | **Cross-promotion engine**: admin-defined policies per country (eligibility, account age, profile completion, verification, categories, minimum products, windows, obligations, commission allocation, fraud rules, penalties, exemptions, manual approvals, suspend/reinstate); peer promotion is one policy type; participation dashboard per marketer; disableable per country; weekly/monthly obligation evaluation. | Spec §5.6, MP §8 | N |
| FR-MKT-16 | Campaign marketplace with applications; content submission and approval; performance rankings; coupon-ID attribution and marketer coupons. | MP §8, Spec §5.4 | N |
| FR-MKT-17 | Marketing-agency accounts managing several marketers (agency switcher). | MP §2, §8 | N |
| FR-MKT-18 | Customer chat (marketer ↔ customer, real time); special requests matched to brokers with atomic `open → in_progress` claim; exclusive contracts (listing beats category; expiry 00:45); public directory; celebrity monthly report. | SRS §11.13 | K |
| FR-MKT-19 | Marketer media library is **admin-curated**: marketers browse and share links only (DV3). | ADR 11 | R |
| FR-MKT-20 | Admin Marketer Settings grids, Marketer Jobs, Campaigns Revenue (financials), Marketer Listings review queue. | SRS §4.1.6 | K |
| FR-MKT-21 | Fraud prevention: self-referral blocks, duplicate-commission guards, click fraud signals, suspicious conversions flagged. | Spec §5.4 | N |

### 4.14 Classifieds — Open Souq (SRS §12.1, Spec §5.2)
| ID | Requirement | Source | Tag |
|---|---|---|---|
| **FR-CLS-1** | Digital contract signing happens before submission, not after approval. | SRS §12.1 | K |
| **FR-CLS-2** | A listing is never public before admin approval. | SRS §12.1 | K |
| **FR-CLS-3** | Categories are dynamic, each with an optional dedicated website section. Seller contracts and customer purchase contracts are assigned per category and country; a section inherits from its parent. | SRS §12.1 | K |
| **FR-CLS-4** | Inquiries `new → contacted → closed`. Guests can send them (throttled 5/min). If a customer purchase contract applies, the action is refused with `customer_contract_required` until accepted. | SRS §12.1 | K |
| **FR-CLS-5** | Map view (lat/lng, GeoJSON), wanted listings, exclusive marketers. | SRS §12.1 | K |
| **FR-CLS-6** | Attachments are verified by the admin. | SRS §12.1 | K |
| FR-CLS-7 | Listing expiry and renewal with reminders (job); paid boosting / featured listings. | MP §11, Spec §5.2 | N |
| FR-CLS-8 | Saved searches with alerts (hourly), favourites, compare ads. | MP §7, §11 | N |
| FR-CLS-9 | Abuse reporting (`classified_reports`) with moderation; fraud signals. | MP §12, Spec §5.2 | N |
| FR-CLS-10 | Mark sold with final price, choosing the referring affiliate/broker (with inquiry counts) and buyer note; campaign completion and conversion rules per SRS §11.12. | SRS §11.12 | K |
| FR-CLS-11 | Individual and business sellers; category-specific attributes; negotiable price; listing analytics. | Spec §5.2 | N |

### 4.15 Real estate and brokers (Spec §5.7) — new
| ID | Requirement | Source | Tag |
|---|---|---|---|
| FR-RE-1 | Properties (rent/sale, residential/commercial, type, location + map, price, area, bedrooms, bathrooms, features, images, video, permit info, verification). Listed by brokers (marketer:broker) or owners (vendor:classified, property vertical). Property uses the classified listing machine (contract + review). | Spec §5.7, MP §2 | N |
| FR-RE-2 | Leads from inquiries, contact and viewing requests; broker leads pipeline with follow-ups; lead analytics. | Spec §5.7 | N |
| FR-RE-3 | Broker profile pages; featured properties; paid promotions; broker commissions; campaign participation; broker documents. | Spec §5.7, MP §8 | N |
| FR-RE-4 | Special requests routed to matching brokers (SRS §11.13). | SRS §11.13 | K |

### 4.16 Travel (SRS §12.2, Spec §5.3)
| ID | Requirement | Source | Tag |
|---|---|---|---|
| FR-TRV-1 | Agency registration via portal 2-step wizard; admin approve (`pending → active ⇄ suspended`); locked sections via change requests. | SRS §12.2, PORTAL_PAGES §5 | K |
| FR-TRV-2 | Packages `draft → pending_review → active → sold_out | expired`; destinations, inclusions, categories, bookable units. | SRS §12.2 | K |
| FR-TRV-3 | Bookings `pending_documents → confirmed → completed` or `cancelled`; completed nightly 03:45. | SRS §12.2 | K |
| FR-TRV-4 | Inquiries `new → contacted → converted | closed`. | SRS §12.2 | K |
| FR-TRV-5 | **Seat capacity locking**: a seat hold during booking (timer shown), released every minute when it expires; no overbooking (transactional allocation). | MP §3, §11, Spec §5.3 | N |
| FR-TRV-6 | Departure dates and availability calendar; optional services; booking modifications; booking vouchers; invoices; reviews; operator settlement; trip contract (customer ↔ agency). | MP §7, §9, Spec §5.3 | N |
| FR-TRV-7 | Agency campaigns (origin B) incl. quote, pause, resume, cancel, export. | SRS §4.5 | K |

### 4.17 Contracts and documents (SRS §13, MP §9)
| ID | Requirement | Source | Tag |
|---|---|---|---|
| FR-CON-1 | Kinds: `seller_platform`, `seller_category`, `seller_marketer` (incl. open-call), `customer_purchase` (purchase/rental), `marketer_onboarding` (optionally required for customers), `exclusive`, `travel_agency`, `broker`, `shipping_company`, `trip`. One engine for every registration (fixes SRS §19.8). | MP §9, SRS §13.1 | I |
| FR-CON-2 | Templates: editor (AR/EN with variables) or uploaded file (PDF/DOC/DOCX ≤ 20 MB, Arabic required); private disk; country or legacy/global; **no cross-country fallback**; scope fixed at creation. | SRS §13.2 | K |
| FR-CON-3 | Version lifecycle Draft → Published → Retired; Scheduled (notice mode); published versions never edited; `contracts:apply-effective` hourly. | SRS §13.3 | K |
| FR-CON-4 | Seller Agreement consent modes: click-wrap (blocks partner web **and mobile app** — fixes SRS §19.4) and notice (+14 days default, continued use). | SRS §13.4, MP §13 | I |
| FR-CON-5 | ContractResolver order and `ContractNotConfiguredException`; stale signatures → `re_sign_required`; collaboration contract applicability and uncovered destinations list. | SRS §13.5 | K |
| FR-CON-6 | Signature evidence: typed name, timestamp, IP, user agent, version, language, frozen copy, SHA-256, consent method; emailed copy; always downloadable. | SRS §13.6 | K |
| FR-CON-7 | Documents per country × business type × category (mandatory / optional / N/A); vendor onboarding documents, certifications, marketer, agent, classified attachments, package contracts, exclusive files, transfer proofs; statuses per lifecycle catalogue. | SRS §13.7, MP §7a | K |

### 4.18 Live media (SRS §14, ADR 11, Spec §9) — mechanism replaced
| ID | Requirement | Source | Tag |
|---|---|---|---|
| **FR-LS-1** | Admin creates a stream: bilingual title/description, thumbnail, optional `scheduled_at`, media mode `audio | video | both`; stream key generated. | SRS §14.1 | K |
| **FR-LS-2** | Admin studio captures camera/mic and presses Go Live → `live`, `started_at`. *(Transport replaced by LiveKit SFU.)* | SRS §14.1, ADR 11 | R |
| **FR-LS-3** | *(Replaced.)* P2P mesh + coturn is replaced by **LiveKit SFU** behind a provider adapter; TURN still documented for LiveKit. | SRS §14.1, ADR 11 | R |
| **FR-LS-4** | End sets `ended`, `ended_at`; an ended stream cannot go live again. | SRS §14.1 | K |
| **FR-LS-5** | Public viewers comment and like (30/min); admin deletes comments; peak/total viewers and likes tracked. | SRS §14.1 | K |
| **FR-LS-6** | Recorded channel: admin uploads media (listed formats, ≤ 500 MB) with required `scheduled_at`. | SRS §14.2 | K |
| **FR-LS-7** | Duration via ffprobe (fallback browser-measured); refused if unknown; `scheduled_end_at = scheduled_at + duration`. | SRS §14.2 | K |
| **FR-LS-8** | `scheduled → live → ended`, healed to scheduled if re-timed; synced every minute and lazily. | SRS §14.2 | K |
| **FR-LS-9** | Go Live refused for channels. | SRS §14.2 | K |
| **FR-LS-10** | Playback `synced` (default) or `free`. | SRS §14.2 | K |
| **FR-LS-11** | Public API returns media URL, playback mode and current offset. | SRS §14.2 | K |
| FR-LS-12 | **Admin-only media**: only admins create, upload, schedule and publish streams, recorded channels and every video (Admin → Content → Live & videos). Creators, stores and brands are tags only. No Live/Videos menu in vendor or creator panels. | ADR 11 | R |
| FR-LS-13 | **Scheduled homepage media**: floating icon/mini-player (expanded, collapsed, dismissed) per country, language, audience and segment; dismissal suppression rules; checked every 5 min. | Spec §9.1, MP §11 | N |
| FR-LS-14 | Recording and replay; moderation; session notifications; Nawy TV vertical feed in the app. | Spec §9, MP §14c | N |

### 4.19 Content (SRS §15, MP §14a, ADR 16)
| ID | Requirement | Source | Tag |
|---|---|---|---|
| FR-CMS-1 | Page builder: 51 blocks in 10 groups (A–J); templates `home`, `store_landing`, `category`, `campaign`, `brand_store`, `seller_store`, `marketer_store`, `search`, `vertical_landing`, `app_context`; per-block visibility and analytics; status `draft → published / scheduled → archived` (every 5 min); append-only revisions with restore; copy to several countries. | SRS §15, MP §14a | I |
| FR-CMS-2 | **App contexts** define the customer app super-app switcher per country: Main, Super Mall, Food, Minutes, Souq, Travel, Property, Live — each with header theme, home layout (`app_context` template) and bottom tab bar. | ADR 16, SRS §15 | I |
| FR-CMS-3 | Static pages (country-specific with global fallback; audience storefront/portal/both); portal content (permission-controlled); footer settings. | SRS §15, PORTAL_PAGES §12 | K |
| FR-CMS-4 | Blog (nested categories, bilingual, tags, featured, attachments, views, scheduled publish); Ads Knowledge Hub (collections, articles, pinned); Seller Help Center (country or all-country; EN+AR search top 30 by views); customer help centre; FAQs (drag to reorder). | SRS §15, PORTAL_PAGES §9–11 | K |
| FR-CMS-5 | In-app documentation module (Admin → Documentation) regenerated for the new system; retired features removed (SRS §19.3). | SRS §4.1.16, MP §13 | I |
| FR-CMS-6 | Media library (admin-curated, DV3). | MP §7 | N |

### 4.20 Support, chat and notifications (SRS §15, Spec §17.2–17.3)
| ID | Requirement | Source | Tag |
|---|---|---|---|
| FR-SUP-1 | Tickets for customers, vendors, marketers, agencies (and carriers, proposed): `open → in_progress → waiting_customer → resolved → closed`; reply, assign, status, priority. | SRS §15 | K |
| FR-SUP-2 | Channels: DB, mail, broadcast (Reverb), FCM push, WhatsApp, SMS. Templates per country and language (`notification_templates`), incl. email and SMS/OTP templates. Bell in every panel. | ADR 10, Spec §17.2 | I |
| FR-SUP-3 | Real-time: chat, order/shipment updates, dashboard alerts via Reverb; Horizon for queues. | ADR 10, Spec §17.3 | K |

### 4.21 Seller portal — `business.` (SRS §4.2, MP §5a, PORTAL_PAGES)
| ID | Requirement | Source | Tag |
|---|---|---|---|
| FR-POR-1 | `/join` chooser with 4 sections (Product seller, Open Souq, Travel agency, Marketer → influencer/affiliate/broker) + country; per-country toggle `join_option_{section}_enabled`. | MP §5a, PORTAL_PAGES §2 | K |
| FR-POR-2 | Vendor 5-step wizard: 1 Account · 2 Store (slug check) · 3 Contact & address · 4 Documents (by country/type/category) · 5 Agreement & e-signature → success; funnel tracking per step. | MP §5a, SRS §4.2 | K |
| FR-POR-3 | Application status pages: in review / approved / rejected with reason / more documents; trackable status and audit trail (`application_audits`). | MP §5a, Spec §11 | N |
| FR-POR-4 | Open Souq landing (sign-up via vendor wizard); travel landing + 2-step wizard + success; marketers overview + 3 type pages; WhatsApp invitation page. | MP §5a | K |
| FR-POR-5 | Broker and shipping-partner registration journeys (new). | MP §5a, Spec §11 | N |
| FR-POR-6 | Seller info ×4, advertising ×6 (`/{code}/advertise/…`), Ads Knowledge Hub, Seller Help Center, blog, static (×7), legal seller agreement per country with AR/EN file viewer; language/country switchers (session); legacy redirects. | MP §5a | K |
| FR-POR-7 | Onboarding-step builder per registration type (admin-configurable). | MP §7a, Spec §11 | N |
| FR-POR-8 | Portal analytics: visitors and funnel; prune daily 04:30. | SRS §4.2, §17 | K |

### 4.22 Seller performance, vendor API and tools
| ID | Requirement | Source | Tag |
|---|---|---|---|
| FR-SPF-1 | Seller score, SLA, strikes, hold, blacklist; vendor cancel hurts the score. | SRS §4.1.5, §4.3 | K |
| FR-SPF-2 | Vendor API & tokens for integrations (create, scope, revoke). | SRS §4.3 | K |
| FR-SPF-3 | AI credits: image enhancement and video generation (adapter); admin AI dashboard. | SRS §4.1.1, §4.3 | K |
| FR-SPF-4 | Change requests for locked profile sections (bank accounts etc.). | SRS §4.1.5 | K |
| FR-SPF-5 | Vendor team, custom roles, bank accounts (verified), payment methods, shipping companies settings. | SRS §4.3 | K |

### 4.23 Search and discovery (ADR 9, Spec §17.4)
| ID | Requirement | Source | Tag |
|---|---|---|---|
| FR-SRC-1 | `SearchEngine` (MySQL fulltext → Meilisearch per country) for products, categories, brands, classifieds, trips, properties, influencers, brokers; country, language, category, price, attributes, location, sort, availability filters. | ADR 9, Spec §17.4 | I |
| FR-SRC-2 | Sponsored results via `SponsoredRanker`; popular searches; search analytics (`search_queries`) per country; index rebuild job. | ADR 9, MP §11 | N |
| FR-SRC-3 | Search overlay (recent, trending), suggestions with bold prefix, results with sponsored brand block, grid/list toggle, sticky chip bar, filter sheet with live count, sort sheet. | MP §14c | N |
| FR-SRC-4 | Public wishlist groups (shareable). | SRS §4.1.5 | K |

### 4.24 Analytics, AI and reporting
| ID | Requirement | Source | Tag |
|---|---|---|---|
| FR-ANL-1 | Admin dashboard and analytics (revenue by period, top products/vendors/categories, customers, SLA, flash-sale analytics, return rates, support metrics); badges cached. | SRS §4.1.1 | K |
| FR-ANL-2 | Analytics cache hourly; prune daily; nightly reporting ETL across countries; celebrity monthly report. | SRS §17, MP §11 | K+N |
| FR-ANL-3 | Panel-level reports for partner, marketer, travel, carrier. | SRS §4.3–4.7 | K |

### 4.25 Ops and system health (MP §7 Ops)
| ID | Requirement | Source | Tag |
|---|---|---|---|
| FR-OPS-1 | Admin System health: queue monitor, failed jobs, scheduler heartbeat per country, API logs, security events, backups status. | MP §5, §7, Spec §12.1 | N |
| FR-OPS-2 | Activity log (immutable audit trail) for every important action. | SRS §4.1.14, NFR-2 | K |

### 4.26 Apps (SRS §4.9, MP §5, §14c, Spec §14.4)
| ID | Requirement | Source | Tag |
|---|---|---|---|
| FR-APP-1 | Customer app (full) in the Noon app structure: context switcher, per-context header and tab bar, main tab bar Home · Categories · Deals · Account · Cart, home section order 1–14, discovery, PDP, cart/checkout, account, contexts, auth (email + Apple/Google), system states. | MP §14c | I |
| FR-APP-2 | Delivery app (full): sign-in, my day, route map, stop detail, accept/decline, pickup scan, delivery code (agent sees code; "Codes match"), proof of delivery, can't deliver, COD, earnings, wallet, availability, notifications, history, profile & GPS, offline. | MP §14, ADR 15 | K+N |
| FR-APP-3 | Ops apps (minimal), one shared sign-in with role switch: vendor (sales, orders, products, inventory, click-wrap), marketer (dashboard, campaigns, links, wallet), supervisor (dispatch, assignments, summary), travel agency (packages/bookings, inquiries). | MP §5, §14 | I |
| FR-APP-4 | Mobile click-wrap enforced in the vendor app. | MP §13 | I |

### 4.27 Data migration (D1)
| ID | Requirement | Source | Tag |
|---|---|---|---|
| FR-MIG-1 | Migrate live data for all 8 countries from the old DB (356 tables) per `data-migration-plan.md`; one migration step + reconciliation test per module (build order, MP §15). | D1, MP §15 | N |
| FR-MIG-2 | Legacy URL redirects (portal `/companies`, `/register`), legacy contract templates (global) preserved as "legacy" country-less templates. | PORTAL_PAGES §2, SRS §13.2 | K |

---

## 5. Non-functional requirements

| ID | Requirement | Source |
|---|---|---|
| **NFR-1** Money integrity | Never sum across currencies; snapshot amounts at event time; row locks and unique keys for idempotency (conversions, settlements, payouts). New: 3-dp currencies, brick/money, ledger-derived balances. | SRS §18, ADR 3–4 |
| **NFR-2** Auditability | Activity log, append-only ledger, wallet transactions, inventory movements, revisions, signatures; order history with actor. | SRS §18 |
| **NFR-3** Security | Guard isolation per panel; permission middleware on every route; private disk; signed WhatsApp URLs; throttling on public endpoints. New: optional 2FA, device revoke, encrypted gateway credentials, OWASP-aligned controls, MIME validation, CSRF, no raw card data, gift-card codes never logged. | SRS §18, Spec §19 |
| **NFR-4** Localization | AR/EN everywhere with RTL; storefront `/{country}-{lang}` markets for 8 countries; Cairo/Inter fonts; per-country number/currency formats. | SRS §18, MP §4a |
| **NFR-5** Real-time | Reverb for notifications, chat, signalling; Horizon queues always running. | SRS §18, ADR 10 |
| **NFR-6** Jobs | Scheduler every minute; every job idempotent and country-aware (runs per country connection); daily jobs run at country-local times (proposed) or UTC-anchored (to confirm, see risk T7). | SRS §18, MP §11 |
| **NFR-7** Performance | Cached badge counts; page-builder cache cleared on save; SSR/ISR on storefront; Core Web Vitals targets (proposed: LCP ≤ 2.5 s p75, CLS ≤ 0.1, INP ≤ 200 ms); API p95 ≤ 300 ms for catalogue reads (proposed); live streams scale via SFU (no 30–50 viewer cap). | SRS §18, Spec §20 |
| **NFR-8** Data safety | Append-only migrations; no destructive commands on live DBs; backups per country DB and central (proposed RPO 15 min, RTO 4 h); retention policies per country. | SRS §18, Spec §19 |
| **NFR-9** Testing | PHPUnit/Pest feature tests and Playwright per panel; Vitest + Playwright for Next.js; Flutter widget/integration tests; parity suite; E2E 1–18; isolation tests ×8; money tests ×8 currencies; calendar tests incl. Egypt DST. | SRS §18, MP §16 |
| NFR-10 Country isolation | Operational data per country DB; trusted `CountryContext`; cross-country access only via reporting DB or explicit admin country switch; isolation test per country (E2E 18). | ADR 2, Spec §4.1 |
| NFR-11 Time | UTC storage, per-country zones, admin in Oman time, DST-safe inputs. | ADR 14 |
| NFR-12 API | Versioned `/api/v1`; consistent envelope; standard validation errors; pagination, filtering, sorting; rate limits; idempotency keys on critical operations; country resolution; API logs; OpenAPI contract; public vs authenticated APIs separated. | Spec §18 |
| NFR-13 Accessibility | WCAG 2.1 AA target for storefront and portal (proposed); keyboard-operable panels. | Spec §15.1 |
| NFR-14 Observability | Error tracking, queue monitoring, failed-job alerts, security events, uptime per host. | Spec §20, MP §7 Ops |
| NFR-15 Stack constraints | Laravel 13 modular monolith; Blade + Tailwind 4 + vanilla JS panels (no Livewire, no React in panels); Next.js 16 storefront/portal; Flutter apps; clients talk to Laravel APIs only. | ADR 1, 12, 13, Spec §2 |
| NFR-16 Design traceability | Every UI route maps to a Figma frame or a documented pattern (`route-frame-map.md`). | Spec §15.3 |

---

## 6. Key user journeys (clickable prototypes, Spec §15.3; E2E, Spec §21.5)

| # | Journey | Main FRs | E2E (Spec §21.5) |
|---|---|---|---|
| J1 | Customer purchase (single and multi-vendor) | FR-CHK-*, FR-PAY-3, FR-ORD-1 | 1, 2 |
| J2 | Seller registration (portal 5-step) | FR-POR-1..3, FR-CON-6 | — |
| J3 | Product creation (listing with contract/cert gates) | FR-LST-1..4 | — |
| J4 | Vendor order processing | FR-ORD-1, FR-DLV-1 | — |
| J5 | Classified listing (contract → review → active → sold) | FR-CLS-1..10 | — |
| J6 | Trip booking with seat hold | FR-TRV-3, FR-TRV-5 | 8 |
| J7 | Affiliate campaign participation (A–F) | FR-MKT-4..13 | 5, 6, 7 |
| J8 | Broker lead management | FR-RE-2 | 9 |
| J9 | Delivery completion with code match and COD | FR-DLV-2..5 | 10, 11 |
| — | Coupons, flash sale, gift card, wallet, scheduled video, payment retry, duplicate webhook, refund reversal, isolation | FR-PRM-*, FR-FIN-*, FR-LS-13, FR-PAY-3, NFR-10 | 3, 4, 12–18 |

---

## 7. Out of scope (and dropped items)

| Item | Reason / source |
|---|---|
| Influencer Deals, Secret Promotions, Affiliate Promo Codes | Retired; docs only (MP §3 **D**, SRS §19.3). |
| Customer phone + OTP sign-in | Replaced by email sign-in (DV1). Phone stays optional for delivery. |
| Creator-, vendor- or brand-produced live streams, recorded channels and videos | Admin-only media (ADR 11). Listing media is the only exception. |
| Marketer-uploaded promotional media library | Admin-curated library (DV3). |
| Agent typing the customer's delivery code | Parity kept: code shown to agent, verbal match (DV4). |
| Drawing every panel content page in Figma | Panels = layouts + components per page type (DV2). |
| Separate Arabic Figma frames for every page; mobile-web frames for every vertical page | Covered by RTL rules and patterns (`design-coverage.md`). |
| P2P WebRTC mesh + coturn as the media transport | Replaced by LiveKit (ADR 11). |
| Microservices | Modular monolith (ADR 1, Spec §14.1). |
| Livewire / React in panels | Spec §2.4, ADR 12. |
| Summing or converting money across currencies in payouts and balances | NFR-1. |
| Automatic payouts without admin approval | SRS §9 ("nothing is paid out automatically"). |
| Local payment methods (KNET, BenefitPay, NAPS/QPay, eFAWATEERcom) | **Open — D6**; adapters can be added later. |
| Legal claim about e-signature validity | SRS §13.6; Spec §19. |

## 8. Open questions for owner review

| # | Question | Raised by |
|---|---|---|
| OQ1 | D6 — enable local payment methods per country? | MP §17 |
| OQ2 | D7 — confirm VAT/GST rates and weekends (see `country-reference.md`). | MP §17 |
| OQ3 | Delivery app frame "01 Sign in" is phone + device; ADR 5 says business accounts use email + password. Confirm agent sign-in method (proposed: email or phone + password, device binding). | `design-coverage.md` vs ADR 5 |
| OQ4 | Default store language per country (proposed in `country-reference.md` §4). | This PRD |
| OQ5 | High-value threshold for proof of delivery per country (setting `delivery_proof_required_above`). | ADR 15 |
| OQ6 | Daily jobs: run at each country's local time or at one UTC time? (proposed: local time per country). | MP §11, ADR 14 |
| OQ7 | Return window defaults per category (MP §7a lists the setting without a value; proposed 14 days). | MP §7a |
| OQ8 | Nawy One price and benefits per country. | MP §7a |
| OQ9 | Shipping companies and brokers: do they get support tickets and wallets like agencies? (proposed yes). | Spec §8.1, §12.5 |
