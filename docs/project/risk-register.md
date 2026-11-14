# Risk Register
> M2 discovery set · v1.0 · 2026-11-14 · Owner: BA / PM (register), Problem Solver / QC (review at each DoD gate)
> Start: MASTER_PLAN v3 §17 (R1–R5, open decisions D6/D7, deviations DV1–DV4). Added: delivery, technical, financial, security, data and design risks found while writing the M2 set.
> Scale: Likelihood **L** and Impact **I** = 1 (low) … 5 (very high). Score = L × I. **≥ 15 red**, 8–14 amber, ≤ 7 green. Owners use MASTER_PLAN §1 roles.

---

## 1. Summary (top risks)

| Rank | ID | Risk | Score |
|---|---|---|---|
| 1 | R1 | Scope size vs. milestones | 20 |
| 2 | T1 | Cross-DB consistency (central + 8 country DBs) | 16 |
| 3 | D-1 | Legacy data quality / migration of 8 countries | 16 |
| 4 | F1 | Ledger / wallet drift or double posting | 15 |
| 5 | R2 | 8 DBs make reporting and migration heavier | 15 |
| 6 | T7 | Daily job timing across 8 time zones | 12 |
| 7 | P1 | Gateway sandbox keys not available (R4) | 12 |
| 8 | S3 | Email-only customer sign-in → fraud / COD abuse | 12 |

---

## 2. Plan risks (MASTER_PLAN §17)

| ID | Risk | L | I | Score | Owner | Mitigation | Trigger / indicator |
|---|---|---|---|---|---|---|---|
| R1 | **Scope size** — parity + spec + 8 countries + 3 app families exceeds team capacity. | 4 | 5 | 20 | BA / PM | Milestone gates M6–M15 with exit criteria; **parity first** (MP §17); FEATURE_MATRIX tracks each feature; cut new (N) items before parity (K) items; weekly burn-up. | Any milestone slips > 2 weeks. |
| R2 | **8 DBs** make reporting and migration heavier. | 3 | 5 | 15 | DB Architect | Nightly reporting ETL (`nawy_reporting`); per-country migration jobs; one migration runner that applies to all country connections with drift check. | Schema drift between country DBs; ETL > 2 h. |
| R3 | **Canva exposes only 200 px previews.** | 4 | 2 | 8 | Senior UI/UX | Owner downloads originals (STATUS §9); `asset-inventory.md` tracks status; placeholders marked as demo. | M13 starts without originals. |
| R4 | **Gateway sandbox keys** not provided. | 4 | 3 | 12 | Senior Backend | Fake drivers behind `PaymentGateway`; contract tests per adapter; keys requested by M8 start. | No keys 2 weeks before M8. |
| R5 | **Noon app screens behind login** could not be verified. | 3 | 2 | 6 | Senior UI/UX | Designed from audit + standard patterns; owner review of the 54 v2 screens (STATUS §9). | Owner rejects app flows. |
| D6 | **Local payment methods** undecided (KNET, BenefitPay, NAPS/QPay, eFAWATEERcom). | 3 | 3 | 9 | Owner / Finance | Adapter interface ready; decision needed by M8; settings key `payment.local_methods` off by default. | Undecided at M8 kickoff. |
| D7 | **VAT/GST and weekends** unconfirmed. | 3 | 4 | 12 | Finance | Settings, not code; finance sign-off before M7 checkout tests; `country-reference.md` §9. | Unsigned at M7. |
| DV1 | Deviation: email sign-in instead of phone + OTP (spec §17.1). | 2 | 3 | 6 | BA / PM | Logged as user decision; phone kept optional for delivery; see S3. | Stakeholder challenges deviation. |
| DV2 | Deviation: panels designed as layouts + components, not every page (spec §15.2). | 4 | 3 | 12 | Senior UI/UX + Senior Backend | `route-frame-map.md` lists every panel page with its pattern or "no frame"; complex pages flagged for a layout before M12; `<x-ui.*>` kit mirrors Figma. | Build-time UI inconsistency in M12 reviews. |
| DV3 | Deviation: marketer media library admin-curated (spec §5.4). | 2 | 2 | 4 | BA / PM | ADR 11; communicate in marketer onboarding docs. | Creator complaints. |
| DV4 | Delivery code read aloud (agent sees it) instead of agent typing. | 3 | 3 | 9 | Problem Solver / QC | Parity decision; add proof photo/signature for COD/high-value; new code on reassignment; code not shown to agent until arrival radius (*proposed* option); see S5. | Fraudulent "delivered" disputes. |

