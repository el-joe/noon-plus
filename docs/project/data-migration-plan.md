# Data migration plan — old Nawy Marketplace → new Nawy platform
Status: **Approved to plan** (user decision D1, 2026-11-14) · Owner: DB Architect + BA/PM · QC gate: Problem Solver/QC

## 1. Source and target
| | Source (old) | Target (new) |
|---|---|---|
| Code | `/var/www/marketplace` @ `c0fe74c1` (same commit as the SRS) | `/var/www/noon-plus` |
| Database | `marketplace_platform_live`: **356 tables** (327 in `database/schema/mysql-schema.sql` + later migrations), single DB with row-level country | `nawy_central` + `nawy_{cc}` per country (+ `nawy_reporting`) |
| Files | Laravel public and private disks (contracts, documents, media, stream recordings) | Object storage, prefix per country; private disk for contracts and documents |
| Inventory | `docs/project/migration/old-table-inventory.csv` (generated from the schema, v0) | — |

Inventory v0 counts:
- 300 country-scoped
- 27 central
- 12 identity (merge)
- 16 drop (transient)
- 1 archive (retired feature)
- **85 need a rule review** (§4.3)

## 2. Principles (non-negotiable)
1. **Never touch the live DB.** We read from a **snapshot** (`nawy_legacy_snapshot`) restored from the old project's `dump-database.sh` output on this server. The live DB stays untouched, so rollback is always possible.
2. **Re-runnable and idempotent.** Every step can run again. Each step upserts by `migration_id_map (source_table, source_id, target_db, target_table, target_id, checksum, migrated_at)`.
3. **Keep legacy IDs.** Every migrated row gets a `legacy_id` (and `legacy_table` where needed) for support, audit and URL redirects.
4. **Money is exact.**
   - Decimals are copied as-is. **Currencies are never converted.**
   - Rounding is not recomputed: historical amounts are facts.
   - Snapshotted fees, commissions and settlement terms are copied, not recalculated.
5. **History stays append-only.** Ledger entries, wallet transactions, inventory movements, status histories, page revisions, the activity log and contract signatures are migrated as they are. Signature SHA-256 hashes are **re-verified** against the frozen copies.
6. **Privacy.**
   - Data never leaves this server.
   - Credentials come from env only.
   - Reports mask PII (phone, email, IBAN).
   - Card data does not exist (gateway tokens only).
7. **Quarantine, don't guess.** A row whose country or owner can't be resolved goes to `migration_quarantine` with a reason. It is never silently dropped or misplaced.

## 3. Tooling
- A `App\Domains\Migration` module in the new backend: one **Step class per domain**, run with `php artisan legacy:migrate {step} --country=uae --dry-run --since=`.
- Connections: `legacy` (snapshot, **read-only DB user**), `central`, and `country_{cc}`.
- A step pipeline with dependencies:
  `reference → identity → catalog → listings → inventory → carts/wishlists → orders → payments → finance → marketing → marketers/campaigns → contracts/documents → classifieds → travel → delivery → content → streams → files`.
- Each step writes metrics: rows read, written, skipped and quarantined, plus sums per currency.
- `legacy:reconcile` produces the validation report (§8). `legacy:redirects` builds the 301 map (§7).

## 4. Country split rules
Each table gets one rule class (column `country_resolution` in the inventory):

| Rule | Meaning | Examples |
|---|---|---|
| **R1 direct** | Row has `country_id` / `country_code` | orders, vendor_listings, banners, coupons (79 tables) |
| **R2 via parent FK** | Country of the parent row | order_items → sub_orders → orders; invitations → marketer_campaigns; booking items → travel_bookings |
| **R3 via owner (polymorphic)** | Country of the owner (vendor, marketer, agency, customer) | wallets + wallet_transactions (owner × currency → country by currency), notifications, device_tokens, files, support_tickets, documents |
| **R4 replicate reference** | Copy to **every launched country DB**; per-country settings decide visibility | categories, attributes, attribute_values, category_attributes, category_shipping_methods, brands, classified categories and attribute definitions/maps, travel countries/cities/categories/inclusions, warranty_plans, storage-fee rules, ad/marketer ad packages, footer links, FAQs, content settings, app_contexts |
| **R5 replicate where used** | Copy a global row only into countries where it is referenced | products, product_variants, images, specifications, size guides → countries that have a listing or per-country product settings |
| **R6 central** | Central DB | countries, cities, currencies, exchange rates, languages, roles and permissions, settings, payment_gateways (→ `country_gateway_configs` split by country), document types, contract templates and versions (country column kept), marketer jobs, subscription plans |
| **R7 identity merge** | Central `users` + `role_memberships` + a country profile row | customers, vendors, vendor_admins, marketers, marketer_admins, marketer_profiles, delivery_agents, travel_agencies/members, shipping companies/supervisors, admins |
| **R8 drop / archive** | Transient, or a retired feature | cache, sessions, jobs, tokens, OTPs, stock locks, password resets; `affiliate_promo_codes` → archive schema |

