# FEATURE_MATRIX — Feature × Channel
> M2 discovery set · v1.0 · 2026-11-14 · Owner: BA / PM
> Sources: MASTER_PLAN v3 §3 (parity tags), §5 (channels), §11, §14, §15 (milestones); SRS §4 (pages per panel); Spec §10, §12, §14.4, §23 (feature matrix rules). Requirement IDs: `PRD.md`.

## Legend

| Column | Channel |
|---|---|
| **Web** | Next.js storefront `/{market}` (desktop + mobile web) |
| **App** | Customer Flutter app (Noon structure, MP §14c) |
| **Adm** | Admin panel `admin.` |
| **Prt** | Partner panel `partner.` (Seller Center = product vendor; Marketplace = classified vendor) |
| **Mkt** | Marketer panel `marketer.` (Creator Studio / Broker / Agency) |
| **Trv** | Travel agency panel `travel-agency.` |
| **Car** | Carrier panel `carrier.` (Shipping + Ops/supervisor) |
| **Dlv** | Delivery agent: web `delivery.` + delivery app |
| **Ops** | Ops apps (minimal): V vendor, M marketer, S supervisor, T travel |
| **Por** | Portal `business.` |
| **API** | `/api/v1` audiences: pub, cust, vend, mkt, trv, car, dlv |

Cells: **●** full · **○** view / limited · **—** not present. Parity: **K** keep · **I** improve · **R** replace · **N** new · **D** drop.
**M** = milestone delivering the backend rule. UI channels land in **M12** (panels, portal), **M13** (web), **M14** (apps) unless the backend milestone is later. Status columns required by Spec §23 (design/backend/dashboard/web/app/test) are tracked in `STATUS.md`; design status per route is in `route-frame-map.md`.

---

## 1. Foundation — countries, identity, settings

| Feature | Web | App | Adm | Prt | Mkt | Trv | Car | Dlv | Ops | Por | API | Parity | M |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 8-country provisioning, CountryContext, country DBs | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● | all | N | M6 |
| Country & language selector (8 countries, AR/EN, RTL) | ● | ● | ● | ○ | ○ | ○ | ○ | ○ | ○ | ● | pub | I | M6 |
| Countries & locales admin (tax, calendars, currencies, cities) | — | — | ● | — | — | — | — | — | — | — | — | I | M6 |
| Admin Oman-time display + "= Oman time" inputs | — | — | ● | ○ | ○ | ○ | ○ | — | — | — | — | N | M6 |
| Domains & subdomain mapping | — | — | ● | — | — | — | — | — | — | — | — | I | M6 |
| Settings service + settings catalogue screens | — | — | ● | ○ | — | — | — | — | — | — | — | I | M6 |
| Customer email sign-in (password / 6-digit code), Google/Apple | ● | ● | — | — | — | — | — | — | — | — | cust | R (DV1) | M6 |
| Email verification gate before checkout | ● | ● | — | — | — | — | — | — | — | — | cust | N | M6 |
| Business sign-in, forgot/reset, optional 2FA | — | — | ● | ● | ● | ● | ● | ● | ● | — | all | I | M6 |
| Role memberships per country; multi-role user; role switch | ○ | — | ● | ● | ● | ● | ● | ● | ● | — | all | I | M6 |
| Login history, device revoke | ● | ● | ● | ● | ● | ● | ● | ● | ○ | — | all | K | M6 |
| Admin users, roles, impersonation, sessions | — | — | ● | — | — | — | — | — | — | — | — | K | M6 |
| Tenant teams & custom roles (vendor, agency, carrier) | — | — | ○ | ● | ○ (agency) | ● | ● | — | — | — | — | K | M6 |
| Credential emails for new accounts | — | — | ● | ● | ● | ● | ● | ● | — | — | — | K | M6 |
| Activity log (audit) | — | — | ● | — | — | — | — | — | — | — | — | K | M6 |
| API envelope, versioning, OpenAPI, rate limits, idempotency | — | — | — | — | — | — | — | — | — | — | all | N | M6 |
| System health (queues, failed jobs, API logs, security events, backups) | — | — | ● | — | — | — | — | — | — | — | — | N | M12 |

