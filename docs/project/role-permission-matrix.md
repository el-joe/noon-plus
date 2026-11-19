# Role & Permission Matrix
> M2 discovery set · v1.0 · 2026-11-14 · Owner: BA / PM
> Sources: MASTER_PLAN v3 §2 (actors), §5 (panels), ADR 5 (auth), ADR 11 (media), ADR 14 (time); SRS §2.2 (guards), §3 (actors), §4 (panels/pages), §16 (roles); Spec §12, §17.1.
> "Proposed" = the sources are silent; this is a documented default to confirm at owner review.

---

## 1. Guards and workspaces

One central identity (`users`) with **role memberships per country** (`role_memberships: user × role × country × status`). Each panel host has its own guard on central users; the guard only admits users who hold an **active** membership of the matching role in the selected country (MP §2, ADR 5).

| Host / channel | New guard (proposed name) | Old guard (SRS §2.2) | Membership role(s) admitted | Auth method | Country selection |
|---|---|---|---|---|---|
| `admin.` | `admin` | `admin` | `admin` (+ Spatie role) | Email + password, optional 2FA | In-panel country switch; only countries in the admin's memberships (super = all) |
| `business.` (portal) | none (guest) | guest | — | — | `/{code}` prefix or session (`/country/{code}`) |
| `partner.` | `partner` | `vendor` | `vendor:product`, `vendor:classified` | Email + password, optional 2FA | Country of the vendor membership |
| `marketer.` | `marketer` | `marketer` | `marketer:influencer`, `marketer:affiliate`, `marketer:broker`, `marketer:agency` | Email + password, optional 2FA; signed URL for WhatsApp invitation answers | Country of the marketer membership |
| `travel-agency.` | `travel_agency` | `travel_agency` | `travel_agency` | Email + password, optional 2FA | Agency country |
| `carrier.` | `carrier` | `shipping_company_supervisors` | `carrier_supervisor` | Email + password, optional 2FA | Company operating country (multi-country company = switch) |
| `delivery.` + delivery app | `delivery` (web) / Sanctum `delivery` ability (app) | `delivery_agents` | `delivery_agent` | Email/phone + password + device (see PRD OQ3) | Agent country |
| Storefront `/{market}` + customer app | Sanctum (`customer` abilities) | `customer` (JWT) | `customer` | **Email** password or 6-digit email code, Google/Apple (DV1) | Market slug |
| Ops apps (vendor, marketer, supervisor, travel) | Sanctum, role-scoped abilities | various | the user's business memberships | One shared sign-in + **role switch** | Per selected membership |
| APIs `/api/v1/*` | Sanctum per audience: `public`, `customer`, `vendor`, `marketer`, `travel`, `carrier`, `delivery`, `admin` (internal) | `api_*.php` files | as above | Token | Header/market resolved by `CountryContext` |

**Rules**
1. A user may hold several memberships (e.g. vendor in Oman + influencer in UAE). Each workspace session is bound to **one membership** at a time; switching membership re-checks permissions (Spec §17.1).
2. Every route has permission middleware (SRS NFR-3). Every model action has a Policy that also checks **tenant ownership** (§4).
3. Memberships have status `pending | active | suspended | revoked` (proposed). Only `active` signs in, except marketers in `pending`, who reach onboarding pages only (SRS §4.4).
4. Every panel shows times in its country time zone; **admin shows Oman time** (ADR 14).

## 2. Permission naming

Format **`{module}.{action}`** (SRS §16), e.g. `marketer_campaigns.approve`.

| Action | Meaning |
|---|---|
| `view` | See list and detail |
| `create` / `update` / `delete` | CRUD |
| `approve` | Approve / reject / moderate a queue item |
| `process` | Execute money movement or state change with side effects (process payout, mark settled, refund) |
| `export` | Download CSV/PDF |
| `configure` | Change settings, grids and rules |
| `impersonate` | Sign in as another account (admin only) |

