# Country Reference — 8 Launch Countries
> M2 discovery set · v1.0 · 2026-11-14 · Owner: BA / PM
> Binding source: **MASTER_PLAN v3 §4a** (8 countries on day 1, D2) and ADR 2, 3, 6, 14. Supporting: `timezones.md`, PORTAL_PAGES (country codes), SRS §2.3.
> Everything below that is a rate, weekend or holiday is a **setting** (see `settings-catalogue.md` §1), not code. Items marked **to confirm** need Finance/Ops sign-off (D7).

---

## 1. Master table (MP §4a, verbatim values)

| Country | ISO2 | Portal / DB code | Country DB | Store markets | Currency (decimals) | Time zone | Weekend | VAT/GST | Default gateways (★ recommended) |
|---|---|---|---|---|---|---|---|---|---|
| UAE | AE | `uae` | `nawy_uae` | `uae-en`, `uae-ar` | AED (2) | Asia/Dubai (UTC+4) | Sat–Sun | VAT 5% | Stripe★, PayTabs, PayPal, Tabby, Tamara |
| Oman | OM | `omn` | `nawy_omn` | `omn-en`, `omn-ar` | OMR (3) | Asia/Muscat (UTC+4) | Fri–Sat | VAT 5% | **Thawani★**, PayTabs, Stripe, PayPal |
| Saudi Arabia | SA | `ksa` | `nawy_ksa` | `ksa-en`, `ksa-ar` | SAR (2) | Asia/Riyadh (UTC+3) | Fri–Sat | VAT 15% | PayTabs★, Stripe, PayPal, Tabby, Tamara |
| Egypt | EG | `egy` | `nawy_egy` | `egy-en`, `egy-ar` | EGP (2) | Africa/Cairo (UTC+2 / +3 DST) | Fri–Sat | VAT 14% | PayTabs★, Stripe |
| Kuwait | KW | `kwt` | `nawy_kwt` | `kwt-en`, `kwt-ar` | KWD (3) | Asia/Kuwait (UTC+3) | Fri–Sat | none | PayTabs★, Stripe |
| Qatar | QA | `qat` | `nawy_qat` | `qat-en`, `qat-ar` | QAR (2) | Asia/Qatar (UTC+3) | Fri–Sat | none | PayTabs★, Stripe |
| Bahrain | BH | `bhr` | `nawy_bhr` | `bhr-en`, `bhr-ar` | BHD (3) | Asia/Bahrain (UTC+3) | Fri–Sat | VAT 10% | PayTabs★, Stripe |
| Jordan | JO | `jor` | `nawy_jor` | `jor-en`, `jor-ar` | JOD (3) | Asia/Amman (UTC+3) | Fri–Sat | GST 16% | PayTabs★, Stripe |

### 1.1 Rules (MP §4a, ADR 2, 3, 14)
1. **Single code table.** One `countries` row holds all codes. Storefront: `/{market}` (e.g. `/uae-en`). Portal, help and ads sites: `/{code}/…` (e.g. `/omn/helpcenter`). Prefix-less URLs redirect to the session country, else the first active country (as the old portal does).
2. **Isolation.** Central `nawy_central` (identity, memberships, countries, domains, encrypted gateway credentials, settings, contract templates with country column, audit) + 8 country DBs for operational data. `CountryContext` switches DB, cache, queue and storage prefix. Nightly ETL to `nawy_reporting`.
3. **Money.** `DECIMAL(19,4)` + brick/money. Half-up rounding to the currency minor unit: **3 dp for OMR, KWD, BHD, JOD**; 2 dp for AED, SAR, EGP, QAR. Never sum across currencies.
4. **Time.** UTC storage. Admin panel shows **Oman time**; country date-times are entered in local time with an "= Oman time" label. `CountryClock` exposes weekends and holidays.
5. **Tax.** VAT/GST rates are per-country settings; Kuwait and Qatar have no VAT at launch (rate 0, tax lines hidden).

### 1.2 Currency details
| Currency | Minor unit | Factor | Display EN | Display AR | Example | Notes |
|---|---|---|---|---|---|---|
| AED | fils | 100 | AED 1,299.00 | 1,299.00 د.إ | — | — |
| OMR | baisa | 1000 | OMR 12.500 | 12.500 ر.ع. | Thawani adapter takes **baisa** inside the adapter only (SRS §6) | 3 dp |
| SAR | halala | 100 | SAR 349.00 | 349.00 ر.س | — | New Saudi riyal symbol (2025) — design decision, to confirm |
| EGP | piastre | 100 | EGP 2,499.00 | 2,499.00 ج.م | — | — |
| KWD | fils | 1000 | KWD 9.750 | 9.750 د.ك | — | 3 dp |
| QAR | dirham | 100 | QAR 120.00 | 120.00 ر.ق | — | — |
| BHD | fils | 1000 | BHD 4.250 | 4.250 د.ب | — | 3 dp |
| JOD | fils (ISO 3 dp) | 1000 | JOD 15.500 | 15.500 د.أ | Commonly shown with piastres; ISO 4217 = 3 dp | 3 dp |