### 4.1 Ledger and wallet specifics
- `ledger_entries` are grouped by transaction group, and each group goes to the country of its source event (R2/R3). We assert **Σdebit = Σcredit per group** after the move.
- Each wallet is mapped to an owner × currency. Its balance in the new system is a **projection of the migrated wallet_transactions**. The migration must reproduce the old `balance_after` exactly (§8).

### 4.2 Multi-country actors
One person is one `users` row, with memberships and country profiles in each country where they have data. For example, a customer with orders in UAE and Oman gets 2 country profiles. A vendor selling in 2 countries keeps 1 identity and gets a vendor profile in each.

### 4.3 Review list (85 tables, to close in MG1)
The heuristic could not resolve these, so each needs an explicit rule. Most fall under R4, R5 or R3:
- `ab_tests`/`ab_test_results`, `ad_campaign_*` targets, `advertise_inquiries`, `agent_location_history` (R3 → agent; **may be archived only**), `ai_*`, `app_contexts`, catalog references, `custom_pages`, `delivery_agent_*` (R3), `device_tokens`, `files`/`files_hashes`, `gift_cards` (R3 by currency), `inventory_movements` (R2 via listing/warehouse), `ledger_entries` (§4.1), `live_streams` and radio slots (assign to the platform's home country, or replicate if multi-country), `notifications` (R3, last 90 days only?), `page_block_*` (R2 via page), `payment_gateway_webhook_logs` (last 90 days?), `products` (R5), `shipping_*` (R4), `slugs` (redirect map), `support_tickets` (R3), `wallets` (§4.1), `webhook_deliveries` (drop?)

## 5. Identity consolidation (R7)
1. Normalise phones to E.164 and lower-case emails.
2. Match key: **verified email first** (customers now log in by email), then verified phone as a secondary signal. Accounts of different types with the same person merge into one user with several memberships.
   - **Old phone-only customers** (no email) are migrated with a `requires_email` flag. On their first visit they sign in with a one-time phone code (legacy path, migration-only) and must add and verify an email. After that, email is their login.
3. Conflicts (same email with different phones, and so on) go to a **manual review queue** in Admin → Migration. Nothing auto-merges on a weak signal.
4. **Passwords:** Laravel bcrypt hashes are copied, so users keep their passwords. Algorithm compatibility is verified in MG2. Sessions, JWT, API and device tokens are not migrated, so everyone logs in once.
5. **Roles:** per-guard Spatie roles map to new roles with team = country. Tenant-prefixed vendor and agency roles map to tenant-scoped roles. System admin roles map 1:1. Custom roles are copied with permissions translated through a permission-name map.
6. Account states (active / suspended / banned / pending) and strikes carry over.

## 6. Domain mapping highlights
| Domain | Notes |
|---|---|
| Orders | orders, sub_orders, order_items, item allocations, custom inputs and status histories (with actor) copied. Master roll-up recomputed and **asserted equal** to the old status. |
| Payments | payment_transactions and refunds copied with gateway references. Gateway credentials are **re-entered** in the new admin (never copied in plain text). Webhook logs: last 90 days. |
| Finance | Payouts, payout items, COD settlements, agent earnings and payouts, acquisition commissions, subscriptions and invoices, tax invoices copied. Open (unpaid) payout statements are **closed in the old system before cutover**. |
| Marketers & campaigns | Profiles, jobs, rules, defaults, campaigns, invitations, samples, conversions, settlements, fines and reminders.<br>**Referral codes are preserved**, so existing `/r/{code}` links keep working.<br>Status map: legacy `completed` → `done`.<br>Budgets (`commission_budget_spent`, `influencer_fixed_reserved`) and fee snapshots copied as-is. |
| Contracts | Templates and versions (central), assignments, every signature table. Frozen copies and files are moved and **hash-verified**. |
| Classifieds / travel | Listings, images, attachments, inquiries, wanted ads, exclusive contracts; agencies, packages, pricing tiers, media, bookings, inquiries, bookable units and reservations. |
| Delivery | Agents (identity), zones, assignments, shipments and tracking events, COD settlements, claims, scorecards. |
| Marketing | Coupons (+ usages), participation invitations and requests, flash sales (+ submissions and histories), banners, ad campaigns (+ stats), paid slots and bookings, gift cards (+ transactions; **codes stay hashed or encrypted**), vouchers. |
| Content | Pages, sections and blocks mapped onto the new block registry through a `block_type_map`. Unknown types become an `html` block flagged for review. Blog, help centre, knowledge hub, FAQs, static pages, footer. |
| Streams | Live streams (ended), comments, likes; recorded channels and media files (durations re-read). |
| Settings | Old setting keys → new settings keys (map file). Unknown keys are reported. |

## 7. URLs, SEO and links
- `slugs` and old routes become a **301 redirect map**: `/products/[slug]` → `/{cc}-{lang}/{slug}/{SKU}/p`, plus seller, marketer, classified and travel URLs.
- `/r/{code}` referral links are preserved exactly. WhatsApp invitation links that are still pending are re-signed and re-sent, or expired by policy.
- Sitemaps are regenerated, and Search Console is updated at cutover.

## 8. Reconciliation (must be 100% before go-live)
1. **Row counts** per table per country: source vs target, plus quarantine. Every difference must be explained.
2. **Money:**
   - Σ order totals, Σ payments, Σ refunds, Σ payouts per currency per month.
   - Every wallet's projected balance equals the old balance.
   - Ledger Σdebit = Σcredit per group.
   - Campaign budget spent and reserved are equal.
3. **States:** the distribution of each lifecycle status is equal (orders, sub-orders, listings, campaigns, invitations, conversions, bookings, classifieds).
4. **Integrity:**
   - No orphan foreign keys.
   - Every signature hash verifies.
   - Every migrated file exists and its checksum matches.
5. **Spot checks:** 50 random orders, vendors, marketers and campaigns compared field by field. A UAT script for business owners covers vendor payout, marketer wallet and customer order history.
6. Output: `storage/migration/reports/{run_id}/reconciliation.html` plus CSVs, archived per run.

## 9. Cutover runbook
| When | Action |
|---|---|
| MG8, T-21 | Dry run #1 on the snapshot; fix mappings; target: zero unexplained reconciliation differences |
| T-10 | Dry run #2 on a fresh snapshot + UAT by the owner and finance; performance timing (target window ≤ 4 h) |
| T-3 | Old system: process all approved payouts, stop new campaigns, announce the maintenance window |
| T-0 | **Freeze:** old system in maintenance (read-only), scheduler and queue stopped. Final snapshot → full migration run → reconciliation → sign-off (BA, Finance, QC) |
| T-0 + window | DNS / subdomain switch to the new system; enable the new scheduler; smoke tests per panel |
| T+30 days | Old system kept read-only for lookups, then archived |
| Rollback | Switch DNS back to the old system (its DB was never written to). The new DB is discarded, and nothing is lost. |

**In-flight objects at freeze:**
- Pending payments are expired.
- Carts: migrate items, drop locks.
- Open orders continue with the same state.
- Active campaigns continue with the same codes and budgets.
- Pending conversions keep their status and are approved by the new jobs.
- Pending withdrawals are processed before freeze or migrated as pending.
- Scheduled pages and banners keep their schedules.

## 10. Testing
- Build an **anonymised fixture snapshot** (a subset of each table with masked PII) and use it in CI. Every migration step gets a test.
- **Golden tests:** wallet projection equals the old balance; order roll-up equals the old status; campaign budget equality.
- **Idempotency test:** run twice and get zero changes on the second run.
- **Quarantine test:** malformed rows are captured, never dropped.

## 11. Work plan (each MG lands after its domain exists in the new code)
| # | Task | Depends on |
|---|---|---|
| MG1 | Close the review list (§4.3); permission and setting maps; block_type_map; status maps; field-level mapping docs per domain | Plan v2 ✅ (inventory v0 done) |
| MG2 | Migration framework (snapshot connection, id map, quarantine, metrics, reports) + identity consolidation + review queue UI | M6 foundation |
| MG3 | Reference data, catalog, listings, inventory, warehouses/FBN | M7 |
| MG4 | Carts, wishlists, orders, payments, returns, refunds, disputes, warranty, reviews | M7–M8 |
| MG5 | Finance: wallets, ledger, payouts, COD, subscriptions, acquisition commissions | M8 |
| MG6 | Marketers, campaigns, conversions, contracts, documents | M9 |
| MG7 | Classifieds, travel, advertising, coupons, flash sales, gift cards | M10 |
| MG8 | Delivery, carriers, content, page builder, streams, files + redirects | M11–M13 |
| MG9 | Dry runs, reconciliation, UAT, cutover | M15 |

## 12. Open questions for the owner
1. **Snapshot access.** Run `dump-database.sh` on this server, or create a **read-only** DB user for a snapshot copy? We never use the live credentials from the old `.env`.
2. **Countries live in the old DB.** Which countries have real data? Currencies KWD, QAR, BHD and JOD exist; provision those country DBs too?
3. **History depth** for high-volume logs (activity_log, notifications, webhook logs, agent GPS history, product views): all, or the last N days?
4. **Old customer URLs** (`nawyweb.com`): does the domain stay, or do we move to a new domain with redirects?
5. **Downtime window** acceptable for cutover (default ≤ 4 h at night).
