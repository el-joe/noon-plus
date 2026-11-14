# Settings Catalogue
> M2 discovery set · v1.0 · 2026-11-14 · Owner: BA / PM
> Sources: MASTER_PLAN v3 §4a, §7a, ADR 3, 5, 6, 11, 14, 15; SRS §20 (all keys), §4–§17 (implied settings); `timezones.md`; PORTAL_PAGES; Spec §5–§9, §13.
> Rule (MP §4a, Spec §5.6): rates, windows, thresholds and policies are **settings**, never hard-coded in controllers or calculators.

---

## 0. Conventions

| Column | Meaning |
|---|---|
| Key | Settings-service key (dot or snake case kept as in the source). SRS keys are kept **verbatim**. |
| Scope | **G** global (central DB) · **C** per country (central DB, keyed by country) · **Cat** per category (country DB) · **V** per vendor/tenant · **W** per warehouse · **E** per entity (row-level field) · **U** per user. Resolution order where several apply: E → V/Cat → C → G. |
| Type | `int`, `decimal`, `money` (country currency, minor-unit precision), `percent`, `bool`, `enum`, `json`, `time`, `duration`. |
| Default | From the source; "TBD" = owner/finance to decide; "*proposed*" = this document's default. |
| Edits | Admin role (role-permission-matrix §3) or tenant role. |
| Source | Where the requirement comes from. |

Storage (proposed): central `settings (key, scope_type, scope_id, value json, updated_by, updated_at)` with audit; reads cached per country; every change is written to the activity log.

---

## 1. Country & locale (MP §4a, ADR 3, ADR 14)

| Key | Scope | Type | Default | Edits | Where used | Source |
|---|---|---|---|---|---|---|
| `country.is_active` | C | bool | true for all 8 (D2) | Super | Storefront markets, panels, jobs fan-out | MP §4a, SRS §4.1.14 |
| `country.timezone` | C | enum (IANA) | per `country-reference.md` | Super | `CountryClock`, jobs, ETA, notifications | ADR 14 |
| `country.weekend_days` | C | json (days) | UAE Sat–Sun; others Fri–Sat (D7) | Super, Ops | ETA, SLA, payout dates | MP §4a |
| `country.holidays` (`country_calendars`) | C | json (date ranges) | placeholders (`country-reference.md` §6) | Ops | ETA, SLA, payout dates, delivery slots | MP §4a, §12 |
| `country.tax.vat_rate` (`tax_rates`) | C | percent | AE 5, OM 5, SA 15, EG 14, KW 0, QA 0, BH 10, JO 16 (GST) — D7 | Finance | `TaxCalculator`, checkout, invoices, VAT on commission | MP §4a |
| `country.tax.prices_include_vat` | C | bool | *proposed* true (consumer prices shown VAT-inclusive) | Finance | PDP, cart | Spec §3.1 D |
| `country.tax.registration_number` | C | string | TBD | Finance | Invoices | Spec §19 |
| `country.currency` | C | enum ISO 4217 | AED, OMR, SAR, EGP, KWD, QAR, BHD, JOD | Super | Money everywhere | MP §4a |
| `country.currency_decimals` | C | int | 3 for OMR, KWD, BHD, JOD; 2 otherwise | Super (locked after launch) | brick/money rounding | ADR 3 |
| `country.rounding_mode` | C | enum | `half_up` | Super | All calculators | ADR 3 |
| `country.default_locale` | C | enum `ar|en` | *proposed* per `country-reference.md` §4 | Super | Prefix-less redirect, emails | NFR-4 |
| `country.enabled_locales` | C | json | `["ar","en"]` | Super | Market slugs | MP §4a |
| `country.payment_methods_enabled` | C | json | wallet, COD, gift card + gateways (§5) | Finance | Checkout | SRS §4.1.14 |
| `currency.rates` | G | json | daily rates (display/reporting only) | Finance | Reports; never for payouts | SRS §4.1.14 |
| `admin_timezone` | G (later U) | enum | `Asia/Muscat` | Super | Admin display of all date-times | ADR 14, `timezones.md` |
| `panel.show_oman_label` | V/U | bool | false for non-admin panels | User | Partner/marketer/travel/carrier date displays | `timezones.md` §6 |
| `domains.pattern` | G | enum | `{panel}.{base}` (alt `{panel}.{cc}.{base}`) | Super | Routing | MP §5, D3 |
| `domains.base` / `domains.portal_host` | G | string | `nawy.test` (local) / `business.` | Super | Routing | MP §5 |
| `cities` (CSV import) | C | table | from migration | Ops | Addresses, zones | SRS §4.1.14 |