Matrix legend: **F** full (all actions) · **A** view + approve/moderate · **E** view + create/update · **P** view + process · **V** view only · **—** no access · **S** scoped to assigned vendors (account manager) · **O** own records only.

---

## 3. Admin panel (`admin.`)

### 3.1 Admin roles (MP §2, SRS §3, §16)
| Role | Scope | Notes |
|---|---|---|
| Super admin | All countries | System role, cannot be deleted; bypasses permission checks except "4-eyes" money actions (proposed: a super admin cannot approve a payout they generated). |
| Operations | Member countries | Orders, fulfilment, delivery, classifieds/travel moderation |
| Finance | Member countries | Payouts, ledger, wallets, settlements, reconciliation |
| Marketing | Member countries | Coupons, flash sales, banners, ads, marketers & campaigns |
| Vendor relations | Member countries | Applications, vendors, documents, contracts, change requests |
| Content | Member countries | Page builder, app contexts, CMS, **Live & videos** (ADR 11) |
| Support | Member countries | Tickets, customers, disputes (read), notifications |
| Account manager | Member countries + **assigned vendors only** | Restriction permission: assigned vendors only, finance hidden (§3.3) |
| Custom roles | Member countries | Built from any permissions (SRS §16) |

### 3.2 Admin permission groups × roles
Admin v2 IA = SRS §4.1 groups + Countries & locales + System health + Live & videos (MP §5).