## 2. Catalog, listings, inventory

| Feature | Web | App | Adm | Prt | Mkt | Trv | Car | Dlv | Ops | Por | API | Parity | M |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Products, variants, attributes, per-country settings (FR-CAT-1) | ○ | ○ | ● | ○ | ○ | — | — | — | ○ V | — | pub, vend | K | M7 |
| Barcode / GTIN, SKU (FR-CAT-4) | — | — | ● | ● | ● | — | — | — | ○ V | — | vend | N | M7 |
| Product requests (FR-CAT-2) | — | — | ● | ● | ● | — | — | — | — | — | vend, mkt | K | M7 |
| Categories (commission, sample qty, min stock, contract switch, return window) | ○ | ○ | ● | — | — | — | — | — | — | — | pub | K | M7 |
| Brands, custom pages, size guides | ● | ● | ● | ● (size guides) | — | — | — | — | — | — | pub | K | M7 |
| Product Q&A (FR-CAT-5) | ● | ● | ● | ● | ○ | — | — | — | — | — | cust, vend | N | M7 |
| Product comparison (FR-CAT-6) | ● | ○ | — | — | — | — | — | — | — | — | cust | N | M7 |
| Bestsellers job + highlights (FR-CAT-3) | ● | ● | ● | — | — | — | — | — | — | — | pub | K | M7 |
| Product certifications | — | — | ● | ● | — | — | — | — | — | — | vend | K | M7 |
| Vendor listings + contract/cert gates (FR-LST-1..4) | ○ | ○ | ● | ● | — | — | — | — | ● V | — | vend | K | M7 |
| Platform listings — Nawy Listings | ○ | ○ | ● | — | — | — | — | — | — | — | — | K | M7 |
| Marketer listings (campaign, independent, own FBN) | ○ | ○ | ● | — | ● | — | — | — | ○ M | — | mkt | K | M7/M9 |
| Buy box across vendor / platform / marketer listings | ● | ● | — | — | — | — | — | — | — | — | pub | K | M7 |
| Inventory, movements (append-only), low/out-of-stock views | — | — | ● | ● | ● | — | — | — | ● V | — | vend, mkt | K | M7 |
| Warehouses, transfers (vendor + platform) | — | — | ● | ● | — | — | — | — | — | — | vend | K | M7 |
| AI credits (image enhancement, video generation) + AI dashboard | — | — | ● | ● | — | — | — | — | — | — | vend | K | M11 |
| Listing media (photos + short video) by listing owner | — | — | ○ | ● | ● | ● | — | — | ○ V | — | vend, mkt, trv | K (ADR 11 exception) | M7 |

## 3. Discovery, search, content rendering

| Feature | Web | App | Adm | Prt | Mkt | Trv | Car | Dlv | Ops | Por | API | Parity | M |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Home (page builder, 51 blocks; Noon section order in app) | ● | ● | ● | — | — | — | — | — | — | — | pub | I | M11/M13 |
| Category & sub-category pages, templates (store, campaign, brand, seller, marketer) | ● | ● | ● | — | — | — | — | — | — | — | pub | I | M11/M13 |
| Mega menu, category nav; app Categories tab (left rail) | ● | ● | ● | — | — | — | — | — | — | — | pub | I | M13 |
| Search (SearchEngine), suggestions, filters, sort, grid/list | ● | ● | — | — | — | — | — | — | — | — | pub | I | M7/M13 |
| Sponsored results (SponsoredRanker) with label | ● | ● | ● | — | — | — | — | — | — | — | pub | N | M10 |
| Popular searches, search analytics | ● | ● | ● | — | — | — | — | — | — | — | pub | N | M7 |
| Deals hub, flash-sale pages with countdown | ● | ● | ● | ○ | — | — | — | — | — | — | pub | K | M7 |
| Recently viewed, recommendations, frequently bought together | ● | ● | — | — | — | — | — | — | — | — | cust | N | M13 |
| Seller storefront (`/seller/[id]`) | ● | ● | — | ○ | — | — | — | — | — | — | pub | K | M13 |
| Wishlist + public wishlist groups | ● | ● | ● | — | — | — | — | — | — | — | cust | K | M7 |
| App contexts / super-app switcher (Main, Super Mall, Food, Minutes, Souq, Travel, Property, Live) | ○ | ● | ● | — | — | — | — | — | — | — | pub | I | M11/M14 |