## 2. Identity & security (ADR 5)

| Key | Scope | Type | Default | Edits | Where used | Source |
|---|---|---|---|---|---|---|
| `auth.customer.methods` | C | json | `email_password`, `email_code`, `google`, `apple` | Super | Customer sign-in | ADR 5, DV1 |
| `auth.customer.email_code_length` | G | int | 6 | Super | Email code sign-in | ADR 5 |
| `auth.customer.email_code_ttl_minutes` | G | int | *proposed* 10 | Super | Email code | ADR 5 |
| `auth.customer.require_verified_email_for_checkout` | G | bool | true | Super | Checkout gate | ADR 5 |
| `auth.customer.phone_required` | C | bool | false (delivery-only field) | Super | Address/checkout | ADR 5 |
| `auth.business.2fa` | G | enum `off|optional|required` | `optional` | Super | All panels | ADR 5 |
| `auth.admin.2fa` | G | enum | *proposed* `required` for Super and Finance | Super | Admin | Spec §19 |
| `auth.password.min_length` | G | int | *proposed* 10 | Super | All | Spec §19 |
| `auth.login.throttle_per_minute` | G | int | *proposed* 5 | Super | All sign-in endpoints | NFR-3 |
| `auth.session.lifetime_minutes` | G | int | *proposed* 120 (panels) | Super | Panels | Spec §17.1 |
| `auth.token.lifetime_days` | G | int | *proposed* 30 (apps, refreshable) | Super | Sanctum | ADR 5 |
| `auth.invitation_link_ttl_hours` | G | int | = `marketer_invitation_timeout_hours` | — | Signed WhatsApp URLs | ADR 5 |
| `auth.credential_email_mode` | G | enum `password|set_link` | *proposed* `set_link` | Super | New accounts | SRS §15 |
| `security.retention.login_history_days` | G | int | *proposed* 365 | Super | Login history prune | Spec §19 |

## 3. Join & onboarding (MP §5a, §7a; PORTAL_PAGES §2–§5)

| Key | Scope | Type | Default | Edits | Where used | Source |
|---|---|---|---|---|---|---|
| `join_option_vendor_enabled` | C | bool | true | Vendor rel. | `/join` card "Product seller" | MP §5a, PORTAL_PAGES §2 |
| `join_option_open_souq_enabled` | C | bool | true | Vendor rel. | `/join` card "Open Souq" | idem |
| `join_option_travel_agency_enabled` | C | bool | true | Vendor rel. | `/join` card "Travel agency" | idem |
| `join_option_marketer_enabled` | C | bool | true | Vendor rel. | `/join` card "Marketer" | idem |
| `join_option_broker_enabled` / `join_option_shipping_enabled` (N) | C | bool | *proposed* true / false | Vendor rel. | New journeys | MP §5a |
| `document_types` matrix (`document_types`) | C × business type × category | enum per doc `mandatory|optional|not_applicable` | from migration | Vendor rel. | Wizard step 4, Settings → Documents, application approval | SRS §13.7, MP §7a |
| `onboarding_steps` (builder) | C × registration type | json (ordered steps, fields, required docs, contract kind) | vendor 5 steps; travel 2 steps; marketer, broker, shipping per Spec §11 | Vendor rel. | Portal wizards, application status | MP §7a, Spec §11 |
| `onboarding.slug_reserved_words` | G | json | *proposed* list (admin, api, www…) | Super | Store slug check | PORTAL_PAGES §3 |
| `onboarding.application_abandon_days` | G | int | *proposed* 30 | Vendor rel. | Application `in_progress → abandoned` | lifecycle §7.1 |
| `onboarding.upload_max_mb` | G | int | *proposed* 10 per file | Super | Registration uploads | Spec §19 |
| `marketer.job_types` (Marketer Jobs) | C | table | influencer, affiliate, broker (+ others) | Marketing | Onboarding, campaign wizard | SRS §4.1.6 |

## 4. Commerce — catalog, cart, checkout, orders (MP §7a, SRS §5–§8)