| Sidebar group | Permission group (module) | Super | Ops | Finance | Marketing | Vendor rel. | Content | Support | Acct mgr |
|---|---|---|---|---|---|---|---|---|---|
| Overview | `dashboard`, `analytics` | F | V | V | V | V | V | V | S (no money KPIs) |
| Overview | `ai_dashboard` | F | — | — | E | — | E | — | — |
| Catalog | `products`, `brands`, `attributes`, `categories`, `custom_pages` | F | E | — | V | E | V | — | V |
| Catalog | `product_requests` | F | A | — | — | A | — | — | S·A |
| Catalog | `platform_listings` (Nawy Listings) | F | E | V | V | — | — | — | — |
| Catalog | `warranty_plans` | F | E | V | — | — | — | — | — |
| Catalog | `product_qa` (N), `reviews` | F | A | — | — | — | A | A | S·V |
| Inventory & fulfilment | `flash_sales` | F | V | — | F | — | — | — | S·V |
| Inventory & fulfilment | `warehouses`, `inventory_transfers`, `shipping_surcharges`, `fbn_inbound`, `fbn_marketplace` | F | F | V | — | — | — | — | S·V |
| Inventory & fulfilment | `fbn_storage_fees` | F | V | F | — | — | — | — | — |
| Inventory & fulfilment | `packaging_catalog`, `packaging_orders` | F | F | V | — | — | — | — | S·V |
| Orders & disputes | `orders` (view, status, assign shipping, force-cancel, flag fraud) | F | F | V | — | — | — | V | S·E |
| Orders & disputes | `orders.refund` | F | — | P | — | — | — | — | — |
| Orders & disputes | `disputes`, `returns`, `warranty_claims` | F | F | V | — | — | — | A | S·A |
| Users & vendors | `customers` (suspend, ban, loyalty, export, notify, QR, devices) | F | E | — | V | — | — | E | — |
| Users & vendors | `wishlists`, `notifications` (broadcast) | F | V | — | E | — | E | V | — |
| Users & vendors | `vendors` (suspend, blacklist, assign AM, lock sections, strikes, hold) | F | E | V | — | F | — | — | S·E (no blacklist, no AM assignment) |
| Users & vendors | `vendor_listings` | F | A | — | — | A | — | — | S·A |
| Users & vendors | `applications` (start review, assign to me, approve/reject) | F | — | — | — | A | — | — | S·A |
| Users & vendors | `change_requests`, `product_certifications` | F | A | — | — | A | — | — | S·A |
| Users & vendors | `seller_performance` (score, strikes, hold, blacklist) | F | E | — | — | F | — | — | S·V |
| Users & vendors | `acquisition_commissions` (rules, processing) | F | — | F | — | V | — | — | — |
| Users & vendors | `my_acquisition_commissions` | O | O | O | O | O | O | O | O |
| Users & vendors | `admins` (CRUD, sessions, password reset), `admins.impersonate` | F | — | — | — | — | — | — | — |
| Users & vendors | `roles` | F | — | — | — | — | — | — | — |
| Marketers & campaigns | `marketers` (approve, suspend, rules, contracts, exclusive) | F | — | V | F | V | — | — | — |
| Marketers & campaigns | `marketer_campaigns` (create platform, approve, invite, resend, samples) | F | V | V | F | — | — | — | S·V |
| Marketers & campaigns | `marketer_financials` (campaigns revenue) | F | — | F | V | — | — | — | — |
| Marketers & campaigns | `marketer_settings`, `marketer_jobs`, `cross_promotion` (N), `attribution` | F | — | V | F | — | — | — | — |
| Marketers & campaigns | `marketer_listings` (review), `content_submissions` (N), `marketing_agencies` (N) | F | — | — | A | — | — | — | — |
| Marketers & campaigns | `ad_packages` (incl. bank-transfer approval) | F | — | P | E | — | — | — | — |
| Marketing & ads | `marketing_fees` | F | — | V | F | — | — | — | — |
| Marketing & ads | `banners`, `cart_card_offers`, `newsletter`, `push` | F | — | — | F | — | E | — | — |
| Marketing & ads | `ad_campaigns`, `ad_slots`, `ad_bookings` (creative review, admin uploads video creative), `sponsored_ranking` | F | — | V | F | — | — | — | — |
| Marketing & ads | `travel_promotions` (D14: travel placement, options, promotions, cancel / refund) | F | — | V | F | — | — | — | — |
| Marketing & ads | `coupons`, `coupon_participation` | F | — | V | F | — | — | — | S·V |
| Marketing & ads | `vouchers`, `gift_cards` | F | — | V | F | — | — | V | — |
| Classifieds | `classified_categories`, `classified_listings` (review, attachments), `classified_reports` (N) | F | A | — | — | A | — | A | S·A |
| Real estate (N) | `properties`, `leads` | F | A | — | V | A | — | — | — |
| Contracts | `contract_templates` (author, publish, assign) | F | — | — | — | F | — | — | — |
| Contracts | `customer_contracts`, `contract_signatures` | F | V | — | — | V | — | V | S·V |
| Contracts | `document_types`, `onboarding_steps` (N), `join_options` (N) | F | — | — | — | F | — | — | — |
| Travel | `travel_agencies` (approve, suspend, change requests) | F | A | — | — | A | — | — | — |
| Travel | `travel_packages` (review), `travel_bookings`, `travel_inquiries` | F | A | V | — | — | — | V | — |
| Travel | `travel_destinations`, `inclusions`, `travel_categories`, `bookable_units` | F | E | — | — | — | E | — | — |
| Delivery & logistics | `delivery_agents` (create, verify docs, zones), `delivery_zones`, `delivery_assignments` | F | F | — | — | — | — | V | — |
| Delivery & logistics | `carrier_claims`, `carrier_scorecard`, `shipping_companies` | F | F | V | — | — | — | — | — |
| Finance | `payouts` (approve, process, hold, recalculate) | F | — | P | — | — | — | — | — |
| Finance | `delivery_payouts`, `cod_settlements` (mark settled) | F | V | P | — | — | — | — | — |
| Finance | `transactions`, `ledger`, `financial_reports`, `reconciliation` (N) | F | — | V/P | — | — | — | — | — |
| Finance | `wallets` (adjust, freeze, approve withdrawals) | F | — | P | — | — | — | — | — |
| Finance | `warranty_purchases`, `subscriptions`, `plans`, `invoices`, `membership` (Nawy One) | F | — | F | V | — | — | — | — |
| Content | `pages` (page builder), `app_contexts` | F | — | — | E | — | F | — | — |
| Content | `live_media` (streams, recorded channels, videos, homepage media) — **admin-only creation** | F | — | — | V | — | F | — | — |
| Content | `blog`, `knowledge_hub`, `help_center`, `faqs`, `static_pages`, `portal_content`, `footer`, `media_library` | F | — | — | E | — | F | E (help) | — |
| Content | `portal_analytics`, `advertise_inquiries` | F | — | — | V | V | V | — | — |
| Platform settings | `countries`, `cities`, `currencies`, `tax_rates`, `calendars` (Countries & locales) | F | V | V (tax) | — | — | — | — | — |
| Platform settings | `shipping_zones`, `shipping_methods`, `shipping_settings`, `weight_slabs`, `shipping_subsidies`, `vendor_zone_alerts` | F | F | V | — | — | — | — | — |
| Platform settings | `payment_gateways` (credentials, test, webhook logs) | F | — | E | — | — | — | — | — |
| Platform settings | `settings` (global and country settings catalogue) | F | per-key (see `settings-catalogue.md`) | | | | | | — |
| Platform settings | `notification_templates` (N), `domains` | F | — | — | E | — | E | — | — |
| Platform settings | `activity_log` | F | V | V | — | — | — | — | — |
| System health (N) | `system_health` (queues, failed jobs, API logs, security events, backups) | F | V | — | — | — | — | — | — |
| Support | `tickets`, `help_center_categories`, `help_center_articles` | F | V | — | — | — | E | F | S·E |
| Documentation | `docs` (in-app guides) | V | V | V | V | V | V | V | V |