## 3. Technical risks

| ID | Risk | L | I | Score | Owner | Mitigation | Trigger / indicator |
|---|---|---|---|---|---|---|---|
| T1 | **Cross-DB consistency**: operations spanning central (identity, settings, templates) and a country DB cannot share one transaction. | 4 | 4 | 16 | Senior Backend | Keep each business transaction inside one country DB; central writes via outbox + idempotent consumers; signatures reference template version IDs (immutable); reconciliation checks. | Orphan records found by nightly checks. |
| T2 | **Wrong-country leakage** (cache keys, queue jobs, storage paths, search indexes without country prefix). | 3 | 5 | 15 | Senior Backend + QA | `CountryContext` sets DB, cache, queue, storage, Meilisearch index prefixes; jobs carry country in payload and fail closed; isolation tests ×8 (E2E 18); static check forbidding raw `DB::connection()` outside the context. | Any isolation test failure. |
| T3 | **Schema drift** between 8 country DBs. | 3 | 4 | 12 | DB Architect | Single migration set applied to all; CI compares schema hashes; append-only migrations (NFR-8). | Hash mismatch in CI. |
| T4 | **Concurrency** on stock locks, seat holds, special-request claims, budget consumption, peer-promotion monthly slot. | 3 | 4 | 12 | Problem Solver / QC | Row locks and unique keys as in SRS (campaign row lock, atomic claim, promoter row lock); load tests with parallel requests; idempotency keys. | Oversell or double claim in tests. |
| T5 | **Laravel 13 / Next.js 16** are new major versions; package compatibility (Spatie, Yajra, next-intl). | 3 | 3 | 9 | Senior Backend / Frontend | Packages already installed (v2 status); pin versions; spike on Spatie teams + multi-connection early in M6. | Blocking package bug. |
| T6 | **Spatie teams** with country + tenant scoping is complex; role-name prefix collisions. | 3 | 3 | 9 | Senior Backend | Team key design in M6; role-permission tests per panel (`role-permission-matrix.md` §12). | Permission leaks in tests. |
| T7 | **Daily job timing across time zones**: old jobs run at fixed times (02:00, 03:15…); 8 countries span UTC+2…+4. Running per local time multiplies schedules; running at one UTC time shifts business days. | 4 | 3 | 12 | Senior Backend | Decide OQ6 in M6; scheduler fan-out per country with country-local "business date"; jobs idempotent per (country, date); scheduler heartbeat on System health. | Jobs overlap or skip a country's day. |
| T8 | **Egypt DST** breaks schedules and countdowns. | 2 | 3 | 6 | Senior Backend | `CountryClock`, DST gap/overlap handling, calendar tests (MP §16). | Test failure around DST dates. |
| T9 | **Webhook duplication / ordering** from 6+ gateways. | 3 | 4 | 12 | Senior Backend | Signature verification; idempotency key per provider event; state machine rejects backward transitions; E2E 15–16. | Duplicate ledger groups. |
| T10 | **LiveKit** infrastructure (TURN, scaling, recording storage) untested in production. | 3 | 3 | 9 | Senior Backend + DevOps | Provider adapter; load test 500 viewers (*proposed*); document TURN; fall back to recorded channels. | Stream failures > 2%. |
| T11 | **Search**: Meilisearch per country (8 × indexes × 2 locales); rebuild time. | 2 | 3 | 6 | Senior Backend | MySQL fulltext first (ADR 9); queued incremental indexing; rebuild job; index per country prefix. | Rebuild > 1 h. |
| T12 | **Reverb / Horizon capacity** for chat, notifications and live comments across 8 countries. | 2 | 3 | 6 | DevOps | Horizon queues per priority; Reverb horizontal scaling; monitor in System health. | Queue latency > 60 s. |
| T13 | **Page-builder renderer parity** between Blade preview, Next.js and Flutter (51 blocks). | 3 | 3 | 9 | Senior Frontend + Mobile | Single JSON block schema; contract tests per block; responsive rules in `page-builder-blocks.md`. | Block renders differently per channel. |
| T14 | **Panel UI without component framework** (vanilla JS, Select2, DataTables) — consistency and maintenance. | 3 | 2 | 6 | Senior Backend | `<x-ui.*>` Blade kit mirroring Figma; JS modules per component; Playwright per panel. | Duplicate JS patterns. |
| T15 | **Mobile app store review** (payments, user-generated content, live media) delays M14. | 3 | 3 | 9 | Mobile | Early TestFlight/Play internal tracks; UGC report/block features; payments through web views where store rules require. | Rejection. |