## 4. Cart, checkout, payments

| Feature | Web | App | Adm | Prt | Mkt | Trv | Car | Dlv | Ops | Por | API | Parity | M |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Cart with 15-min stock lock; guest cart merge (FR-CHK-1) | ● | ● | — | — | — | — | — | — | — | — | cust | K | M7 |
| Checkout totals calculator (FR-CHK-2) | ● | ● | ○ | — | — | — | — | — | — | — | cust | K | M7 |
| Coupon sheet (1 platform + 1 vendor) | ● | ● | — | — | — | — | — | — | — | — | cust | K | M7 |
| Free-delivery progress, shipments grouped, save for later | ● | ● | — | — | — | — | — | — | — | — | cust | N | M13 |
| Marketer contract gate (FR-CHK-4) | ● | ● | ○ | — | ○ | — | — | — | — | — | cust | K | M9 |
| Sub-order split per seller (FR-CHK-5) | — | — | ● | ● | ● | — | — | — | — | — | — | K | M7 |
| Warranty plan at checkout | ● | ● | ● | — | — | — | — | — | — | — | cust | K | M7 |
| Cart card offers | ● | ● | ● | — | — | — | — | — | — | — | cust | K | M7 |
| Address book + map picker | ● | ● | — | — | — | — | — | — | — | — | cust | I | M7 |
| Payment adapters: Thawani, Stripe, PayPal, PayTabs, Tabby, Tamara | ● | ● | ● | — | — | — | — | — | — | — | cust | I | M8 |
| Local methods KNET, BenefitPay, NAPS/QPay, eFAWATEERcom (D6) | ○ | ○ | ○ | — | — | — | — | — | — | — | cust | N (open) | M8 |
| Per-country gateway config with recommended flag | ● | ● | ● | — | — | — | — | — | — | — | pub | I | M8 |
| Webhook-verified payments, idempotent; 3DS wait; payment failed + retry | ● | ● | ● | — | — | — | — | — | — | — | cust | I | M8 |
| Saved cards (provider tokens) | ● | ● | — | ○ | — | — | — | — | — | — | cust | K | M8 |
| COD (fee, eligibility) | ● | ● | ● | ○ | — | — | — | ● | — | — | cust, dlv | K | M7/M11 |
| Wallet / Nawy Credits (incl. promotional) at checkout | ● | ● | ● | — | — | — | — | — | — | — | cust | I | M8 |
| Gift card redemption at checkout | ● | ● | ● | — | — | — | — | — | — | — | cust | K | M8 |
| Payment reconciliation, gateway reports | — | — | ● | — | — | — | — | — | — | — | — | N | M8 |

## 5. Orders and post-order

| Feature | Web | App | Adm | Prt | Mkt | Trv | Car | Dlv | Ops | Por | API | Parity | M |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Sub-order machine, master roll-up, history with actor | ● | ● | ● | ● | ● | — | ○ | ○ | ● V | — | cust, vend, mkt | K | M7 |
| Auto-complete 02:00 | — | — | ○ | ○ | ○ | — | — | — | — | — | — | K | M7 |
| Order detail, invoice PDF, cancel, tracking with delivery code | ● | ● | ● | ● | ○ | — | — | — | ○ V | — | cust | K | M7 |
| Vendor order processing (confirm, pack, ready, cancel, print) | — | — | ○ | ● | ● (own) | — | — | — | ● V | — | vend | K | M7 |
| Returns (wizard, pickup, inspection, restock) | ● | ● | ● | ● | ○ | — | ○ | ○ | — | — | cust, vend | K | M7 |
| Refunds (card / wallet / COD-to-wallet) | ○ | ○ | ● | ○ | — | — | — | — | — | — | cust | K | M8 |
| Disputes (mediation, resolutions) | ● | ● | ● | ● | — | — | — | — | — | — | cust, vend | K | M7 |
| Warranty: standard + extended plans; claims thread | ● | ● | ● | ● | — | — | — | — | — | — | cust, vend | K | M7 |
| Reviews (moderation, vendor replies), write review, my reviews | ● | ● | ● | ● | — | — | — | — | — | — | cust, vend | K | M7 |
| Fraud flag on orders | — | — | ● | — | — | — | — | — | — | — | — | K | M7 |