### 3.3 Account-manager restrictions (SRS §3, §16; MP §2)
An account manager is an `admin` membership with the restriction permission `admin.restricted_to_assigned_vendors` plus `finance.hidden`.

| Area | Rule |
|---|---|
| Vendor scope | Sees only vendors where `vendors.account_manager_id = me` (per country). All vendor-linked lists (listings, applications, change requests, certifications, signatures, product requests, orders, returns, disputes, warranty claims, FBN inbound, packaging orders, coupons, campaigns) are filtered to those vendors by a global scope **and** re-checked in Policies. |
| Applications | Sees applications assigned to them, and unassigned ones only if they hold `applications.assign_to_me` (proposed). |
| Orders | Sees sub-orders of assigned vendors; can update fulfilment status and contact; cannot refund or force-cancel (proposed). |
| Finance hidden | No access to Finance group (payouts, ledger, wallets, transactions, COD settlements, reports, subscriptions, invoices), marketing fees, campaign financials, acquisition rules. On allowed pages, money fields that reveal platform economics (commission %, commission amount, payout net, fees, subsidy share, campaign fee) are **masked**. Customer-facing prices and order totals stay visible (proposed). |
| Dashboard | Work-queue widgets for assigned vendors only; no revenue charts. |
| Own earnings | "My Acquisition Commissions" stays visible (own data only). |
| Forbidden actions | Assign account managers, blacklist vendors, edit roles/admins, gateway credentials, country settings, impersonate. |
| Exports | Only for lists already scoped to assigned vendors, with masked money columns. |

### 3.4 Country scoping for admins
- An admin works in one country at a time (in-panel switch). Super admins see all 8; others only countries in their memberships.
- Central entities (countries, domains, gateway credentials, contract templates, document types, settings, notification templates, admins, roles) are edited in central context; country-scoped settings require a membership in that country.
- Cross-country reports come from `nawy_reporting` and need `analytics.cross_country` (super and finance by default, proposed).

---

## 4. Tenant scoping rules (all panels)