## 4. Financial and payment risks

| ID | Risk | L | I | Score | Owner | Mitigation | Trigger |
|---|---|---|---|---|---|---|---|
| F1 | **Ledger / wallet drift** or double posting during migration and new flows. | 3 | 5 | 15 | DB Architect + Senior Backend | Balances are ledger projections (ADR 4); idempotency keys; nightly balance-vs-ledger check; ledger integrity tests (DoD). | Any non-zero drift. |
| F2 | **COD fraud and cash shortfalls** (agent keeps cash, fake deliveries). | 3 | 4 | 12 | Ops + Finance | Daily COD settlement 23:30; payout unlocks only after remittance; proof photo/signature; COD max order amount setting; disputed state; agent scorecard. | Settlement variance > tolerance. |
| F3 | **3-decimal currency rounding** errors (OMR, KWD, BHD, JOD), especially fees and commission splits. | 3 | 4 | 12 | Senior Backend + QA | brick/money with currency context; half-up to minor unit; money tests ×8 currencies; Thawani baisa conversion only in adapter. | Penny differences in reconciliation. |
| F4 | **Clawback** of paid conversions creates negative balances that cannot be recovered. | 3 | 3 | 9 | Finance | `clawback.max_negative_balance`; recovery job from next earnings; manual write-off with approval. | Unrecovered > 30 days. |
| F5 | **Campaign fee / budget** mistakes (fee charged twice, budget exceeded). | 2 | 4 | 8 | Senior Backend | Server-side snapshot; charge once at go-live with idempotency; last commission shortened; campaign E2E A–F. | Budget over-spend. |
| F6 | **Gift card / voucher code leakage** in logs or admin UI. | 2 | 4 | 8 | Senior Backend | Store hashed codes; mask in UI; never log (Spec §6.2). | Code found in logs. |
| F7 | **Payout without COD remittance** or on held vendors. | 2 | 4 | 8 | Finance | Eligibility rules in `SettlementCalculator`; 4-eyes approval (*proposed*); no automatic payouts. | Payout with unremitted COD. |
| F8 | **Tax misconfiguration** (Jordan GST vs VAT; KW/QA zero). | 2 | 4 | 8 | Finance | Per-country `tax_rates` with tests; invoice templates reviewed per country. | Incorrect invoice. |

## 5. Security and privacy risks

| ID | Risk | L | I | Score | Owner | Mitigation | Trigger |
|---|---|---|---|---|---|---|---|
| S1 | **Admin privilege misuse** (impersonation, finance actions, credential view). | 2 | 5 | 10 | Super admin + QC | Optional→required 2FA for Super/Finance (*proposed*); impersonation audited; encrypted credentials; activity log; security events in System health. | Unusual admin activity. |
| S2 | **Account-manager data leakage** (finance fields visible). | 3 | 3 | 9 | Senior Backend | Global scopes + Policies + masked serializers; tests on orders, vendors, dashboard (`role-permission-matrix.md` §3.3). | Test failure. |
| S3 | **Email-only customer sign-in** → disposable emails, fake accounts, COD abuse. | 4 | 3 | 12 | BA / PM + Senior Backend | Email verification before checkout; COD limits for new accounts (*proposed*); device fingerprint and velocity rules; phone captured for delivery; fraud flags on orders. | COD refusal rate spikes. |
| S4 | **Signed WhatsApp links** forwarded or replayed. | 2 | 3 | 6 | Senior Backend | Short TTL = invitation timeout; single use; bound to invitation and marketer. | Duplicate use attempts. |
| S5 | **Delivery code social engineering** (agent sees the code; customer not present). | 3 | 3 | 9 | QC | Code per assignment, regenerated on reassignment; proof of delivery; customer notified on delivery with "not me?" link (*proposed*); dispute flow. | Delivered-not-received claims. |
| S6 | **File uploads** (documents, contracts, listing media) carry malware or wrong MIME. | 3 | 3 | 9 | Senior Backend | MIME + extension validation, size limits, private disk, AV scan (*proposed*), signed URLs. | AV hits. |
| S7 | **Data retention / privacy** rules differ per country (PDPL KSA, PDPL Oman, Egypt PDPL, etc.). | 3 | 4 | 12 | BA / PM + Legal (owner) | Retention settings per data class; consent records; country legal configuration points (Spec §19); legal review before M15. | Regulator or legal query. |
| S8 | **API abuse / scraping** of storefront and public APIs. | 3 | 2 | 6 | DevOps | Rate limits, bot protection at edge, API logs. | Traffic anomalies. |
| S9 | **E-signature legal standing** questioned. | 2 | 3 | 6 | Owner / Legal | Evidence model (SRS §13.6); no legal claim made; per-country counsel review. | Contract dispute. |