| Key | Scope | Type | Default | Edits | Where used | Source |
|---|---|---|---|---|---|---|
| `cart.lock_minutes` | G | int | **15** | Ops | `cart_inventory_locks`, ReleaseExpiredLocksJob | SRS §6, MP §7a |
| `cart.guest_token_ttl_days` | G | int | *proposed* 30 | Ops | Guest cart | SRS §6 |
| `coupon.max_platform_per_order` | G | int | **1** | Marketing | `CouponCalculator` | SRS §6, MP §7a |
| `coupon.max_vendor_per_order` | G | int | **1** | Marketing | `CouponCalculator` | SRS §6, MP §7a |
| `shipping.free_threshold` | C | money | TBD per country | Ops, Finance | Checkout totals (platform absorbs) | SRS §6, MP §7a |
| `cod.enabled` | C | bool | true | Finance | Checkout | SRS §6 |
| `cod.fee` | C | money | TBD per country | Finance | `CheckoutTotalsCalculator` | SRS §6, MP §7a |
| `cod.max_order_amount` | C | money | *proposed* TBD | Finance | COD eligibility | Risk F2 |
| `return_window_days` | Cat (inherits parent; C default) | int | *proposed* 14 (OQ7) | Ops | Return eligibility, auto-complete, conversion approval | MP §7a, SRS §7.1 |
| `return.excluded_custom_items` | Cat | bool | true for made-to-order | Ops | Return wizard | `design-coverage.md` (modest PDP) |
| `payment.pending_expiry_minutes` | G | int | *proposed* 30 | Finance | ExpirePendingPaymentsJob | SRS §6 |
| `order.auto_complete_time` | G | time | 02:00 | Super | AutoCompleteOrdersJob | SRS §7.1 |
| `order.vendor_cancel_strike` | G | bool | true | Ops | Seller score | SRS §4.3 |
| `catalog.compare_max_items` | G | int | *proposed* 4 | Content | Product compare (N) | MP §3 |
| `reviews.require_verified_purchase` | G | bool | *proposed* true | Content | Write review | SRS §7.8 |
| `reviews.auto_flag_rules` | G | json | *proposed* profanity list + link detection | Content | Review machine | SRS §7.8 |
| `bestseller.ranking_window_days` | C | int | *proposed* 30 | Marketing | Bestseller job | SRS §5.1 |
| `cart_card_offers` | C | table | — | Marketing | Cart | SRS §4.1.7 |
| `warranty_plans` | C × parent category | table | — | Ops | Checkout warranty option | SRS §4.1.2 |
| `category.commission_percent` | Cat | percent | per category (migration) | Finance | `VendorCommissionCalculator`, payouts | SRS §4.1.2, §20.2 |
| `category.allowed_shipping_methods` | Cat | json | — | Ops | Checkout | SRS §4.1.2 |
| `category.individual_contract_required` | Cat (inherit) | enum `required|exempt|inherit` | inherit | Vendor rel. | ContractResolver, ListingContractGate | SRS §13.5, §20.2 |

## 5. Payments (ADR 6, Spec §13)

| Key | Scope | Type | Default | Edits | Where used | Source |
|---|---|---|---|---|---|---|
| `country_gateway_configs.enabled` | C × gateway | bool | seeds per `country-reference.md` | Finance | Checkout methods | ADR 6 |
| `country_gateway_configs.mode` | C × gateway | enum `sandbox|production` | sandbox until keys (R4) | Finance | Adapter | ADR 6 |
| `country_gateway_configs.credentials` | C × gateway | encrypted json | — | Finance (Super to view) | Adapter | ADR 6, Spec §13 |
| `country_gateway_configs.currencies` | C × gateway | json | country currency | Finance | Adapter | ADR 6 |
| `country_gateway_configs.min_amount` / `max_amount` | C × gateway | money | per provider | Finance | Method eligibility (incl. BNPL) | ADR 6 |
| `country_gateway_configs.sort` | C × gateway | int | recommended first | Finance | Payment list order | ADR 6 |
| `country_gateway_configs.is_recommended` | C × gateway | bool | ★ per §4a (Thawani OM, Stripe AE, PayTabs others) | Finance | "Recommended" badge | ADR 6, MP §4a |
| `country_gateway_configs.fee_rule` | C × gateway | json (% + fixed, payer) | TBD | Finance | `PaymentFeeCalculator` | ADR 6 |
| `payment.retry_max_attempts` | G | int | *proposed* 3 | Finance | Failed payment retry | Spec §13 |
| `payment.local_methods` (KNET, BenefitPay, NAPS/QPay, eFAWATEERcom) | C | bool | **off — D6** | Finance | Adapters | MP §4a, D6 |
| `refund.card_eta_days_text` | G | string | "5–10 days" | Content | Customer messaging | SRS §7.5 |
| `refund.cod_to_wallet` | G | bool | true | Finance | Refund machine | SRS §7.5 |

## 6. Fulfilment, shipping & FBN (SRS §8, §4.1.3)