## 6. Fulfilment, shipping, delivery, carriers

| Feature | Web | App | Adm | Prt | Mkt | Trv | Car | Dlv | Ops | Por | API | Parity | M |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Shipping zones, methods, rates, slabs, volumetric weight | ○ | ○ | ● | ○ | — | — | ○ | — | — | — | pub | K | M7 |
| ETA with per-country weekends + holidays; cut-off countdown | ● | ● | ● | — | — | — | — | — | — | — | pub | I | M7 |
| Exceptional zones / subsidies | ○ | ○ | ● | ● | — | — | — | — | — | — | — | K | M7 |
| City shipping surcharges, vendor zone alerts | — | — | ● | ● | — | — | — | — | — | — | — | K | M7 |
| Weight calculator | — | — | — | ● | ○ | — | — | — | — | — | vend | K | M7 |
| FBN inbound, storage clock, overage fees, storage invoices | — | — | ● | ● | ● (own FBN) | — | — | — | — | — | vend | K | M7/M8 |
| FBN marketplace rules, outbound surcharges | — | — | ● | ○ | — | — | — | — | — | — | — | K | M7 |
| Packaging catalog and orders | — | — | ● | ● | — | — | — | — | — | — | vend | K | M7 |
| Shipments (label → delivered/failed/returned) + tracking events | ● | ● | ● | ● | — | — | ● | ● | ● S | — | cust, car, dlv | K | M11 |
| Delivery assignments (auto/manual, live map) | — | — | ● | — | — | — | ● | ● | ● S | — | car, dlv | K | M11 |
| **Delivery code — agent sees code, customer reads aloud, "Codes match"** | ● (code shown) | ● (code shown) | ○ | — | — | — | ○ | ● | — | — | cust, dlv | K (ADR 15) | M11 |
| Proof of delivery (photo / signature) for COD & high value | — | — | ○ | ○ | — | — | ○ | ● | — | — | dlv | N | M11 |
| Failed reasons, reschedule, return-to-origin | ○ | ○ | ● | ○ | — | — | ● | ● | ● S | — | dlv, car | N | M11 |
| Agent availability, GPS, documents, vehicle | — | — | ● | — | — | — | ● | ● | ○ S | — | dlv | K | M11 |
| SLA breach monitor (15 min) | — | — | ● | — | — | — | ● | ○ | ● S | — | — | K | M11 |
| COD collection, daily settlement, payout unlock | — | — | ● | ○ (amber notice) | — | — | ● | ● | ● S | — | dlv, car | K | M11 |
| Agent earnings, wallet, withdrawals, delivery payouts | — | — | ● | — | — | — | ○ | ● | — | — | dlv | K | M11 |
| Carrier agents & supervisors (owner rule) | — | — | ● | — | — | — | ● | — | ○ S | — | car | K | M11 |
| Carrier claims, scorecard | — | — | ● | ● (claims) | — | — | ● | — | — | — | car | K | M11 |
| Carrier settings, service zones, pricing, pickups, invoices (Spec §12.7) | — | — | ○ | — | — | — | ● | — | — | — | car | N | M11 |
| Pickup scan, offline mode, event history | — | — | — | — | — | — | — | ● (app) | — | — | dlv | N | M14 |

## 7. Finance