## 6. Data and migration risks (D1)

| ID | Risk | L | I | Score | Owner | Mitigation | Trigger |
|---|---|---|---|---|---|---|---|
| D-1 | **Legacy data quality** (356 tables, mixed countries in one DB, legacy rounding FLOOR/ROUND). | 4 | 4 | 16 | DB Architect | Per-module migration step + reconciliation test (MP §15 build order); rounding differences documented; dry runs per country; 100% reconciliation exit (M15). | Reconciliation < 100%. |
| D-2 | **Full dump not yet provided** (owner action, STATUS §7). | 4 | 3 | 12 | Owner | Request now; use schema-only + sample data meanwhile. | No dump by M6 end. |
| D-3 | `data-migration-plan.md` and `timezones.md` still cover 4 countries. | 5 | 2 | 10 | BA / DB Architect | Update for KW, QA, BH, JO before M6 (country-reference CQ7). | — |
| D-4 | **Cutover downtime** for a live marketplace across 8 countries. | 3 | 4 | 12 | DB Architect + DevOps | Rehearsed cutover; per-country waves (*proposed*); read-only window; rollback plan. | Rehearsal > planned window. |
| D-5 | **Live signatures and append-only records** altered during migration. | 2 | 5 | 10 | DB Architect | Copy as-is with hashes; verify SHA-256 of frozen copies; never edit. | Hash mismatch. |

## 7. Design and product risks

| ID | Risk | L | I | Score | Owner | Mitigation | Trigger |
|---|---|---|---|---|---|---|---|
| X1 | **Missing frames** for complex panel pages and some storefront routes (see `route-frame-map.md` gap list). | 4 | 3 | 12 | Senior UI/UX | Prioritise gap list by milestone; draw complex layouts before the milestone that builds them. | Build starts on a page with no frame or pattern. |
| X2 | **Arabic/RTL** shown only on a few frames. | 3 | 3 | 9 | Senior UI/UX + Frontend | RTL rules + Cairo styles; RTL Playwright snapshots; AR content QA per country. | RTL defects in QA. |
| X3 | **Owner review pending** for M3–M5 (all ✅ "pending owner review"). | 3 | 3 | 9 | BA / PM | Review sessions per Figma page; change log. | Large rework after review. |
| X4 | **App contexts** (Food, Minutes) imply operations not in scope (restaurants, 15-min dark stores). | 3 | 4 | 12 | BA / PM | Confirm with owner what Food and Minutes sell at launch; can launch as curated catalog contexts via page builder. | No supply for a context at launch. |
| X5 | **Spec deviations** challenged later (DV1–DV4). | 2 | 3 | 6 | BA / PM | Logged in MP §17 with dates and rationale. | Stakeholder escalation. |

## 8. Third-party and operations risks

| ID | Risk | L | I | Score | Owner | Mitigation | Trigger |
|---|---|---|---|---|---|---|---|
| O1 | **WhatsApp Business API** approval, templates per language, per-country numbers. | 3 | 3 | 9 | Senior Backend | Start registration early; template catalogue in `notification_templates`; fallback to SMS/email. | Template rejection. |
| O2 | **SMS deliverability** differs per country (sender ID registration). | 3 | 2 | 6 | DevOps | Per-country provider setting; email first (DV1). | Delivery rate < 95%. |
| O3 | **BNPL availability** limited to AE/KSA (D4). | 2 | 2 | 4 | Finance | Per-country config; hide where unavailable. | — |
| O4 | **Carrier integrations** beyond in-house agents not specified. | 3 | 3 | 9 | BA / PM | Shipping adapter boundary; manual carrier panel first. | Carrier asks for API. |
| O5 | **Scheduler or queue down** → timed transitions stop (SRS NFR-6). | 2 | 5 | 10 | DevOps | Supervisor/Horizon, heartbeat alerts on System health, idempotent catch-up runs. | Missed heartbeat. |

## 9. Review cadence
- Re-score at every milestone gate (M6–M15) and when a decision (D6, D7, OQ*) is made.
- Red risks get a named action in `STATUS.md` until amber.
