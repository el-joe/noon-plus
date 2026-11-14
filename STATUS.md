# Nawy — Status: done vs TODO
Updated 2026-11-14. Plan: `MASTER_PLAN.md` (v3). Figma: https://www.figma.com/design/d2MhBJmRddMrMSHfjJY8Y8 (node IDs in `docs/design/figma-state.json`).
Legend: ✅ done and screenshot-checked · 🟡 partly done · ⬜ not started

## 1. Planning & docs
| Item | State |
|---|---|
| MASTER_PLAN v3 (8 countries, portal parity, Noon-structure app, deviations log) | ✅ |
| Gap review of the SRS, spec and portal against designs and plan | ✅ (findings are in the v3 plan) |
| Reference audits: Noon home, page types and widgets, **Noon app**, OpenSooq, Boksha, Boutiqaat | ✅ |
| Page-builder block registry v2 (51 blocks) + responsive rules | ✅ |
| Data-migration plan + old table inventory (356 tables) | ✅ (needs updating for 8 countries) |
| Time-zone spec | ✅ (needs Kuwait, Qatar, Bahrain, Jordan added) |
| Figma team guide | ✅ `docs/design/figma-team-guide.md` |
| M2 discovery set: PRD, role-permission matrix, lifecycle catalogue, settings catalogue (~200 keys), country reference, risk register, FEATURE_MATRIX, route→frame map (`docs/project/`) | ✅ — owner review needed on items marked *proposed*. route-frame-map §6 lists **65 remaining design gaps** (mostly admin/seller detail screens) |

## 2. Figma — design system & components (all Figma milestones M3–M5 now ✅ pending owner review)
| Item | State |
|---|---|
| Tokens (light/dark), text styles EN/AR, icons, core components | ✅ |
| Storefront components + 51 page-builder blocks + templates (category, sub-category, store, campaign, brand) | ✅ |
| Panel kit v1 + kit v2 (timeline, signature, budget, fee quote, referral, samples, map) | ✅ |
| App components v1 (status/top/tab bars) + **v2** (context switcher, product card ×4, grocery card, chips, coupon, toast, floating cart, tab bar) | ✅ |

## 3. Figma — customer website
| Area | State |
|---|---|
| Home v2, category v2 + templates, PDP, cart, checkout | ✅ |
| Search results, deals hub, flash sale (Egypt), order confirmation, payment failed, empty cart | ✅ |
| Auth (email) + all 22 account pages | ✅ |
| Classifieds (home, results, ad detail, post-ad wizard, shop) | ✅ |
| Modest fashion (category, made-to-order PDP, size-guide drawer) | ✅ |
| Creators (directory, boutique, broker, Nawy TV — admin-produced media) | ✅ |
| Travel (home, trip detail, booking travellers) | ✅ |
| Real estate (results with map, property detail) | ✅ |
| Help centre, help article, legal, 404, 500, gift cards, referral landing | ✅ |
| Mobile web (home, search, product, cart, checkout, account, tracking) | ✅ |
| Arabic/RTL (login + 3 app screens) | ✅ |
| Review gaps: seller storefront; travel destination, payment, confirmation + voucher, manage booking; classifieds map, wanted, inquiry contract gate, report, saved searches, favourites, compare; live player + floating mini-player (3 states); gift-card product; About, Contact, policy template; checkout gates (email, marketer contract), address modal, warranty plan, BNPL/3DS, coupon error; return wizard ×4, dispute, warranty claim, cancel, invoice, write review, my reviews; forgot/reset/verify/social completion; mega menu, search dropdown, 8-country selector, cookie consent; affiliate referral PDP | ✅ 45 frames (page "Web · States & extras") |

## 4. Figma — portal (business.nawy.com)
| Area | State |
|---|---|
| Join chooser, sell landing, advertise landing, application status, knowledge hub (generic) | ✅ |
| Vendor 5-step wizard (Account, Store, Address, Documents, Agreement) + success | ✅ |
| Open Souq landing; travel landing + 2-step wizard + success | ✅ |
| Marketers overview + influencer / affiliate / broker pages; creator & shipping registration | ✅ |
| Seller info ×4, advertising ×6, Ads Knowledge Hub ×3, Seller Help Center ×4, blog ×2, static ×2, legal seller agreement (8 countries), application states ×3, panel sign-in/forgot/reset, WhatsApp invitation, join chooser v2 (4 sections, 8 countries, admin toggles) | ✅ 30 frames |

## 5. Figma — panels
| Item | State |
|---|---|
| Shells: Admin (v1 + v2 SRS IA), Seller Center, Creator Studio, Marketplace, Broker, Travel Partner, Shipping, Ops, Delivery agent | ✅ |
| Templates: seller products list, admin gateways form, admin page builder, admin Live & videos | ✅ |
| Layouts: vendor dashboard + order detail, creator overview + campaign detail, travel dashboard + departures, supervisor dispatch + failed deliveries, delivery my day | ✅ |
| Admin ×8 (dashboard, approvals, order detail, marketer settings grid, contract editor, payouts & COD, countries & locales, system health); Seller ×4; Creator ×3; Marketplace ×4; Broker ×3; Travel ×2; Shipping ×4; Delivery ×2 | ✅ 30 frames (page "Panel layouts") |