| Rule | Detail | Source |
|---|---|---|
| T1 Spatie teams | Spatie Permission "teams" key = **country + tenant**. Admin roles: team = country. Vendor roles: team = vendor (in its country). Agency roles: team = agency. Carrier: team = shipping company. Marketing agency: team = agency. | MP §3 Roles §16 (I) |
| T2 Role-name prefix | Tenant-scoped role names are prefixed (e.g. `v:{vendor_id}:catalog`, `ta:{agency_id}:bookings`) so they never collide across tenants. | SRS §16 |
| T3 Owner bypass | Vendor owner and travel agency owner bypass permission checks inside their tenant (SRS §16, §3). Carrier **owner supervisor** has full rights incl. creating supervisors (SRS §4.7). | SRS §16, §4.7 |
| T4 Ownership policies | Every Policy checks `resource.tenant_id == membership.tenant_id` **and** `CountryContext == membership.country`. A guessed ID from another tenant or country returns 404 (proposed, to avoid enumeration). | NFR-3, Spec §4.1 |
| T5 Country isolation | Operational queries run on the country DB selected by `CountryContext`; a membership of country X can never open data from country Y (E2E 18). | ADR 2 |
| T6 Vendor type gate | Pages marked product-only or classified-only are hidden by `vendor.type` middleware (SRS §4.3). | SRS §4.3 |
| T7 Marketer type gate | Features depend on the marketer's job types (influencer / affiliate / broker / agency) and `global_status` (§7). | SRS §11.1, §11.2 |
| T8 Delivery agent | Sees only shipments assigned to them (Spec §8.2); customer phone/address visible only while the assignment is active (proposed). | Spec §8.2 |
| T9 Customer | Own orders, bookings, inquiries, chats, contracts, wallet only; guest cart by token. | SRS §4.8 |
| T10 Locked profile sections | Vendor and agency locked sections (bank accounts, legal identity) change only via admin-approved change requests. | SRS §4.1.5, §12.2 |
| T11 Click-wrap gate | While a mandatory Seller Agreement version is unsigned, every partner page and the vendor app redirect to the consent page (owner signs). | SRS §4.3, MP §13 |
| T12 Media rule | No vendor, marketer or agency role can create streams, recorded channels or videos; only listing media (ADR 11). | ADR 11 |

---

## 5. Partner panel (`partner.`) — Seller Center / Marketplace

### 5.1 Roles
| Role | Who | Notes |
|---|---|---|
| Owner | Vendor account creator | Bypasses checks (T3). Only the owner signs the Seller Agreement, manages bank accounts, API tokens, team and roles (proposed). |
| Custom roles | Created by owner in Partner → Roles | Built from vendor permissions (`vendor.can:*`). |
| Proposed default templates | Manager, Catalog, Orders & fulfilment, Finance, Marketing, Viewer | Seeded per vendor; owner may edit. |

### 5.2 Permission groups × default roles
Type column: **P** product vendor only, **C** classified vendor only, **B** both (SRS §4.3).