| Key | Scope | Type | Default | Edits | Where used | Source |
|---|---|---|---|---|---|---|
| `fbn.free_storage_days` | W (C default) | int | **30** | Ops | Storage clock | SRS §8, MP §7a |
| `fbn.daily_overage_fee` | W | money per unit/volume | TBD | Finance | FBN overage job 01:00 | SRS §8 |
| `fbn.vendor_storage_limit` | V × W | int (units or m³) | TBD | Ops | Inbound guard | SRS §4.1.3 |
| `fbn.outbound_surcharge` (Shipping Surcharges) | W | money | TBD | Ops | FBN shipments | SRS §4.1.3 |
| `shipping.zones` / `methods` / `rates` (base + per kg) | C | tables | migration | Ops | `ShippingFeeCalculator` | SRS §8 |
| `shipping.weight_slabs` | C | table | migration | Ops | Calculator | SRS §4.1.14 |
| `shipping.volumetric_divisor` | C | int | *proposed* 5000 | Ops | Billable weight, Weight Calculator | SRS §8 |
| `shipping.city_surcharges` | V | table | — | Vendor | Checkout | SRS §4.3 |
| `shipping.subsidies` / exceptional zones | C × zone (V opt-in) | json (vendor share %, platform share %) | TBD | Ops, Finance | Subsidy in checkout and payout | SRS §8 |
| `shipping.vendor_zone_alerts` | C | table | — | Ops | Vendor alerts | SRS §4.1.14 |
| `shipping.eta_cutoff_time` | C | time | *proposed* 14:00 local | Ops | PDP delivery-date countdown | MP §14c |
| `shipping.carrier_fallback_rules` | C | json | — | Ops | Carrier selection | SRS §4.1.11 |
| `packaging.delivery_fee` | C | money | TBD (never subsidised) | Ops | Packaging cart | SRS §4.3 |

## 7. Delivery & COD (ADR 15, SRS §4.6, §8)

| Key | Scope | Type | Default | Edits | Where used | Source |
|---|---|---|---|---|---|---|
| `delivery.code_length` | G | int | **4** | Super (locked) | Assignment code | ADR 15 |
| `delivery.code_visible_to_agent` | G | bool | **true** (parity, DV4) | locked | Agent app/web | ADR 15 |
| `delivery.proof_required_for_cod` | C | bool | true | Ops | "Codes match" step | ADR 15 |
| `delivery_proof_required_above` | C | money | TBD (OQ5) | Ops | High-value proof | ADR 15 |
| `delivery.proof_type` | C | enum `photo|signature|either` | either | Ops | Proof of delivery | ADR 15 |
| `delivery.sla_minutes` | C × zone × method | int | TBD | Ops | CheckSlaBreachJob | SRS §8 |
| `delivery.max_attempts` | C | int | *proposed* 3 | Ops | Reschedule → RTO | Spec §8.2 |
| `delivery.failure_reasons` | C | json list | *proposed* not home, wrong address, refused, unreachable, COD not ready | Ops | Can't deliver screen | Spec §8.2 |
| `delivery.auto_assign` | C | enum `off|nearest|round_robin` | *proposed* nearest | Ops | Assignment | SRS §4.1.11 |
| `delivery.agent_rate_per_delivery` | C (carrier override) | money | TBD | Finance | Delivery payouts | SRS §9 |
| `delivery.agent_bonus_rules` | C | json | TBD | Finance | Delivery payouts | SRS §9 |
| `cod.settlement_time` | G | time | 23:30 | Finance | GenerateCodSettlementsJob | SRS §8 |
| `delivery.gps_ping_seconds` | G | int | *proposed* 30 | Ops | Live map | SRS §4.6 |

## 8. Finance (SRS §9, MP §10)

| Key | Scope | Type | Default | Edits | Where used | Source |
|---|---|---|---|---|---|---|
| `payout.schedule` | V | enum `weekly|biweekly|monthly` | *proposed* weekly | Finance | Payout job (Mon 02:00 / 1st & 15th / 1st) | SRS §9 |
| `payout.minimum_amount` | C | money | *proposed* TBD | Finance | Payout generation | SRS §9 |
| `payout.require_four_eyes` | G | bool | *proposed* true | Super | Approve ≠ generator | Spec §19 |
| `wallet.withdrawal_minimum` | C | money | TBD | Finance | Withdrawal machine | SRS §9 |
| `acquisition_commission_rules` | C | table (rate, monthly min/max band, date range, admin) | migration | Finance | Monthly 1st 02:00 job | SRS §4.1.5 |
| `subscription_plans` | C | table (monthly/annual price) | migration | Finance | Vendor subscriptions | SRS §4.1.12 |
| `subscription.grace_days` | G | int | *proposed* 7 | Finance | `active → past_due → cancelled` | SRS §9 |
| `promotional_credit.default_expiry_days` | C | int | *proposed* 90 | Finance, Marketing | Promotional balance | MP §10 |
| `finance.suspicious_rules` | G | json | *proposed* velocity + amount thresholds | Finance | Flags | MP §10 |
| `reconciliation.tolerance` | C | money | *proposed* 0 | Finance | Gateway reconciliation | MP §10 |