## 6. Figma — apps
| App | State |
|---|---|
| **Customer app v2 — Noon structure**: 54 screens (8 contexts, discovery, product, cart and checkout, account, auth, system states) | ✅ |
| Customer app v1 (18 screens + 3 RTL) | ✅ (kept as v1) |
| Delivery app (10 screens; code screen fixed to the agent-sees-code flow) | ✅ |
| Delivery app gaps ×7 (accept/decline, pickup scan, availability, notifications, history, profile & GPS, offline) + ops apps ×14 (sign-in & role switch, vendor ×4, marketer ×4, supervisor ×3, travel ×2) | ✅ 21 screens |

## 7. Build (code)
| Milestone | State |
|---|---|
| M6 Laravel foundation | ✅ **done** (74 Pest tests on MySQL): central DB `noon-plus` + 8 country DBs (`nawy_uae`…`nawy_jor`) with `nawy:countries:create-databases / sync / migrate`; CountryContext + `UsesCountryDatabase` (isolation proven); CountryClock (local→UTC, "= … Oman time" label incl. Egypt DST, per-country weekends); MoneyFactory (3-decimal OMR/KWD/BHD/JOD, half-up); SettingsService (country overrides global); role memberships + Spatie teams = country; customer email-code sign-in API (10-min codes, 5-try lock, throttling, Sanctum tokens); API envelope + `/api/v1/countries`, `/api/v1/{market}/context`, `/me`; **all 6 business panel hosts** (`admin.`, `partner.`, `marketer.`, `travel-agency.`, `carrier.`, `delivery.` on `nawy.test`) with shared email+password sign-in, per-panel role guard and Figma sidebar navigation, Figma-token Tailwind theme, `<x-ui>` kit (button, input, badge, stat card, sidebar item, **datetime-input with live Oman label**), admin dashboard; `ForEachCountry` scheduler runner; `nawy:admin:create`. **Horizon** (on `admin.nawy.test/horizon`, admins only; supervisors for payments/webhooks, default/notifications, reports/migration; snapshot every 5 min) and **Reverb** (Redis queue + cache; private `country.{code}.ops` channels: admins all countries, carrier supervisors own country). plus **two-step panel sign-in** (emailed code, per account), **login history** (panel + API, every outcome), **devices** (list / sign out other devices), **audit trail** (who changed settings, memberships, countries — old and new values). Deferred: routing panels from the `domains` table (hosts come from config for now) |
| M7 Catalog, listings, cart, checkout, orders… | 🟡 **started** — done and tested (119 tests total): catalog in country DBs (category tree, brands, products, variants, vendors), listings with the SRS lifecycle state machine + history with actor and guards (price > 0, active seller, rejection reason), append-only inventory ledger with row locking and automatic out-of-stock/restock transitions, buy box (lowest in-stock price → better seller → oldest), public API: `/{market}/categories`, `/{market}/products` (text, category incl. children, brand, price range, sort, pagination), `/{market}/products/{slug}` (buy box + other offers, 3-decimal currencies). **cart** (guest token + merge on sign-in, 15-min stock holds, expiry job every minute), **coupons** (percentage / fixed / free shipping, platform + seller scope, max 1 + 1, limits, min order, cap), **checkout totals** (per-seller shipping, free over threshold, COD fee, VAT included, 3-decimal currencies), **place order** (row-locked stock re-check, one sub-order per seller, stock taken from ledger, coupon redemptions, verified email required), **sub-order lifecycle** (SRS §7.1 with history and actor, customer cancel before shipping restocks, master status roll-up §7.2), order API (list, detail, cancel shipment). **Next:** real shipping engine, warranty plans, flash sales, auto-complete job, seller/admin order screens |
| M8–M12 | ⬜ |
| M13 Next.js storefront + portal | ⬜ (skeleton + packages installed) |
| M14 Flutter apps | ⬜ (skeletons) |
| M15 Data migration cutover (8 countries) | ⬜ — **owner action:** run the full `mysqldump` into `/var/www/noon-plus/storage-legacy/` |

## 8. Small design follow-ups
- Make an "Ops tab bar" component (ops screens use relabelled copies of the delivery tab bar).
- Make the Contract signature box component width-flexible (instances were set to fill).
- Return policy template: write sections 5–6.
- Review copy from teammates: Arabic brand "ناوي" in help articles; mixed example names (Sara/Youssef).

## 9. Owner actions / open decisions
- D6: local payment methods (KNET, BenefitPay, NAPS/QPay, eFAWATEERcom)?
- D7: confirm VAT/GST rates and weekends per country (§4a).
- Download the full-resolution Canva originals (`docs/design/asset-inventory.md`).
- Run the old-DB dump for migration testing.
- Review the Noon-structure customer app (screens behind Noon's login were designed from standard patterns).