| Group | Permission group | Type | Owner | Manager | Catalog | Orders & fulf. | Finance | Marketing | Viewer |
|---|---|---|---|---|---|---|---|---|---|
| Main | `dashboard`, `notifications` | B | F | F | V | V | V | V | V |
| Sales | `orders` (confirm, pack, ready, cancel*, print) | P | F | F | — | F | V | — | V |
| Sales | `returns`, `warranty_claims` (own listings only), `claims`, `disputes` | P | F | F | — | F | V | — | V |
| Catalog | `listings` (create, edit, price, stock, badges) | P | F | F | F | V | — | V | V |
| Catalog | `product_requests`, `size_guides`, `inventory` | P | F | F | F | E (inventory) | — | — | V |
| Catalog | `change_requests` | B | F | E | — | — | — | — | V |
| Catalog | `ai_credits`, `weight_calculator` | P | F | F | F | F | — | F | V |
| Fulfilment & storage | `fulfilment` (FBN inbound), `warehouses`, `transfers`, `city_surcharges`, `exceptional_zones`, `packaging` | P | F | F | V | F | V (fees) | — | V |
| Vendor campaigns | `campaigns` (wizard, edit/cancel pending, cancel active, pause/resume, invite, samples, approve marketer request) | B | F | F | — | — | V | F | V |
| Marketing | `flash_sales`, `coupons`, `coupon_participation`, `ads` | P | F | F | — | — | V | F | V |
| Contracts | `legal` (sign Seller Agreement) | B | F | V | V | V | V | V | V |
| Contracts | `pending_contracts` (category/product contracts), `contract_history` | B | F | F | E (sign category) | — | — | — | V |
| Advertising | `nawi_ads`, `ad_bookings` (book, upload image creative, pay) | B | F | F | — | — | V | F | V |
| Open Market | `classifieds` (create after contract, mark sold, pause, boost), `inquiries`, `conversations` | C | F | F | F | — | — | V | V |
| Other | `performance`, `support` | B | F | F | V | V | V | V | V |
| Finance | `payouts`, `transactions`, `sales_report`, `wallet`, `subscription` | B | F | V | — | — | F | — | — |
| Developer | `api_tokens` | P | F | — | — | — | — | — | — |
| Settings | `profile` (locked sections via change request), `shipping_companies`, `payment_methods`, `documents` | B | F | E | — | E (shipping) | E (payment) | — | V |
| Settings | `team`, `roles`, `bank_accounts` | B | F | — | — | — | V (bank) | — | — |

\* Vendor cancel hurts the seller score (SRS §4.3).
Video upload: listing video only (ADR 11). Sponsored **video** creative for an ad booking is uploaded by admin.

---

## 6. Travel agency panel (`travel-agency.`)

| Permission group | Owner | Member (proposed default: Operations) | Member (proposed: Finance) |
|---|---|---|---|
| `dashboard` | F | V | V |
| `packages` (create, submit for review), `departures` & capacity, `optional_services` | F | F | — |
| `bookable_units` | F | F | — |
| `bookings` (documents, confirm, complete, cancel, modifications) | F | F | V |
| `inquiries` | F | F | — |
| `promotions` (D14 paid placement: buy, pay by card, receipts) | F | F | V |
| `campaigns` (create, quote, pause, resume, cancel, export) | F | E | V |
| `finance` (wallet, payouts, revenue, sales report, operator settlement) | F | — | F |
| `reports`, `performance` | F | V | V |
| `team`, `roles` | F | — | — |
| `bank_accounts` | F | — | V |
| `profile` (locked via change requests) | F | V | V |
| `support` | F | F | F |

Owner bypasses checks; members checked through `travel_agency.can:*` (SRS §16).

---

## 7. Marketer panel (`marketer.`) — Creator Studio / Broker / Agency

Access depends on **job types** and **status**. Status gate (SRS §4.4, FR-MKT-1): `pending` → only Profile, Onboarding, Contract, Support; `active` + current onboarding contract accepted → all features of the held types; `suspended` → read-only finance and support.

| Feature (permission group) | Influencer | Affiliate | Broker | Agency (manages marketers) |
|---|---|---|---|---|
| Dashboard, notifications, profile (social accounts, ad price) | ● | ● | ● | ● (per managed marketer + roll-up) |
| Onboarding contract (`/contract`) | ● | ● | ● | ● (own) |
| Invitations (accept/reject, signed WhatsApp link) | ● | ● | ● | ● on behalf (proposed, with marketer consent) |
| Active / finished campaigns, campaign detail, referral link/QR | ● | ● | ● | ● |
| Join open campaigns (origin F) / campaign marketplace applications | — | ● | ● | ● |
| Request a campaign (origin D) | ● | ● | ● | ● |
| Peer products / promoted by others (origin E) | ● | — | — | ● (influencers only) |
| Cross-promotion participation dashboard | ● (if eligible by policy) | policy-dependent | policy-dependent | ● |
| Samples (address, confirm receipt) | ● | ● (affiliate sample) | — | ● |
| My listings, inventory, orders, product requests (own selling) | ● (incl. own FBN) | ● (independent listings) | ● | — |
| Classified listings, inquiries, wanted listings | ● | ● | ● | — |
| Properties, leads pipeline, follow-ups (N) | — | — | ● | — |
| Special requests (start / claim) | — | — | ● | — |
| Exclusive contracts (view, download) | ● | ● | ● | V |
| Conversations (customer chat) | ● | ● | ● | ● |
| Promote (Nawi ad slots), ad packages | ● | ● | ● | ● |
| Flash sales (bonus), coupon participation, marketer coupons | ● | ● | ● | ● |
| Content submission (N) | ● | ● | ● | ● |
| Media library (admin-curated; browse and share links only) | ● | ● | ● | ● |
| Contracts (onboarding versions, collaboration contracts) | ● | ● | ● | ● |
| Commissions, wallet (pending/spendable, withdrawals), reports | ● | ● | ● | ● (agency view; withdrawals by marketer, proposed) |
| Live / videos creation | — | — | — | — (ADR 11) |
| Support tickets | ● | ● | ● | ● |