## 9. Membership — Nawy One (MP §7a)

| Key | Scope | Type | Default | Edits | Where used | Source |
|---|---|---|---|---|---|---|
| `membership.nawy_one.enabled` | C | bool | TBD | Marketing | App/web account | MP §14c |
| `membership.nawy_one.price_monthly` / `price_annual` | C | money | TBD (OQ8) | Finance | Membership billing | MP §7a |
| `membership.nawy_one.trial_days` | C | int | *proposed* 0 | Marketing | Membership machine | MP §10 |
| `membership.nawy_one.benefits` | C | json (free-delivery threshold override, cashback %) | TBD | Marketing | Checkout calculator | MP §14c |

## 10. Marketing — marketers & campaigns (SRS §20, MP §7a, §8)

### 10.1 SRS §20.1 keys (verbatim)
| Key | Scope | Type | Default | Edits | Where used |
|---|---|---|---|---|---|
| `marketer_campaign_auto_approve_hours` | C | int | **36** | Marketing | ProcessCampaignAutoApproveJob |
| `marketer_invitation_timeout_hours` | C | int | **12** | Marketing | ProcessInvitationTimeoutJob, invitation `expires_at` |
| `marketer_replacement_min_accepted_campaigns` | C | int | **0** | Marketing | Replacement candidate filter |
| `marketer_attribution_window_days` | C | int | **7** | Marketing | Referral click validity |
| `marketer_referral_grace_days` | C | int | (setting) TBD — *proposed* 3 | Marketing | ReferralCodeResolver GRACE |
| `marketer_payout_clearing_days` | C | int | (setting) TBD — *proposed* 14 | Finance | ReleaseMarketerPendingCommissionJob |
| `marketer_classified_approval_days` | C | int | **2** | Marketing | Conversion approval (classified) |
| `marketer_peer_campaign_min_stock` | C | int | **1** | Marketing | Peer promotion guard |
| `marketer_campaign_min_platform_sample_qty` | C | int | **1** | Marketing | Samples plan |
| `marketer_campaign_min_influencer_sample_qty` | C | int | **1** | Marketing | Samples plan |
| `marketer_campaign_allowed_fulfilment_models` | C | json | (service default) — *proposed* `["fbn","fbm"]` | Marketing | Wizard source validation |
| `marketer_whatsapp_resend_cooldown_minutes` | G | int | **10** | Marketing | Admin resend |

### 10.2 SRS §20.2 per-category fields
| Key | Scope | Type | Default | Edits | Where used |
|---|---|---|---|---|---|
| `min_stock_for_campaign` | Cat | int | **10** | Marketing | Wizard guard; pause/resume rules |
| `platform_sample_qty` | Cat | int | ≥ min platform | Marketing | Samples |
| `influencer_sample_qty` | Cat | int | ≥ 1 | Marketing | Samples |
| `affiliate_sample_qty` | Cat | int | — | Marketing | Samples |
| Marketers tab (affiliate defaults) | Cat | json | — | Marketing | Commission resolution |
| Sale commission % | Cat | percent | — | Finance | Settlement |
| Individual-contract switch | Cat | enum | inherit | Vendor rel. | ContractResolver |

### 10.3 SRS §20.3 configuration grids
| Grid | Scope | Shape | Edits | Where used |
|---|---|---|---|---|
| Marketer Settings — influencer fixed amount | C × vertical (× category) | money | Marketing | InfluencerFixedService snapshot |
| Marketer Settings — commission fee | C × vertical (× category) | % + fixed | Marketing, Finance | Commission fee |
| Influencer overrides / profile commission rules | Marketer × category or vertical | fixed, %, fee override | Marketing | FR-MKT-2 |
| Marketing Fees — fixed | C | amount and/or % | Marketing, Finance | Campaign fee |
| Marketing Fees — variable | C × category × FBM/FBN/FBP | amount + % | Marketing, Finance | Campaign fee |
| Marketing Fees — vertical | C × classified/travel | amount + % | Marketing, Finance | Campaign fee |

