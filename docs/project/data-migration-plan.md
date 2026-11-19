# Data migration plan — old Nawy Marketplace → new Nawy platform
Status: **Built and run on dev (WP11, 2026-11-18)** — approved to plan (user decision D1, 2026-11-14) · Owner: DB Architect + BA/PM · QC gate: Problem Solver/QC

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
  `reference → identity → catalog → listings → inventory → carts/wishlists → orders → payments → finance → marketing → marketers/campaigns → contracts/documents → classifieds → travel → delivery → support → content → streams → files`.
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
- The map (`legacy_redirects`) is served by the new platform itself (§13.4): the website 301s old storefront URLs before its 404, the panels 301 old portal paths; an nginx map is optional.

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

## 13. WP11 implementation (built 2026-11-18)

Code: `backend/app/Domains/Migration` — `Steps/*Step.php` (one per domain), `Support/` (LegacySource, MigrationContext, IdMap, TargetWriter, Quarantine, Archiver, CountryResolver, TableRules + `table-rules.php`, SettingKeyMap, BlockTypeMap, LegacyFiles, Reconciler, RedirectMap), `Console/` (`legacy:migrate`, `legacy:reconcile`, `legacy:redirects`). Runbook: `docs/deploy/migration-runbook.md`. Report: `docs/project/migration/reconciliation-report.md`.