Agency switcher: an agency user switches into a managed marketer; actions are logged with both identities (proposed).

---

## 8. Carrier panel (`carrier.`) — Shipping + Ops

| Permission group | Owner supervisor | Supervisor (normal) |
|---|---|---|
| Dashboard (active deliveries, agent count, SLA) | F | V |
| Agents (create, suspend, activate; no cap) | F | F |
| Supervisors (create) | F | — (SRS §4.7) |
| Assignments / unassigned (assign, reassign, track) — Ops dispatch | F | F |
| Failed deliveries, reschedule, return-to-origin | F | F |
| Pickup requests, shipments, tracking events | F | F |
| COD monitoring, reconciliation, settlements | F | V |
| Claims and scorecard | F | V |
| Company settings, service zones, pricing (N) | F | — |
| Invoices, reports | F | V |
| Users & permissions (N) | F | — |
| Support | F | F |

---

## 9. Delivery agent (`delivery.` web + delivery app)

| Capability | Rule |
|---|---|
| Dashboard / my day, availability toggle | Own only |
| Assignments | Accept/decline, pickup (scan), deliver; only assigned shipments (T8) |
| Delivery code | **Sees** the 4-digit code; customer reads theirs aloud; agent taps **"Codes match"**; never types it (ADR 15) |
| Proof of delivery | Photo or signature required for COD/high-value |
| Can't deliver | Reason, reschedule, return to origin |
| COD | Record collected cash; view settlements (`pending → settled / disputed`) |
| Earnings, wallet, withdrawals | Own only |
| Profile | Documents, vehicle, live GPS |

---

## 10. Customer (storefront + customer app)

| Capability | Guest | Registered (email unverified) | Registered (email verified) |
|---|---|---|---|
| Browse, search, PDP, live player, directory | ● | ● | ● |
| Cart (token, 15-min locks) | ● | ● | ● |
| Classified inquiry (5/min throttle; contract gate) | ● | ● | ● |
| Checkout, booking, purchase gift card | — | — (email gate) | ● |
| Account area (orders, returns, disputes, warranty, bookings, contracts, chats, special requests, tickets, notifications, payments, credits, QR, security & devices, Nawy One, wishlist groups) | — | ● | ● |
| Reviews, Q&A | — | ● (proposed: purchase required for reviews) | ● |
| Live comments and likes | ● (30/min) | ● | ● |

---

## 11. Portal (`business.`)

Public, no login (PORTAL_PAGES). Admin-side controls: `join_options` (per-country section toggles), `onboarding_steps`, `document_types`, `portal_content`, `portal_analytics`, `advertise_inquiries`, `knowledge_hub`, `help_center`, `blog`, `static_pages`.

## 12. Test obligations (Spec §21.3)
For every row above: one allowed and one denied Playwright/feature test per role, plus country-switch denial and cross-tenant ID tampering (404) tests. Account-manager masking is tested on orders, vendors and dashboard.