### 10.4 MP §7a / §8 marketing settings (new)
| Key | Scope | Type | Default | Edits | Where used | Source |
|---|---|---|---|---|---|---|
| `campaign_fee_refund_policy` | C | enum `none|before_first_conversion|pro_rata` | **`none`** (D5) | Finance | Campaign cancel-active | MP §7a, D5 |
| `attribution_model` | C | enum `last_click|first_click|linear` | **`last_click`** | Marketing | Attribution service (after direct credit) | MP §8, §13 |
| `attribution_window_days` | C | int | = `marketer_attribution_window_days` (7) | Marketing | Clicks | MP §7a |
| `clearing_days` | C | int | = `marketer_payout_clearing_days` | Finance | Pending → spendable | MP §7a |
| `clawback.enabled` / `clawback.max_negative_balance` | C | bool / money | true / TBD | Finance | Clawback recovery | MP §13 |
| `cross_promotion.enabled` | C | bool | *proposed* false until policies set | Marketing | Engine on/off per country | Spec §5.6 |
| `cross_promotion.policies` | C | json (eligibility, min account age, profile completion, verification, categories, min products, limits, required activities, windows, commission allocation, rewards, fraud rules, penalties, exemptions, manual approval) | — | Marketing | Policy engine + dashboard | Spec §5.6 |
| `cross_promotion.peer_per_owner_per_month` | C | int | **1** | Marketing | Peer promotion guard | SRS §11.6 |
| `marketer.fraud_rules` | C | json | *proposed* self-referral block, click velocity, same-device conversions | Marketing | Attribution, conversions | Spec §5.4 |
| `marketer.media_library` | G/C | admin-curated | — | Content | Browse/share only | DV3 |

## 11. Platform marketing & advertising (SRS §10, Spec §5.8, §7)

| Key | Scope | Type | Default | Edits | Where used | Source |
|---|---|---|---|---|---|---|
| `flash_sale.display_names` | C | json | Flash Sales, Mega Deals, Daily Deals, Special Offers | Marketing | Storefront labels | Spec §5.8 |
| `flash_sale.max_qty_per_customer` | E (event) | int | *proposed* 2 | Marketing | Cart guard | Spec §5.8 |
| `flash_sale.marketer_bonus` | E | % or fixed | TBD | Marketing | Conversion bonus | SRS §10 |
| `coupon_participation.min_fee` / `capacity` / `expires_at` | E (invitation) | money / int / datetime | per invitation | Marketing | Participation | SRS §10 |
| `banner.placements` | G | json | migration | Content | Banner slots | SRS §10 |
| `ad_campaign.min_daily_budget` | C | money | TBD | Marketing | Ad campaign | SRS §10 |
| `ad_campaign.min_cpc_bid` / `min_cpm_bid` | C | money | TBD | Marketing | Auction | SRS §10 |
| `ad_slots` (placement, dimensions, pricing model, price, max concurrent) | C | table | migration | Marketing | Bookings | SRS §4.1.7 |
| `ad_packages` (price, VAT, duration, features, target type) | C | table | migration | Marketing | Marketer packages | SRS §4.1.6 |
| `sponsored_ranking.weights` | C | json (bid, relevance, CTR, quality) | TBD | Marketing | `SponsoredRanker` | MP §3, Spec §7 |
| `sponsored_ranking.max_sponsored_per_page` | C | int | *proposed* 4 per 48 results | Marketing | Search/category | Spec §7 |
| `sponsored.label_text` | C × locale | string | "Sponsored" / "إعلان" | Content | Every paid placement | Spec §7 |
| `gift_card.denominations` | C | json money list | TBD | Marketing | Gift-card store | Spec §6.2 |
| `gift_card.custom_amount_min` / `max` | C | money | TBD | Marketing | Custom amount | Spec §6.2 |
| `gift_card.default_expiry_months` | C | int | *proposed* 12 | Marketing | Issue | Spec §6.2 |
| `voucher.types_enabled` | C | json | all Spec §6.1 types | Marketing | Vouchers | Spec §6.1 |
| `newsletter.double_opt_in` | C | bool | *proposed* true | Marketing | Subscriptions | SRS §10 |

## 12. Classifieds, real estate & travel (SRS §12, Spec §5.2, §5.3, §5.7)