Digits: Latin digits (0–9) in both locales for prices, codes and phone numbers (*proposed*, matches Noon). Thousands separator `,`, decimal `.`.

---

## 2. Phone formats

E.164 storage; validation with libphonenumber (metadata per ISO2). Phone is **optional for customers** and used for delivery only (ADR 5, DV1); delivery agents and business contacts may require it.

| Country | Calling code | National significant number | Mobile pattern (indicative) | Example (E.164) | Local display | Notes |
|---|---|---|---|---|---|---|
| UAE | +971 | 9 digits (mobile) | `5[024568]XXXXXXX` | +971 50 123 4567 | 050 123 4567 | Trunk 0 locally |
| Oman | +968 | 8 digits | `[79]XXXXXXX` | +968 9123 4567 | 9123 4567 | No trunk 0 |
| KSA | +966 | 9 digits (mobile) | `5XXXXXXXX` | +966 55 123 4567 | 055 123 4567 | Trunk 0 locally |
| Egypt | +20 | 10 digits (mobile) | `1[0125]XXXXXXXX` | +20 10 1234 5678 | 010 1234 5678 | Trunk 0 locally |
| Kuwait | +965 | 8 digits | `[569]XXXXXXX` (to confirm newer ranges) | +965 5123 4567 | 5123 4567 | No trunk 0 |
| Qatar | +974 | 8 digits | `[3567]XXXXXXX` | +974 3312 3456 | 3312 3456 | No trunk 0 |
| Bahrain | +973 | 8 digits | `(3[0-9]|66)XXXXXX` | +973 3612 3456 | 3612 3456 | No trunk 0 |
| Jordan | +962 | 9 digits (mobile) | `7[789]XXXXXXX` | +962 79 123 4567 | 079 123 4567 | Trunk 0 locally |

Input UX: country-code picker pre-set to the market country; accept local format with trunk 0 and normalise to E.164.

---

## 3. Address formats