| Feature | Web | App | Adm | Prt | Mkt | Trv | Car | Dlv | Ops | Por | API | Parity | M |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Double-entry ledger with idempotency | — | — | ● | — | — | — | — | — | — | — | — | I | M8 |
| Wallets (pending / spendable / reserved / frozen / promotional) | ● | ● | ● | ● | ● | ● | — | ● | ○ M | — | cust, vend, mkt, trv, dlv | I | M8 |
| Withdrawals + approval | — | — | ● | ● | ● | ● | — | ● | ○ M | — | mkt, dlv, trv | K | M8 |
| Vendor payouts (schedules, approve → process, hold, recalc) | — | — | ● | ● | — | — | — | — | — | — | vend | K | M8 |
| Payout statement (lines incl. campaign fees, FBN, samples) | — | — | ● | ● | — | — | — | — | — | — | vend | K | M8 |
| Marketer commissions (pending → spendable) | — | — | ● | — | ● | — | — | — | ● M | — | mkt | K | M9 |
| Marketer-as-seller settlement (03:20) | — | — | ● | — | ● | — | — | — | — | — | mkt | K | M9 |
| Clawback recovery | — | — | ● | — | ● | — | — | — | — | — | mkt | N | M9 |
| Travel revenue, payouts, operator settlement | — | — | ● | — | — | ● | — | — | — | — | trv | K+N | M10 |
| Acquisition commissions (+ My acquisition commissions) | — | — | ● | — | — | — | — | — | — | — | — | K | M8 |
| Vendor subscriptions, plans, invoices | — | — | ● | ● | — | — | — | — | — | — | vend | K | M8 |
| Nawy One membership | ● | ● | ● | — | — | — | — | — | — | — | cust | N | M8 |
| Promotional credits with expiry | ● | ● | ● | — | — | — | — | — | — | — | cust | N | M8 |
| Transactions, financial reports, warranty purchases | — | — | ● | ● | ● | ● | — | — | — | — | — | K | M8 |
| Suspicious-transaction flags | — | — | ● | — | — | — | — | — | — | — | — | N | M8 |

## 8. Platform marketing & advertising

| Feature | Web | App | Adm | Prt | Mkt | Trv | Car | Dlv | Ops | Por | API | Parity | M |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Coupons (platform, vendor, category, product; rules) | ● | ● | ● | ● | ○ | — | — | — | — | — | cust, vend | K | M7 |
| Coupon participation invitations | — | — | ● | ● | ● | — | — | — | — | — | vend, mkt | K | M10 |
| Flash sales (events, submissions, marketer bonus) | ● | ● | ● | ● | ● | — | — | — | — | — | pub, vend, mkt | K | M7 |
| Banners (placements, schedule, audience) | ● | ● | ● | — | — | — | — | — | — | — | pub | K | M11 |
| Gift cards (store, product, scheduled email, balances) | ● | ● | ● | — | — | — | — | — | — | — | cust | K+N | M8 |
| Vouchers (product, category, service, trip, seller, platform) | ● | ● | ● | ○ | — | ○ | — | — | — | — | cust | I | M8 |
| Newsletter, push broadcasts | ● | ● | ● | — | — | — | — | — | — | — | pub | K | M11 |
| Self-serve ad campaigns (CPC/CPM) | — | — | ● | ● | — | — | — | — | — | — | vend | K | M10 |
| Nawi ad slots + paid bookings (vendors and marketers) | ● (placements) | ● | ● | ● | ● | ○ (N) | — | — | — | — | vend, mkt | K | M10 |
| Admin-uploaded video creatives on bookings | — | — | ● | — | — | — | — | — | — | — | — | R (ADR 11) | M10 |
| Ad packages (marketers; wallet or bank transfer) | — | — | ● | — | ● | — | — | — | — | — | mkt | K | M10 |
| Sponsored ranking settings | — | — | ● | — | — | — | — | — | — | — | — | N | M10 |
| Advertiser types extended: classified sellers, brokers, travel operators | — | — | ● | ● | ● | ● | — | — | — | — | vend, mkt, trv | N | M10 |
| Advertise pages + request form → inquiries | — | — | ● | — | — | — | — | — | — | ● | pub | K | M12 |

## 9. Marketers & campaigns