| Key | Scope | Type | Default | Edits | Where used | Source |
|---|---|---|---|---|---|---|
| `classified.inquiry_throttle_per_minute` | G | int | **5** | Super | Guest inquiries | SRS §12.1 |
| `classified.listing_duration_days` | C × classified category | int | *proposed* 30 | Ops | Expiry job | MP §11 |
| `classified.renewal_reminder_days` | C | json | *proposed* [3, 1] before expiry | Ops | Renewal reminders | MP §11 |
| `classified.renewal_price` | C × category | money | *proposed* 0 | Marketing | Renewal | Spec §5.2 |
| `classified.boost_packages` | C | table | TBD | Marketing | Paid boosting/featured | Spec §5.2 |
| `classified.report_reasons` | G | json | *proposed* fraud, wrong category, sold, offensive, duplicate | Ops | Report ad modal | MP §12 |
| `classified.saved_search_alert_frequency` | U | enum `instant|daily` | daily | Customer | Saved-search alerts (hourly job) | MP §11 |
| `classified.categories` (dynamic, sections, contracts per country) | C | table | migration | Ops, Vendor rel. | Classified module | SRS §4.1.8 |
| `property.verification_required` | C | bool | *proposed* true | Ops | Property review | Spec §5.7 |
| `travel.seat_hold_minutes` | C | int | *proposed* 10 | Ops | Seat-hold expiry job | MP §11 |
| `travel.documents_deadline_days` | C | int | *proposed* 7 before departure | Ops | `pending_documents` reminders | SRS §12.2 |
| `travel.cancellation_policy` | E (package) | json tiers | per package | Agency | Refund on cancel | Spec §5.3 |
| `travel.platform_commission_percent` | C × travel category | percent | TBD | Finance | Booking settlement | Spec §12.1 |

## 13. Contracts & documents (SRS §13)

| Key | Scope | Type | Default | Edits | Where used | Source |
|---|---|---|---|---|---|---|
| `contracts.notice_default_days` | G | int | **14** (example in SRS) | Vendor rel. | Notice mode | SRS §13.4 |
| `contracts.upload_max_mb` | G | int | **20** | Super | Template upload | SRS §13.2 |
| `contracts.upload_formats` | G | json | PDF, DOC, DOCX | Super | Template upload | SRS §13.2 |
| `contracts.require_arabic` | G | bool | true | Super | Template validation | SRS §13.2 |
| `contracts.mobile_clickwrap_enforced` | G | bool | **true** | Super | Vendor app gate | MP §13 |
| `contracts.email_signed_copy` | G | bool | true | Super | Signature evidence | SRS §13.6 |
| `marketer_contract.required_for_customers` | E (marketer contract) | bool | false | Marketing | **FR-CHK-4** | SRS §13.1 |

## 14. Live media & content (SRS §14–§15, ADR 11, Spec §9.1)

| Key | Scope | Type | Default | Edits | Where used | Source |
|---|---|---|---|---|---|---|
| `streams.provider` | G | enum | `livekit` | Super | Media adapter | ADR 11 |
| `streams.recorded_max_mb` | G | int | **500** | Super | Upload | SRS §14.2 |
| `streams.recorded_formats` | G | json | mp3, wav, ogg, oga, m4a, aac, mp4, webm, mov, m4v, mpeg | Super | Upload | SRS §14.2 |
| `streams.default_playback_mode` | G | enum `synced|free` | `synced` | Content | Channels | SRS §14.2 |
| `streams.comment_like_throttle_per_minute` | G | int | **30** | Super | Viewer actions | SRS §14.1 |
| `streams.signalling_throttle` | G | json | viewer 240/min, admin 600/min (legacy; review for LiveKit) | Super | Signalling | SRS §14.1 |
| `streams.recording_enabled` | G | bool | *proposed* true | Content | Replay | Spec §9 |
| `homepage_media.dismiss_suppress_hours` | C | int | *proposed* 24 | Content | Floating player | Spec §9.1 |
| `homepage_media.targeting` | E | json (country, language, audience, segment) | — | Content | Scheduling | Spec §9.1 |
| `page_builder.cache_ttl_minutes` | G | int | *proposed* 10 (cleared on save) | Content | Renderer | SRS §18 NFR-7 |
| `app_contexts` | C | table (contexts, order, theme, home template, tab bar) | Main, Super Mall, Food, Minutes, Souq, Travel, Property, Live | Content | Customer app switcher | ADR 16 |
| `footer_settings` | C × locale | json | migration | Content | Storefront footer | SRS §15 |
| `blog.per_page` | G | int | **12** | Content | Blog list | PORTAL_PAGES §11 |
| `blog.related_count` | G | int | **3** | Content | Blog post | PORTAL_PAGES §11 |
| `help_center.search_limit` | G | int | **30** (most viewed first) | Content | Help search | PORTAL_PAGES §10 |
| `static_pages.audience` | E | enum `storefront|portal|both` | — | Content | Static pages | PORTAL_PAGES §12 |
| `cookie_consent.mode` | C | enum `banner|none` | banner, essential-only default | Super | Storefront/portal | `design-coverage.md` |

## 15. Notifications & support (ADR 10, Spec §17.2)