Every address stores `lat`, `lng` (map picker in checkout and app, MP §14c), `city_id` (from the country's `cities`, SRS §4.1.14), a free-text landmark, and the recipient phone. Country-specific fields below (*proposed* form layouts; to confirm with Ops).

| Country | Fields in order (required **bold**) | Postal code | National address scheme |
|---|---|---|---|
| UAE | **Emirate**, **Area / community**, **Street**, **Building / villa name or no.**, Flat / floor, Makani no., Landmark | None | Makani (Dubai) optional |
| Oman | **Governorate**, **Wilayat**, **Area**, Way no., **Building / house no.**, Flat, PO Box + postal code, Landmark | 3 digits (with PO Box), optional | — |
| KSA | **City**, **District**, **Street**, **Building no. (4 digits)**, Additional no. (4 digits), **Postal code (5 digits)**, Unit, Short address | 5 digits | Saudi National Address; short address (4 letters + 4 digits) accepted |
| Egypt | **Governorate**, **City / district**, **Street**, **Building no.**, Floor, Apartment, Landmark | 5 digits, optional | — |
| Kuwait | **Governorate**, **Area**, **Block**, **Street**, Avenue (Jadda), **House / building**, Floor, Apartment, PACI no. | 5 digits, optional | PACI civil number optional |
| Qatar | **Zone no.**, **Street no.**, **Building no.**, Unit, Area | None | Blue-plate address (zone/street/building) |
| Bahrain | **Governorate**, **Area**, **Block**, **Road**, **Building**, Flat | None (block used) | — |
| Jordan | **Governorate**, **City**, **Area**, **Street**, **Building no.**, Floor, Apartment, Landmark | 5 digits, optional | — |

Shipping zones are groups of cities per country (SRS §8). Exceptional (remote) zones are configured per country.

---

## 4. Language defaults (OQ4 — to confirm)

| Country | Markets | Proposed default when no prefix / no preference | Email & notification language | Admin UI |
|---|---|---|---|---|
| UAE | `uae-en`, `uae-ar` | `en` | User preference, else market language | EN/AR per admin |
| Oman | `omn-en`, `omn-ar` | `ar` | idem | idem |
| KSA | `ksa-en`, `ksa-ar` | `ar` | idem | idem |
| Egypt | `egy-en`, `egy-ar` | `ar` | idem | idem |
| Kuwait | `kwt-en`, `kwt-ar` | `ar` | idem | idem |
| Qatar | `qat-en`, `qat-ar` | `ar` | idem | idem |
| Bahrain | `bhr-en`, `bhr-ar` | `ar` | idem | idem |
| Jordan | `jor-en`, `jor-ar` | `ar` | idem | idem |

Rules: AR/EN everywhere with RTL (NFR-4); `_ar` / `_en` fields on content; fonts Cairo (AR) and Inter (Latin) (`figma-state.json`). The portal language switcher `/language/{ar|en}` stores the choice in session (PORTAL_PAGES §14). Before launch, compare with the old system's defaults in the migration dump.

Calendar display: Gregorian dates `DD MMM YYYY` (EN) / Arabic month names (AR). Hijri date display is optional per country (*proposed* off; to confirm for KSA).

Week start for date pickers: Sunday for Fri–Sat weekend countries; Monday for UAE (*proposed*).

---

## 5. Time zones and DST

| Country | IANA | UTC offset | DST | 22:00 local = Oman time |
|---|---|---|---|---|
| UAE | Asia/Dubai | +04 | No | 22:00 |
| Oman | Asia/Muscat | +04 | No | 22:00 |
| KSA | Asia/Riyadh | +03 | No | 23:00 |
| Egypt | Africa/Cairo | +02 winter / +03 summer | **Yes** (since 2023: last Friday of April → last Thursday of October; to confirm each year) | 00:00 next day (winter) / 23:00 (summer) |
| Kuwait | Asia/Kuwait | +03 | No | 23:00 |
| Qatar | Asia/Qatar | +03 | No | 23:00 |
| Bahrain | Asia/Bahrain | +03 | No | 23:00 |
| Jordan | Asia/Amman | +03 (permanent since 2022) | No | 23:00 |

Egypt inputs must reject the spring-forward gap and ask on the fall-back overlap (`timezones.md` §7). `timezones.md` still lists only 4 countries — Kuwait, Qatar, Bahrain and Jordan rows above are the additions it needs (STATUS §1).

---

## 6. Holiday calendar placeholders (`country_calendars`) — **to confirm** (D7)

Placeholders only, for seeding `country_calendars` and testing ETA/SLA/payout skipping. Islamic holidays follow moon sighting; dates below are **approximate (±1–2 days)** and must be confirmed by each country's official announcement. Ops owns the calendar; Finance confirms bank-holiday effects on payouts.

### 6.1 Islamic holidays (approximate Gregorian dates)
| Holiday | 2026 (remaining) | 2027 (approx.) | Observed in |
|---|---|---|---|
| Isra and Mi'raj | — | ~6 Jan 2027 | OM, KW (JO to confirm) |
| Eid al-Fitr | — | ~9–11 Mar 2027 (length varies by country) | All 8 |
| Arafat Day | — | ~15 May 2027 | AE, EG, KW, BH (others to confirm) |
| Eid al-Adha | — | ~16–19 May 2027 | All 8 |
| Hijri New Year (1449) | — | ~6 Jun 2027 | AE, OM, EG, KW, BH, JO |
| Ashura | — | ~15–16 Jun 2027 | BH |
| Prophet's Birthday (Mawlid) | — | ~14 Aug 2027 | AE, OM, EG, KW, BH, JO |

### 6.2 Fixed national / civil holidays
| Country | Fixed-date holidays (placeholders) | Remaining 2026 |
|---|---|---|
| UAE | 1 Jan New Year; 1 Dec Commemoration Day; 2–3 Dec National Day (Eid Al Etihad) | 1–3 Dec 2026 |
| Oman | 11 Jan Accession Day (to confirm); 20–21 Nov National Day | 20–21 Nov 2026 |
| KSA | 22 Feb Founding Day; 23 Sep National Day | — |
| Egypt | 7 Jan Coptic Christmas; 25 Jan Revolution / Police Day; Sham el-Nessim (Easter Monday, movable); 25 Apr Sinai Liberation; 1 May Labour Day; 30 Jun Revolution; 23 Jul Revolution; 6 Oct Armed Forces Day | — |
| Kuwait | 1 Jan New Year; 25 Feb National Day; 26 Feb Liberation Day | — |
| Qatar | National Sports Day (2nd Tuesday of Feb); 18 Dec National Day | 18 Dec 2026 |
| Bahrain | 1 Jan New Year; 1 May Labour Day; 16–17 Dec National Day | 16–17 Dec 2026 |
| Jordan | 1 Jan New Year; 1 May Labour Day; 25 May Independence Day; 25 Dec Christmas | 25 Dec 2026 |

### 6.3 Calendar rules
- A delivery ETA, SLA clock and payout date skip the country's **weekend days and holidays** (FR-SHP-2). The old system skipped Friday only.
- Payout run dates that fall on a weekend/holiday move to the next business day (*proposed*).
- Holidays are entered in country local dates; the admin view shows them with the Oman label like other country dates.
- Calendar tests: weekends per country, a holiday inside an ETA window, Egypt DST transition (MP §16).

---

## 7. Payments per country

| Country | Card gateways (seed, ★ recommended) | BNPL (D4) | Internal | Local methods (**D6 — open**) |
|---|---|---|---|---|
| UAE | Stripe★, PayTabs, PayPal | Tabby, Tamara | Wallet, COD, gift card | — |
| Oman | Thawani★, PayTabs, Stripe, PayPal | — | Wallet, COD, gift card | — |
| KSA | PayTabs★, Stripe, PayPal | Tabby, Tamara | Wallet, COD, gift card | (mada via gateways, to confirm) |
| Egypt | PayTabs★, Stripe | — | Wallet, COD, gift card | — |
| Kuwait | PayTabs★, Stripe | — | Wallet, COD, gift card | **KNET** |
| Qatar | PayTabs★, Stripe | — | Wallet, COD, gift card | **NAPS / QPay** |
| Bahrain | PayTabs★, Stripe | — | Wallet, COD, gift card | **BenefitPay** |
| Jordan | PayTabs★, Stripe | — | Wallet, COD, gift card | **eFAWATEERcom** |

Config per country × gateway: enabled, mode, encrypted credentials, currencies, min/max, sort, `is_recommended`, fee rule (ADR 6). Payment success only by webhook or server verification.

---

## 8. Country-specific operational notes

| Country | Notes |
|---|---|
| UAE | Only country with Sat–Sun weekend; Friday is a half day for government (no platform effect, *proposed*). Tabby/Tamara available. |
| Oman | Admin home time zone; Thawani amounts in baisa. 3-dp currency. Flash-sale and payment-failed frames use Oman examples (`figma-state.json`). |
| KSA | Highest VAT (15%). National Address fields required for delivery (*proposed*). |
| Egypt | Only DST country. High COD share expected (*assumption*). |
| Kuwait | No VAT; 3-dp currency; KNET pending D6. |
| Qatar | No VAT; blue-plate addressing; NAPS/QPay pending D6. |
| Bahrain | VAT 10%; 3-dp currency; BenefitPay pending D6. |
| Jordan | GST 16% (general sales tax, not VAT); 3-dp currency; eFAWATEERcom pending D6. |

---

## 9. Open questions

| # | Question | Owner | Status | Impact if unresolved |
|---|---|---|---|---|
| **D6** | Enable local payment methods: KNET (Kuwait), BenefitPay (Bahrain), NAPS/QPay (Qatar), eFAWATEERcom (Jordan)? | Owner | ❓ Open (MP §17) | Lower card conversion in KW/QA/BH/JO; adapters can be added later without schema change. Needed by M8 to include in payment tests. |
| **D7** | Confirm VAT/GST rates (AE 5, OM 5, SA 15, EG 14, KW 0, QA 0, BH 10, JO 16) and weekends (AE Sat–Sun; others Fri–Sat). | Finance | ❓ Open (MP §17) | Wrong invoices and ETA dates; values are settings, so a correction is a config change, not a release. Needed before M7 checkout tests. |
| CQ1 | Official 2027 holiday calendars per country (replace §6 placeholders). | Ops | Open | ETA/SLA/payout dates off by holiday days. |
| CQ2 | Default language per country (§4). | Owner | Open (PRD OQ4) | Redirect target for prefix-less URLs. |
| CQ3 | Address schemes: make KSA National Address and Qatar blue-plate fields mandatory? | Ops | Open | Delivery failures. |
| CQ4 | Free-shipping threshold and COD fee per country (settings). | Finance | Open | Checkout totals. |
| CQ5 | Prices shown VAT-inclusive in all VAT countries? | Finance | Open (*proposed* yes) | PDP/cart display. |
| CQ6 | Egypt DST dates each year — confirm the government rule is still in force. | Ops | Open | Scheduling errors in Egypt. |
| CQ7 | `timezones.md` must add KW, QA, BH, JO (STATUS §1); data-migration plan must add the 4 new country DBs. | BA / DB Architect | Open | Incomplete specs for M6/M15. |
| CQ8 | Is mada (KSA) and Meeza (Egypt) card acceptance required via PayTabs/Stripe? | Finance | Open | Card coverage. |