| Feature | Web | App | Adm | Prt | Mkt | Trv | Car | Dlv | Ops | Por | API | Parity | M |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Marketer registration, onboarding (job types), approval | — | — | ● | — | ● | — | — | — | ○ M | ● | mkt | K | M9 |
| Onboarding contract acceptance (FR-MKT-1) | — | — | ● | — | ● | — | — | — | ○ M | — | mkt | K | M9 |
| Profile commission rules + Marketer Settings grids (FR-MKT-2) | — | — | ● | — | ○ | — | — | — | — | — | — | K | M9 |
| Marketer jobs | — | — | ● | — | ○ | — | — | — | — | — | — | K | M9 |
| Campaign wizard (owners A, B, C) + live fee quote | — | — | ● | ● | — | ● | — | — | — | — | vend, trv | K | M9 |
| Marketer request (D), approve-request | — | — | ● | ● | ● | — | — | — | — | — | mkt, vend | K | M9 |
| Peer promotion (E) | — | — | ○ | — | ● | — | — | — | — | — | mkt | K | M9 |
| Open-to-affiliates join (F) / campaign marketplace + applications | — | — | ● | — | ● | — | — | — | ● M | — | mkt | K+N | M9 |
| Invitations (12 h, code, QR, WhatsApp signed link, replacement, invite more) | — | — | ● | ● | ● | ● | — | — | ● M | ● (WhatsApp page) | mkt | K | M9 |
| Referral redirect `/r/{code}` + click tracking | ● | ● | — | — | ○ | — | — | — | ● M (links) | — | pub | K | M9 |
| Attribution models (last / first / linear) + window | — | — | ● | — | ○ | — | — | — | — | — | — | I | M9 |
| Samples (address, dispatch, receipt) | — | — | ● | ● | ● | — | — | — | — | — | mkt | K | M9 |
| Campaign fee, budget, commission fee, influencer fixed | — | — | ● | ● | ○ | ● | — | — | — | — | vend, trv | K | M9 |
| Conversions (approve, release, reverse) | — | — | ● | ○ | ● | ○ | — | — | ● M | — | mkt | K | M9 |
| Cross-promotion engine + participation dashboard | — | — | ● | — | ● | — | — | — | — | — | mkt | N | M9 |
| Content submission & approval | — | — | ● | ○ | ● | ○ | — | — | — | — | mkt | N | M9 |
| Performance rankings, coupon-ID attribution, marketer coupons | ○ | ○ | ● | — | ● | — | — | — | — | — | mkt | N | M9 |
| Marketing agencies (agency switcher) | — | — | ● | — | ● | — | — | — | — | — | mkt | N | M9 |
| Customer ↔ marketer chat | ● | ● | ○ | — | ● | — | — | — | — | — | cust, mkt | K | M11 |
| Public directory, marketer page, boutique | ● | ● | ● | — | ○ | — | — | — | — | — | pub | K | M13 |
| Exclusive contracts (listing / category) | ● (badge) | ● | ● | — | ● | — | — | — | — | — | mkt | K | M9 |
| Admin-curated media library (browse/share) | — | — | ● | — | ● | — | — | — | — | — | mkt | R (DV3) | M11 |
| Celebrity monthly report | — | — | ● | — | — | — | — | — | — | — | — | K | M11 |
| Campaigns revenue (platform financials) | — | — | ● | — | — | — | — | — | — | — | — | K | M9 |
| Retired: Influencer Deals, Secret Promotions, Affiliate Promo Codes | — | — | — | — | — | — | — | — | — | — | — | D | — |

## 10. Classifieds, real estate, travel