| Key | Scope | Type | Default | Edits | Where used | Source |
|---|---|---|---|---|---|---|
| `notification_templates` | C × locale × event × channel | template | seeded | Content, Marketing | All notifications | ADR 10 |
| `notifications.channels_enabled` | C | json | db, mail, broadcast, fcm, whatsapp, sms | Super | Dispatch | ADR 10 |
| `notifications.sms_provider` | C | enum | TBD | Super | SMS/OTP | Spec §17.2 |
| `notifications.whatsapp_sender` | C | string | TBD (Business API number) | Super | Invitations | SRS §11.7 |
| `tickets.auto_close_days` | G | int | *proposed* 7 | Support | Ticket machine | SRS §15 |
| `tickets.priorities` / `categories` | G | json | low, normal, high, urgent | Support | Tickets | SRS §15 |

## 16. Seller performance, API & AI (SRS §4.1.5, §4.3)

| Key | Scope | Type | Default | Edits | Where used | Source |
|---|---|---|---|---|---|---|
| `seller_score.weights` | C | json (cancellations, late shipment, returns, ratings, SLA) | TBD | Vendor rel. | Performance page | SRS §4.3 |
| `seller_score.strike_threshold_hold` | C | int | *proposed* 3 | Vendor rel. | Auto hold | SRS §4.1.5 |
| `seller_score.strike_expiry_days` | C | int | *proposed* 90 | Vendor rel. | Strikes | SRS §4.1.5 |
| `vendor_api.rate_limit_per_minute` | G | int | *proposed* 120 | Super | Vendor API | SRS §4.3 |
| `vendor_api.token_max_per_vendor` | G | int | *proposed* 5 | Super | API & Tokens | SRS §4.3 |
| `ai.credit_price` / `ai.free_monthly_credits` | C | money / int | TBD | Marketing | AI credits | SRS §4.3 |
| `ai.provider` | G | enum | adapter (TBD) | Super | AI jobs | MP §3 |

## 17. Search & analytics (ADR 9)

| Key | Scope | Type | Default | Edits | Where used | Source |
|---|---|---|---|---|---|---|
| `search.engine` | G | enum `mysql|meilisearch` | `mysql` → `meilisearch` | Super | `SearchEngine` | ADR 9 |
| `search.popular_searches_count` | C | int | *proposed* 10 | Marketing | Search overlay, home section 14 | MP §14c |
| `search.synonyms` | C × locale | json | — | Content | Search | Spec §17.4 |
| `analytics.portal_retention_days` | G | int | *proposed* 180 | Super | `portal-analytics:prune` 04:30 | SRS §17 |
| `analytics.reporting_etl_time` | G | time | *proposed* 01:30 Oman | Super | Nightly ETL | ADR 2 |

## 18. Ops & retention (MP §7 Ops, Spec §19)

| Key | Scope | Type | Default | Edits | Where used | Source |
|---|---|---|---|---|---|---|
| `ops.api_logs_retention_days` | G | int | *proposed* 30 | Super | API logs | Spec §19 |
| `ops.security_events_retention_days` | G | int | *proposed* 365 | Super | Security events | Spec §19 |
| `ops.activity_log_retention` | G | enum | never delete (append-only) | locked | Audit | SRS §2.3 |
| `ops.backup_schedule` | G | json | *proposed* hourly binlog + daily full per DB | Super | Backups | Spec §19 |
| `ops.failed_job_alert_threshold` | G | int | *proposed* 10 / 15 min | Super | System health | MP §7 |
| `ops.maintenance_mode` | C | bool | false | Super | Storefront/panels | Spec §22 |

---

## 19. Who edits what (summary)

| Role | Setting groups |
|---|---|
| Super | §1 country core, §2 identity, §5 credentials view, §7 locked keys, §13, §14 media infra, §16 API, §17 engine, §18 |
| Finance | Tax, payment configs, COD fee, payouts, wallets, subscriptions, membership prices, refund policy, clearing days, fees grids (with Marketing) |
| Operations | Calendars/holidays, shipping, FBN, delivery, classifieds/travel operational durations, return windows |
| Marketing | Marketer settings and grids, attribution, cross-promotion, coupons, flash sales, ads, gift cards, ranking weights |
| Vendor relations | Join toggles, document types, onboarding steps, contract notice, seller score |
| Content | Page builder, app contexts, footer, CMS limits, homepage media, notification templates (with Marketing) |
| Vendor owner | Payout schedule (if allowed), city surcharges, exceptional-zone opt-in, team/roles |
| Customer | Saved-search alert frequency, notification preferences, language/country |