### 13.1 Framework decisions
- **Dispositions.** Every one of the 359 legacy tables (inventory v0 + 3 snapshot-only `marketing_fee_*` tables) has a rule in `table-rules.php`: `map` (field mapping into new tables), `fold` (carried by another table's mapping), `verify` (checked against config, not copied), `archive` (copied as it was into `legacy_records` of its country, or the central DB for global rows) or `drop` (R8 transient). Archiving keeps every row and its exact decimals for tables the new system has no field mapping for yet; a later mapping can read `legacy_records`.
- **Ids.** New rows get new integer ids; the legacy UUID is kept in `legacy_id` (users, vendors, products, variants, listings, orders, sub-orders, items, categories, brands, pages, blog posts, help articles, signatures, coupons, ad slots, zones, warranty plans, carriers, delivery agents, marketer profiles, contract templates) and in `migration_id_map` for every row. Unique values that clash with existing (demo) rows get a `-legacy` suffix (slugs, SKUs, carrier/ad-slot codes); coupon codes are never renamed (a clash is quarantined `duplicate_code`); reference data (categories, brands, blog categories, help collections, category attributes) is **linked** to an existing row with the same natural key and never overwritten.
- **Idempotency.** Each upsert stores a checksum of the target values: unchanged → nothing written; changed → updated in place (only rows the migration inserted). A second run on dev wrote 0 rows (1.6 s).
- **Country resolution.** R1 column (`NULL`/`*` = global → central archive or every country for content); R2 parent chain; R3 owner (morph map) with money rows assigned to their **currency's** country; R5 products = countries with an available `product_countries` row or a listing; vendors = own country + every listing country (one vendor row per country, one identity); shipping companies = own + `served_countries`.
- **Money.** Decimals copied as strings; no currency conversion ever (a currency that is not the country's → quarantine `currency_mismatch`). Sub-order totals are the sum of their legacy parts and must add up, with the COD fee and discounts, to the legacy order total (else `totals_mismatch`). Listings: legacy price + compare-at → new sale price + regular price.
- **Wallets / ledger (§4.1).** Wallet → owner account (customer_wallet / vendor_payable / marketer_payable) in bucket spendable (frozen when frozen); each wallet transaction → one balanced ledger transaction (owner leg keeps the legacy `balance_after`, counter leg on platform `wallet_clearing`). The chain is verified first; a wallet that does not reproduce its balance is quarantined with its transactions. Legacy `ledger_entries` groups → ledger transactions in bucket `legacy` (history only, so live balances are not counted twice); unbalanced / mixed-currency / unmapped-account (`gateway_fee`, `carrier_payable`) groups are quarantined whole.
- **Identity (§5).** Merge key = lower-cased email (users.email is the login). Same email + different phone → `identity_conflict` (manual review). Phone-only customers get `phone-{digits}@legacy.nawy.invalid` + `users.requires_email = true`; their first sign-in is the one-time phone code of §13.6. bcrypt hashes copied (`password_set_at` = legacy created_at). Admin roles: `super_admin` → country-less admin membership; operations/finance/marketing/vendor_relations → admin membership + role in every country (legacy admins have no country); custom roles (`aa`, `asdasdas`) have no assignment and are archived. Tenant roles: owner → `owner_user_id`; vendor manager → manager, staff → viewer; travel manager → operations, staff → viewer. Non-owner marketer staff logins → quarantine `membership_unsupported`.
- **Statuses.** Orders, sub-orders, listings, products use the same values; vendor `pending/under_review/inactive` → `on_hold`, `rejected` (an application, not a store) → quarantine; marketer `inactive/blacklisted` → `suspended` with the legacy status in `status_reason`; agent `inactive` → `suspended`; listing conditions like-new/good/acceptable → `used`.
- **Contracts.** Legacy template versions are imported as **drafts** (whatever their legacy publish state; the change summary says when it was published there) for admin review. A published global template, such as the legacy Seller Agreement "French · v1", would otherwise apply in every country at once and send every store, legacy or not, to re-sign. When an admin publishes the reviewed draft, migrated signatures of that same version stay active (`ContractPublisher` only supersedes other versions). Editor signatures verify as `sha256(rendered_content . json_encode(variables))` with the variables in the old `variablesFor()` order; file signatures as the file's SHA-256. Verified ones get a frozen copy on the private disk whose SHA-256 the new `ContractSigner::verify()` checks.
- **Category slugs.** The storefront's category URL segment is `[a-z0-9-]`, so legacy slugs with underscores (`smart_home`, `accessories_el`, 17 in the snapshot) are normalised (`smart-home`) by `CatalogStep::categorySlug()`. The normalised slug is the natural key for linking to an existing category; a slug held by another, non-migrated row gets the `-legacy` suffix. Rows written by an earlier run are renamed in place on the next run (checksum change). `legacy:redirects` maps `/{market}/{old_slug}` → `/{market}/{new-slug}`.
- **Content.** Pages → one page per locale with one revision; blocks through `BlockTypeMap` (unknown types / missing required settings → `html` block `needs_review`, page imported as **draft**; the legacy home pages had no slide/banner images, so all 8 are drafts). Portal help centre → hub `help_center` (seller audience); ad support → hub `knowledge_hub`. Static pages replace the seeded placeholders of the same slug, written pages are kept.
- **Settings.** `SettingKeyMap` maps the 10 keys with a new equivalent; credentials (mail, WhatsApp tokens) are archived redacted and must be re-entered; 40 keys with no new equivalent are archived (`unknown_setting_key`).
- **Paid ad slots.** Monthly/weekly priced slots (the classified "listing boost" packages) are archived (`pricing_model_unsupported`) — converting to a per-day price would be a recalculation.
- **Files.** Product images, blog covers etc. are referenced as `legacy/{old path}` on the public disk and copied with a SHA-256 check by the `files` step; missing ones are quarantined `file_missing`.

### 13.2 Review list (§4.3) — decisions for the 85 tables


| Table | Step | Rule | Decision | Country from |
|---|---|---|---|---|
| ab_test_results | content | R2 | archive: no field mapping in WP11: archived as it was in the country DB | via `ab_test_id` → ab_tests |
| ad_campaign_category_targets | marketing | R2 | archive: no field mapping in WP11: archived as it was in the country DB | via `ad_campaign_id` → ad_campaigns |
| ad_campaign_keywords | marketing | R2 | archive: no field mapping in WP11: archived as it was in the country DB | via `ad_campaign_id` → ad_campaigns |
| ad_fraud_patterns | marketing | R2 | archive: no field mapping in WP11: archived as it was in the country DB | via `ad_campaign_id` → ad_campaigns |
| ad_image_items | content | R2 | fold: blocks mapped through BlockTypeMap into the page revision zones | via `page_block_id` → page_blocks |
| ad_packages | marketing | R4 | archive: global data with no field mapping in WP11: archived once in the central DB | central DB |
| advertise_inquiries | reference | R4 | archive: global data with no field mapping in WP11: archived once in the central DB | central DB |
| agent_location_history | delivery | R2 | archive: no field mapping in WP11: archived as it was in the country DB | via `agent_id` → delivery_agents |
| ai_feature_credits | catalog | R3 | archive: no field mapping in WP11: archived as it was in the country DB | owner [owner_type, owner_id] |
| ai_image_enhancement_jobs | catalog | R2 | archive: no field mapping in WP11: archived as it was in the country DB | via `product_image_id` → product_images |
| app_contexts | content | R4 | archive: global data with no field mapping in WP11: archived once in the central DB | central DB |
| attribute_values | catalog | R4 | fold: folded into category_attributes (key, type, options) | via `attribute_id` → attributes |
| attributes | catalog | R4 | fold: folded into category_attributes (key, type, options) | central DB |
| blog_categories | content | R4 | map:  | central DB |
| bookable_unit_availability | travel | R2 | archive: no field mapping in WP11: archived as it was in the country DB | via `bookable_unit_id` → bookable_units |
| bookable_unit_photos | travel | R2 | archive: no field mapping in WP11: archived as it was in the country DB | via `bookable_unit_id` → bookable_units |
| bookable_unit_time_slots | travel | R2 | archive: no field mapping in WP11: archived as it was in the country DB | via `bookable_unit_id` → bookable_units |
| brands | catalog | R4 | map: replicated to every country DB; linked by slug when it already exists | central DB |
| cart_item_custom_attribute_values | carts | R2 | archive: no field mapping in WP11: archived as it was in the country DB | via `cart_item_id` → cart_items |
| categories | catalog | R4 | map: replicated to every country DB; linked by slug when it already exists | central DB |
| category_attributes | catalog | R4 | map: replicated to every country DB; linked by slug when it already exists | via `attribute_id` → attributes |
| category_shipping_methods | catalog | R4 | archive: global data with no field mapping in WP11: archived once in the central DB | central DB |
| classified_attribute_definitions | classifieds | R4 | archive: global data with no field mapping in WP11: archived once in the central DB | central DB |
| classified_categories | classifieds | R4 | archive: global data with no field mapping in WP11: archived once in the central DB | central DB |
| classified_category_attribute_map | classifieds | R4 | archive: global data with no field mapping in WP11: archived once in the central DB | central DB |
| content_settings | content | R4 | archive: global data with no field mapping in WP11: archived once in the central DB | central DB |
| custom_page_category_map | content | R4 | archive: global data with no field mapping in WP11: archived once in the central DB | central DB |
| custom_pages | content | R4 | archive: global data with no field mapping in WP11: archived once in the central DB | central DB |
| delivery_agent_cod_settlements | delivery | R2 | archive: no field mapping in WP11: archived as it was in the country DB | via `agent_id` → delivery_agents |
| delivery_agent_documents | delivery | R2 | archive: no field mapping in WP11: archived as it was in the country DB | via `agent_id` → delivery_agents |
| delivery_agent_payouts | delivery | R2 | archive: no field mapping in WP11: archived as it was in the country DB | via `agent_id` → delivery_agents |
| delivery_agent_shifts | delivery | R2 | archive: no field mapping in WP11: archived as it was in the country DB | via `agent_id` → delivery_agents |
| device_tokens | identity | R3 | archive: no field mapping in WP11: archived as it was in the country DB | owner [tokenable_type, tokenable_id] |
| faqs | content | R4 | archive: global data with no field mapping in WP11: archived once in the central DB | central DB |
| files | files | R3 | archive: no field mapping in WP11: archived as it was in the country DB | owner [model_type, model_id] |
| files_hashes | files | R2 | archive: no field mapping in WP11: archived as it was in the country DB | via `file_id` → files |
| flash_sale_submission_histories | marketing | R2 | archive: no field mapping in WP11: archived as it was in the country DB | via `flash_sale_submission_id` → flash_sale_submissions |
| footer_links | content | R4 | archive: global data with no field mapping in WP11: archived once in the central DB | central DB |
| gift_card_batches | marketing | R4 | archive: global data with no field mapping in WP11: archived once in the central DB | central DB |
| gift_cards | marketing | R3 | map (§13.5): gift_cards in the currency's country; code hashed + encrypted | currency |
| international_shipping_rates | catalog | R4 | archive: global data with no field mapping in WP11: archived once in the central DB | central DB |
| inventory_movements | inventory | R2 | map: movements kept; on-hand gap posted as one legacy_opening movement | via `warehouse_inventory_id` → warehouse_inventories |
| ledger_entries | finance | R2 | map: groups → ledger_transactions in bucket "legacy"; Σdebit = Σcredit asserted | central DB |
| live_streams | streams | R4 | archive: global data with no field mapping in WP11: archived once in the central DB | central DB |
| marketer_ad_packages | marketers | R4 | archive: global data with no field mapping in WP11: archived once in the central DB | central DB |
| marketer_campaign_sample_custom_attribute_values | marketers | R2 | archive: no field mapping in WP11: archived as it was in the country DB | via `marketer_campaign_sample_id` → marketer_campaign_samples |
| marketer_conversation_messages | marketers | R2 | archive: no field mapping in WP11: archived as it was in the country DB | via `conversation_id` → marketer_conversations |
| marketer_marketer_job_category | marketers | R2 | archive: no field mapping in WP11: archived as it was in the country DB | via `marketer_marketer_job_id` → marketer_marketer_job |
| notifications | identity | R3 | archive: no field mapping in WP11: archived as it was in the country DB | owner [notifiable_type, notifiable_id] |
| open_market_listing_prices | marketers | R4 | archive: global data with no field mapping in WP11: archived once in the central DB | central DB |
| packaging_supply_request_items | inventory | R2 | archive: no field mapping in WP11: archived as it was in the country DB | via `request_id` → packaging_supply_requests |
| page_block_brands | content | R2 | fold: blocks mapped through BlockTypeMap into the page revision zones | via `page_block_id` → page_blocks |
| page_block_categories | content | R2 | fold: blocks mapped through BlockTypeMap into the page revision zones | via `page_block_id` → page_blocks |
| page_block_products | content | R2 | fold: blocks mapped through BlockTypeMap into the page revision zones | via `page_block_id` → page_blocks |
| page_block_sellers | content | R2 | fold: blocks mapped through BlockTypeMap into the page revision zones | via `page_block_id` → page_blocks |
| paid_ad_daily_stats | marketing | R2 | archive: no field mapping in WP11: archived as it was in the country DB | via `paid_ad_booking_id` → paid_ad_bookings |
| paid_banner_bookings | marketing | R4 | archive: global data with no field mapping in WP11: archived once in the central DB | central DB |
| payment_gateway_webhook_logs | payments | R2 | archive: no field mapping in WP11: archived as it was in the country DB | via `country_payment_gateway_id` → country_payment_gateways |
| product_image_hashes | files | R2 | archive: no field mapping in WP11: archived as it was in the country DB | via `product_image_id` → product_images |
| product_variant_attributes | catalog | R5 | fold: product_countries decides the R5 countries; variant attributes become option_values | via `product_variant_id` → product_variants |
| products | catalog | R5 | map: replicated to countries with a listing or a product_countries row | central DB |
| radio_schedule_slots | streams | R4 | archive: global data with no field mapping in WP11: archived once in the central DB | central DB |
| registration_step_events | identity | R2 | archive: no field mapping in WP11: archived as it was in the country DB | via `registration_progress_id` → registration_progress |
| return_request_message_attachments | orders | R2 | archive: no field mapping in WP11: archived as it was in the country DB | via `return_request_message_id` → return_request_messages |
| shipment_tracking_events | delivery | R2 | archive: no field mapping in WP11: archived as it was in the country DB | via `shipment_id` → shipments |
| shipping_fallback_rules | catalog | R4 | archive: global data with no field mapping in WP11: archived once in the central DB | central DB |
| shipping_methods | catalog | R4 | archive: global data with no field mapping in WP11: archived once in the central DB | central DB |
| shipping_rates | catalog | R1 | map: zones by country_id; rates by destination zone (origin = destination only) | central DB |
| size_guide_rows | catalog | R2 | archive: no field mapping in WP11: archived as it was in the country DB | via `size_guide_id` → size_guides |
| slider_slides | content | R2 | fold: blocks mapped through BlockTypeMap into the page revision zones | via `page_block_id` → page_blocks |
| slugs | content | R6 | fold: become 301 redirects (legacy:redirects) | central DB |
| storage_fee_free_period_rules | finance | R4 | archive: global data with no field mapping in WP11: archived once in the central DB | central DB |
| support_tickets | support | R3 | map (§13.5): support_tickets of the requester | related order, else owner [requester_role, requester_user_id] |
| ticket_attachments | support | R2 | archive: files, archived as they were in the country DB | via `ticket_message_id` → ticket_messages |
| ticket_messages | support | R2 | map (§13.5): moved with their ticket | via `ticket_id` → support_tickets |
| travel_categories | travel | R4 | archive: global data with no field mapping in WP11: archived once in the central DB | central DB |
| travel_cities | travel | R4 | archive: global data with no field mapping in WP11: archived once in the central DB | central DB |
| travel_countries | travel | R4 | archive: global data with no field mapping in WP11: archived once in the central DB | central DB |
| travel_inclusions | travel | R4 | archive: global data with no field mapping in WP11: archived once in the central DB | central DB |
| vendor_api_tokens | identity | R2 | archive: no field mapping in WP11: archived as it was in the country DB | via `vendor_admin_id` → vendor_admins |
| vendor_exceptional_zone_alert_results | identity | R2 | archive: no field mapping in WP11: archived as it was in the country DB | via `alert_id` → vendor_exceptional_zone_alerts |
| vendor_listing_addon_options | identity | R4 | archive: global data with no field mapping in WP11: archived once in the central DB | central DB |
| wallets | finance | R3 | map: wallet → ledger account (owner × currency); every transaction balanced against wallet_clearing; balance_after kept | owner [owner_type, owner_id] |
| warranty_plans | catalog | R1 | map: country_ids JSON → one plan per listed country | central DB |
| webhook_deliveries | payments | R4 | archive: global data with no field mapping in WP11: archived once in the central DB | central DB |

### 13.3 Open items
- ~~Phone-only legacy customers: the one-time phone-code first sign-in (§5.2)~~ — built, §13.6. Production SMS needs the owner's SMS provider account (`NAWY_SMS_DRIVER`; `log` on dev).
- ~~Field mappings for the tables archived with 0 rows in the snapshot~~ — built, §13.5 (payments, refunds, payouts, campaigns, invitations + referral codes, conversions, travel packages + bookings, classified categories + listings, gift cards, support tickets + messages).
- ~~Admin → Migration review queue UI for `migration_quarantine`~~ — built, §13.4.
- Still archived by decision (history only, readable in Admin → Data migration → Legacy records): `payout_items` (statement lines; the payout carries the total), `gift_card_transactions` (the card carries its balance), `travel_package_pricing_tiers`, `bookable_units` and unit days (no new equivalent), `ticket_attachments` (files).

### 13.4 Admin → Data migration and website redirects (WP11 extras, 2026-11-19)

**Admin panel** (`Data migration` sidebar group; permissions `data_migration.view` / `data_migration.manage`, held by super admins and the default Operations role; migration `2026_12_03_100100_seed_data_migration_permissions`):

| Page | Route | What it does |
|---|---|---|
| Quarantine queue | `admin.migration.quarantine` (`/migration/quarantine`) | `migration_quarantine` filtered by step, legacy table, country, reason, legacy id and status (open · marked resolved · waived · migrated by a later run). |
| Quarantine entry | `admin.migration.quarantine.show` | Reason + table rule, the legacy row with personal data **masked** (`PiiMask`; passwords / tokens never shown), "Reveal personal data" (POST, activity-logged `pii_revealed`), related ids (`*_id` → `migration_id_map` targets and other quarantined rows), decision form, re-try, audit trail. |
| Decision | POST `…/review` | `resolved` (fixed at the source, waiting for the re-run) or `waived` (accepted as not migrated), note required, actor + time stored (`review_status`, `review_note`, `reviewed_by`, `reviewed_at`) and activity-logged (`data_migration` log). `resolved_at` stays owned by the migration: a waived row still counts as quarantined in `legacy:reconcile`; a manually resolved row that the next run quarantines again is reopened. |
| Re-try one row | POST `…/preview`, then POST `migration/jobs/{job}/apply` | Runs the row's step for that one row (`MigrationContext::$only`, also `legacy:migrate {step} --row=table:id`): dry run first (in the request), apply repeats exactly that dry run once. A row that its step only moves with its parent (e.g. `parent_quarantined` children) reads 0 and the page says to re-try the parent / the step. |
| Re-try a step for a country | POST `migration/retries` → `migration/jobs/{job}` | Dry run queued on the `migration` queue (`RunMigrationJob`), result page refreshes until done, then "Apply" queues the real run. |
| Migration runs | `admin.migration.runs`, `admin.migration.runs.show` | `migration_runs` per step: countries / single-row scope, read · written · quarantined, Σ per currency, started / finished; detail = every table of the step and the other steps of the run. |
| Legacy records | `admin.migration.records`, `…records.show` | `legacy_records` of the central DB or a country DB, search by legacy table and id; read only, masked, reveal logged. |
| Legacy redirects | `admin.migration.redirects` | `legacy_redirects` search (path, source, country), download the `legacy:redirects` exports (JSON / nginx map), "Rebuild map" (queued `legacy:redirects`). |
| Reconciliation | `admin.migration.reconciliation` | Summary + the latest `reconciliation-report.md` rendered (`nawy.legacy.reconcile_report`), "Run reconcile" (queued `legacy:reconcile`, one at a time; exit code and masked output kept). |

Panel tasks are recorded in `migration_jobs` (kind row / step / reconcile / redirects, dry run, status, metrics, masked output, requester, preview it applies). Partial runs (one country, one row) resolve only the untouched quarantine entries they have now migrated (an id-map entry in one of their countries); a full step run resolves as before. Figma: "Admin data migration · quarantine queue" and "… · quarantine entry" (Panel layouts page, see `docs/design/figma-state.json` → `panelLayouts`).

**Redirects served by the platform** (no nginx map needed):
- `GET /api/v1/legacy-redirects?path=/uae-en/orders/{old-id}` → `{data: {from, to, status: 301}}` or 404 (`LegacyRedirects`: every answer cached per path for a day under a version that `legacy:redirects` bumps; `Cache-Control` public).
- Website (`website/proxy.ts` + `lib/legacy-redirects.ts`): a market path that `lib/missing-routes.ts` decides is missing is looked up before the rewrite to `/{market}/missing`; an un-prefixed old-portal path (`/{cc}/…`, `/helpcenter`, `/adsupport`, `/blog`, `/join`, `/vendors`, `/marketers`, `/companies`, `/faq`) is looked up before the market redirect. A hit answers 301 to the same-site target (query kept); answers are cached in memory for 10 minutes; errors fail open. Ordinary pages never call it.
- Laravel (panel and API hosts): `LegacyRedirectFallback` (404 renderer, GET/HEAD, not `api/*`) 301s any mapped old path to `nawy.payments.storefront_url` + target, keeping the query.
- E2E: `E2eSeeder` upserts `/uae-en/live-streams` → `/uae-en/live` (idempotent; the same entry `legacy:redirects` builds), `website/e2e/specs/flows/legacy-redirects.spec.ts` checks the 301.

### 13.5 Field mappings for the tables that were empty in the snapshot (2026-11-19)

Built so a live system that has rows in them migrates without archiving. All follow §2: ids kept in `migration_id_map` (and the legacy UUID as the payment `reference` / in `legacy_id` where the table has one), money copied exactly in its own currency (a currency that is not the row's country's → `currency_mismatch`), no conversion or recalculation, idempotent upserts (a second run writes nothing), and quarantine with a reason instead of guessing. Fields with no new column stay readable: the full legacy row is also kept in `legacy_records` of the same country (reason `mapped_with_legacy_fields`; gift-card codes masked). New steps: `payments`, `marketers`, `classifieds`, `travel`, `support` (new, after `delivery`); payouts in `finance`, gift cards in `marketing`.

| Legacy table | New table(s) | Country | Mapping | Quarantined (reason) |
|---|---|---|---|---|
| payment_transactions | `payments`, `payment_refunds` | R2 via order | sale / authorisation / stand-alone capture → payment (`reference` = legacy id, method from the gateway like orders, `gateway_reference`, captured / refunded amounts, status: succeeded sale → captured, authorisation → authorized → captured or voided by its capture / void rows, failed / cancelled → failed with `failure_code`); capture and void rows fold into their authorisation; gateway `refund` rows → `payment_refunds` of the order's one captured payment (status succeeded → completed, pending → pending, else failed) | chargeback (`shape_unsupported`), unknown gateway (`method_unmapped`), order not migrated (`parent_*`), refund with 0 or 2+ captured payments (`payment_ambiguous`) |
| refunds | `refunds` | R2 via order | number `RF-L{uuid hex}`, sub-order, customer of the order, `source_type` admin, amount = net refund (amount − gateway fee − tax; deductions kept in legacy_records), destination wallet when the refund transaction was on the wallet, status as is, `payment_refund_id` → the migrated gateway refund | order / sub-order not migrated |
| payouts | `vendor_payouts` | currency's country | number (unique), vendor in that country, status as is, schedule = the migrated vendor's, total = net amount, bank reference, approver, completed / failure / hold reasons; breakdown kept in legacy_records | vendor not in that country (`parent_unmigrated`), unknown currency |
| marketer_campaigns | `marketer_campaigns` | R1 | number `MC-L…`, origin vendor / platform / marketer_request / peer, owner, listing, budget, **budget spent and influencer fixed reserved copied**, fee amount + fee snapshot (subtotal, fixed, variable, total, grand total, commission type…), fee status pending → quoted, paid → charged, voided, refunded; statuses as is | travel (D11) and classified campaigns (`shape_unsupported`), no store listing, unknown owner / fee status; soft-deleted → archived |
| marketer_campaign_invitations | `campaign_invitations` + central `referral_codes` | campaign's | **referral code kept exactly** and registered centrally, so `/r/{code}` resolves to its country; an invitation that never had a code gets a stable internal one; effective type influencer (fixed amount) else the profile's per-sale type; status `replaced` → cancelled (closed_at = replaced_at); influencer fixed status paid → settled | code not 6–16 letters/digits (`code_unsupported`), code used by another link (`duplicate_code`), marketer not migrated |
| marketer_campaign_conversions | `marketer_conversions` | campaign's | order-item conversions: basis = sale amount, gross / fee / net copied (**gross − fee = net asserted**), attribution direct, status approved + released wallet → released | non-product conversions (`shape_unsupported`), `totals_mismatch`, invitation / order item not migrated, duplicate credit |
| travel_packages | `travel_packages` + one `travel_departures` + `travel_departure_rooms` | agency's | one departure (dates, seat capacity = available seats, seats reserved = booked), the per-person price on single / double / triple / quad (the legacy rule was price × travellers whatever the rooming) | tier-priced packages (`pricing_model_unsupported`), unlimited seats, no destination city |
| travel_bookings | `travel_bookings` | package's | number, contact from the account, `access_token` = HMAC of the legacy id (stable), one `per_person` room line, total copied (**must equal price × travellers**), status pending_documents → requested; passport / signature kept in legacy_records | bookable-unit bookings, `totals_mismatch`, package quarantined |
| classified_categories | `classified_categories` | R4 every country | slug normalised, linked to an existing category with the same slug (never overwritten) | parent not migrated |
| classified_listings | `classified_ads` | R1 | owner = customer, store owner (+ `vendor_id`) or marketer (+ `marketer_profile_id`), price and currency copied, purpose / attributes / Arabic title in `specs`, statuses as is; soft-deleted → archived | owner not migrated, number longer than 24 |
| gift_cards | `gift_cards` | currency's country | code stored only as the new HMAC hash (still redeemable) + encrypted copy (re-used across runs so the run stays idempotent), last 4, amount and **remaining balance copied**, source purchased → purchase else admin | `inactive` (never activated), no expiry date, no recipient email, same code already in the country |
| support_tickets, ticket_messages | `support_tickets`, `support_ticket_messages` | related order's, else requester's | requester customer / seller (store tenant) / marketer / travel agency (store owner) / shipping supervisor (carrier); category map (order_issue → order, payment_issue → payment, product_inquiry → product, payout → payouts, catalog → selling, policy → other); the description becomes the first message; admin messages authored by the admin | delivery-agent tickets (`shape_unsupported`), requester not migrated; their messages `parent_quarantined` |

`legacy:reconcile` checks for them: row counts (as every table), money per currency — Σ payments, Σ payment refunds, Σ refunds (net), Σ payouts (net), Σ campaign budget spent, Σ influencer fixed reserved, Σ conversion gross, Σ travel booking totals, Σ classified ad prices, Σ gift card balances — and status distributions after the documented maps (campaigns, invitations, conversions, bookings, classified ads, gift cards, tickets, payouts, refunds). Tests: `LegacyMigrationTest` (fixture rows for every table above, plus the idempotency and reconciliation runs).

### 13.6 Phone-code first sign-in for phone-only customers (§5.2, built 2026-11-19)

- `POST /api/v1/{market}/auth/phone-code/send {phone}` → 202 always (no account lookup by phone); a 6-digit code is sent by SMS only when the number belongs to an account with `requires_email`. Codes share the email-code store, length, 10-minute expiry, 5-attempt lock and limits (`email_login_codes`, key `phone:{E.164}`, purpose `phone_login`; throttle `phone-code`: 5/min per IP, 3/min and 10/day per number).
- `POST …/auth/phone-code/verify {phone, code, device_name}` → `{token, user: {…, requires_email: true}}`; login history method `phone_code`.
- Until the account adds an email, every signed-in API route except `GET me`, `POST me/email`, `POST me/email/verify`, sign-out, notifications and push tokens answers 403 `email_required` (`EnsureAccountEmail`). `POST me/email/verify` replaces the placeholder, verifies it and clears `requires_email`; from then on email is the login and the phone path sends nothing for that account.
- SMS: `App\Shared\Notifications\Sms\SmsSender` — driver `log` (dev), `array` (tests). **Production needs the owner's SMS provider account and key** (`NAWY_SMS_DRIVER`).
- Website: "Had an old Nawy account with only a phone number?" on `/login` (phone → code), then `/{market}/auth/add-email` (email → code); the account area sends such an account there. Customer app: the same steps inside the sign-in screen; an `email_required` answer signs the app out with a notice to sign in by phone again. Tests: `LegacyPhoneSignInTest`, website `phone-sign-in.test.tsx`, app `screens_test.dart`.