| Feature | Web | App | Adm | Prt | Mkt | Trv | Car | Dlv | Ops | Por | API | Parity | M |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Classified listing lifecycle (contract before submit, review) | ● | ● | ● | ● | ● | — | — | — | — | — | pub, vend, mkt | K | M10 |
| Dynamic classified categories + dedicated sections | ● | ● | ● | ○ | ○ | — | — | — | — | — | pub | K | M10 |
| Inquiries (guest, throttle, contract gate) | ● | ● | ● | ● | ● | — | — | — | — | — | cust, vend, mkt | K | M10 |
| Map view, wanted ads, exclusive badges | ● | ● | — | ○ | ● | — | — | — | — | — | pub | K | M10 |
| Mark sold (final price, referring marketer) | — | — | ○ | ● | ● | — | — | — | — | — | vend, mkt | K | M10 |
| Listing expiry & renewal reminders, paid boosting | ● | ● | ● | ● | ● | — | — | — | — | — | vend, mkt | N | M10 |
| Saved searches + alerts, favourites, compare ads | ● | ● | — | — | — | — | — | — | — | — | cust | N | M10 |
| Abuse reports + moderation | ● | ● | ● | — | — | — | — | — | — | — | cust | N | M10 |
| Customer purchase/rental contracts (My Contracts) | ● | ● | ● | — | — | — | — | — | — | — | cust | K | M10 |
| Properties (rent/sale, map, verification) | ● | ● | ● | ● (owners) | ● (brokers) | — | — | — | — | — | pub, mkt | N | M10 |
| Broker leads pipeline, follow-ups, lead analytics | — | — | ○ | — | ● | — | — | — | — | — | mkt | N | M10 |
| Special requests (customer → brokers, atomic claim) | ● | ● | ○ | — | ● | — | — | — | — | — | cust, mkt | K | M10 |
| Travel agencies: registration, approval, change requests | — | — | ● | — | — | ● | — | — | — | ● | trv | K | M10 |
| Packages, departures, capacity, bookable units, optional services | ● | ● | ● | — | — | ● | — | — | ● T | — | pub, trv | K+N | M10 |
| Bookings (documents, confirm, complete, cancel) + seat hold | ● | ● | ● | — | — | ● | — | — | ● T | — | cust, trv | K+N | M10 |
| Booking modifications, vouchers, invoices, trip contract | ● | ● | ● | — | — | ● | — | — | — | — | cust, trv | N | M10 |
| Travel inquiries | ● | ● | ● | — | — | ● | — | — | ● T | — | cust, trv | K | M10 |
| Destinations, inclusions, travel categories (admin) | ● | ● | ● | — | — | ○ | — | — | — | — | pub | K | M10 |
| Agency campaigns (origin B) | — | — | ● | — | ○ | ● | — | — | — | — | trv | K | M10 |

## 11. Contracts & documents

| Feature | Web | App | Adm | Prt | Mkt | Trv | Car | Dlv | Ops | Por | API | Parity | M |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Contract templates (editor/file, country, versions, publish, assign) | — | — | ● | — | — | — | — | — | — | — | — | K | M9 |
| Seller Agreement click-wrap (web **and vendor app**) + notice mode | — | — | ● | ● | — | — | — | — | ● V | ● (step 5) | vend | I | M9 |
| Category/product contracts (pending, history) | — | — | ● | ● | — | — | — | — | — | — | vend | K | M9 |
| Collaboration (seller ↔ marketer) contracts incl. open-call | — | — | ● | ● | ● | ● | — | — | — | — | vend, mkt | K | M9 |
| Contract kinds travel_agency, broker, shipping_company, trip | ● (trip) | ● (trip) | ● | — | ● | ● | ● | — | — | ● | all | N | M9 |
| Signature evidence + downloads | ● | ● | ● | ● | ● | ● | ● | — | — | — | all | K | M9 |
| Document types matrix; onboarding documents | — | — | ● | ● | ● | ● | ● | ● | — | ● | vend | K | M9 |
| Onboarding-step builder | — | — | ● | — | — | — | — | — | — | ● | — | N | M9 |
| Change requests (locked sections) | — | — | ● | ● | — | ● | — | — | — | — | vend, trv | K | M9 |
| Public seller agreement page per country (AR/EN files) | — | — | — | — | — | — | — | — | — | ● | pub | K | M12 |

## 12. Live media & content

| Feature | Web | App | Adm | Prt | Mkt | Trv | Car | Dlv | Ops | Por | API | Parity | M |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Live broadcasts via native WebRTC + Reverb signalling (D18; was LiveKit) (**admin-only** studio) | ● (watch) | ● (watch) | ● | — | — | — | — | — | — | — | pub | R (ADR 11) | M11 |
| Recorded channels (scheduled, synced/free playback) | ● | ● | ● | — | — | — | — | — | — | — | pub | K | M11 |
| Comments, likes, viewer stats | ● | ● | ● | — | — | — | — | — | — | — | pub | K | M11 |
| Scheduled homepage media + floating mini-player | ● | ● | ● | — | — | — | — | — | — | — | pub | N | M11 |
| Nawy TV feed / Nawy Live strip | ● | ● | ● | — | — | — | — | — | — | — | pub | N | M11/M14 |
| Page builder (blocks, templates, revisions, copy to countries) | — | — | ● | — | — | — | — | — | — | — | pub | I | M11 |
| App contexts admin | — | — | ● | — | — | — | — | — | — | — | pub | I | M11 |
| Static pages, footer, portal content | ● | ○ | ● | — | — | — | — | — | — | ● | pub | K | M11 |
| Blog | — | — | ● | — | — | — | — | — | — | ● | pub | K | M11 |
| Ads Knowledge Hub, Seller Help Center | — | — | ● | ○ (link) | — | — | — | — | — | ● | pub | K | M11 |
| Customer help centre, FAQs | ● | ● | ● | — | — | — | — | — | — | ○ | pub | K | M11 |
| In-app documentation module (regenerated) | — | — | ● | — | — | — | — | — | — | — | — | I | M15 |
| Media library (admin-curated) | — | — | ● | — | ○ | — | — | — | — | — | — | N | M11 |

## 13. Support, notifications, analytics

| Feature | Web | App | Adm | Prt | Mkt | Trv | Car | Dlv | Ops | Por | API | Parity | M |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Support tickets | ● | ● | ● | ● | ● | ● | ● | ○ | — | — | cust, vend, mkt, trv, car | K | M11 |
| Notifications: DB, mail, Reverb, FCM, WhatsApp, SMS; bell in every panel | ● | ● | ● | ● | ● | ● | ● | ● | ● | — | all | I | M11 |
| Notification templates per country + language | — | — | ● | — | — | — | — | — | — | — | — | N | M11 |
| Customer QR code + regenerate | ● | ● | ● | — | — | — | — | — | — | — | cust | K | M6 |
| Admin dashboard & analytics | — | — | ● | — | — | — | — | — | — | — | — | K | M12 |
| Panel dashboards & reports | — | — | — | ● | ● | ● | ● | ● | ● | — | all | K | M12 |
| Seller performance (score, SLA, strikes, hold, blacklist) | — | — | ● | ● | — | ○ | ○ (scorecard) | — | — | — | vend | K | M7 |
| Vendor API & tokens | — | — | ○ | ● | — | — | — | — | — | — | vend | K | M12 |
| Portal analytics (visitors, funnel) | — | — | ● | — | — | — | — | — | — | ● (tracking) | — | K | M12 |
| Nightly reporting ETL (cross-country) | — | — | ● | — | — | — | — | — | — | — | — | N | M8 |

## 14. Portal (`business.`)

| Feature | Web | App | Adm | Prt | Mkt | Trv | Car | Dlv | Ops | Por | API | Parity | M |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Join chooser (4 sections, 8 countries, per-country toggles) | — | — | ● | — | — | — | — | — | — | ● | — | K | M12 |
| Vendor 5-step wizard + success + funnel tracking | — | — | ● | — | — | — | — | — | — | ● | — | K | M12 |
| Application status (in review / approved / rejected / more documents) | — | — | ● | — | — | — | — | — | — | ● | — | N | M12 |
| Open Souq landing | — | — | — | — | — | — | — | — | — | ● | — | K | M12 |
| Travel landing + 2-step wizard + success | — | — | ● | — | — | — | — | — | — | ● | — | K | M12 |
| Marketers overview + influencer / affiliate / broker pages | — | — | — | — | — | — | — | — | — | ● | — | K | M12 |
| Broker and shipping-partner registration journeys | — | — | ● | — | — | — | — | — | — | ● | — | N | M12 |
| Seller info ×4, advertising ×6, Ads KH, Help Center, blog, static ×7, legal | — | — | ● | — | — | — | — | — | — | ● | pub | K | M12 |
| Language/country switchers; legacy redirects | — | — | — | — | — | — | — | — | — | ● | — | K | M12 |

## 15. Data migration & hardening

| Feature | Channel | Parity | M |
|---|---|---|---|
| Per-module legacy migration step + reconciliation test, 8 countries | Backend / ops | N | M7–M15 |
| Cutover, retention, backups, performance, docs tree (Spec §23) | All | N | M15 |
