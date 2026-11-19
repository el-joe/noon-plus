# Design coverage — Figma file https://www.figma.com/design/d2MhBJmRddMrMSHfjJY8Y8
Status: Designed · Partial · Not started. Node IDs: `figma-state.json`.
Updated 14 Nov 2026 — Figma milestones M3 (panels), M4 (website + portal) and M5 (apps) are designed.

## Customer website (pages "Web · …")
| Area | Pages | Desktop | Mobile web | AR/RTL |
|---|---|---|---|---|
| Home | Home v2 (reference structure, 21 page-builder blocks) | ✅ | ✅ | — (uses RTL rules) |
| Category | Category v2 (reference template), category with sub-categories, store / campaign / brand-store templates | ✅ | ✅ search grid pattern | — |
| Discovery | Search results, Deals hub, Flash sale (Egypt, local time slots) | ✅ | ✅ search | — |
| Product & checkout | PDP, Cart, Checkout (per-country gateways), Order confirmation, Payment failed (Thawani retry), Empty cart | ✅ | ✅ PDP, cart, checkout | ✅ app checkout |
| Auth | Login (D9: email code first, then password + "Forgot password?", Google, Apple), register, OTP, forgot / reset password | ✅ | — | ✅ Login AR |
| Account | All 22 pages (orders, tracking, returns, disputes, warranty, bookings, inquiries, special requests, wishlist, chats, credits, gift cards, vouchers, notifications, tickets, profile, addresses, saved cards, contracts, QR, security) | ✅ | ✅ account + tracking | ✅ app account |
| Classifieds (OpenSooq) | Home, results, ad detail, post-ad wizard, seller shop | ✅ | app post-ad | — |
| Modest fashion (Boksha) | Abaya category (fulfilment / ships-within / length filters, designer shops), made-to-order PDP (size & fit selector, measurement form, custom-item returns rule), size-guide drawer (find my size, height table, units) | ✅ | — | — |
| Creators (Boutiqaat) | Directory, creator boutique, broker profile, Nawy TV — all lives/videos admin-produced (ADR 11) | ✅ | app Nawy Live | — |
| Travel | Home (search widget), destination list (EN + AR), trip detail (departures with seats, itinerary, policies, "Request to book", D14 "Sponsored" badge + "You may also like"), booking request — one details form (name, email, phone with country code, departure, rooms, traveller count, optional names, notes; seat-hold timer; estimate + "pay the agency directly"), guest + phone-validation state, request sent, booking status (D8: no payment, no passports) | ✅ | ✅ app 48, 55–58, 64 | ✅ D14 home, trip detail, app 48 + 64 |
| Real estate | Results (list + map, premium/verified), property detail (facts, permit info, agent box, viewing request with name / email / mobile, no payment) | ✅ | — | — |
| Content | Help centre, help article, legal (versioned), 404, 500, gift cards store, referral landing | ✅ | — | — |

## Portal — business.nawy.com (page "Web · Sell with us")
Portal header (component) · Join chooser (8 roles) · Sell landing (fees, FBN) · Advertise landing (ad formats + enquiry) · Seller registration: business, documents, agreement (click-wrap + signature) · Creator registration (influencer / affiliate / broker, channel connect) · Travel agency registration · Shipping partner registration · Application status · Knowledge hub. ✅

## Panels (pages "Panel kit", "Panel shells", "Page templates")
- Shells: Admin, **Admin v2 (full SRS IA, Oman time)**, Seller Center, Creator Studio, Marketplace, Broker, Travel Partner, Shipping, Ops, **Delivery agent**. ✅
- Templates: List page, Payment gateways form, Page builder, **Content · Live & videos (admin only)**. ✅
- Kit v2 components: State timeline, Contract signature box, Budget meter, Fee quote card, Referral card, Sample tracker, Map panel; plus Date-time input / display (Oman label). ✅
- No vendor or creator panel has a Live or Videos menu (ADR 11).

## Mobile apps (page "Apps · Customer & delivery")
- **Customer app — full (18 screens):** splash, country & language, email sign-in, home, categories, search, product, cart, checkout, order placed, orders, tracking + delivery code, account, wishlist, notifications, Nawy Live player, post an ad, Nawy Credits. Plus AR/RTL home, checkout, account.
- **Delivery app — full (10 screens):** phone + device sign-in, my day, route map, stop detail, delivery code, proof of delivery (photo + signature + COD), can’t deliver (reasons, reschedule, return to origin), COD cash & settlements, earnings, wallet & payouts.
- **Ops apps — minimal (4):** vendor (orders + agreement click-wrap), creator (earnings + code), supervisor (fleet), travel agency (bookings).
- App components: Status bar, Top bar, Tab bar (customer), Delivery tab bar.

## Page builder
51 blocks in groups A–J (`page-builder-blocks.md`). Mobile rendering follows the responsive rules in that spec (carousels become swipe rows, grids drop to 2 columns, side banners stack). Separate mobile Figma variants per block are not drawn; the app/mobile-web screens show the patterns.

## Not drawn (by decision, covered by patterns/specs)
- Arabic versions of every page: RTL rules are shown on login + 3 app screens; all pages use the same mirrored layout and `ar/*` Cairo text styles.
- Mobile web versions of vertical pages (classifieds, travel, real estate, modest): they reuse the mobile search/product patterns.
- Every panel content page: panels are layouts + components only (brief), composed from the kit at build time.

## v3 additions (2026-11-14, after the gap review)
- **Customer app v2** (page "App · Customer v2"): 54 screens (now 62 + 6 AR) + 9 components. See MASTER_PLAN §14c.
- **Portal parity** (page "Web · Sell with us"): every PORTAL_PAGES.md page — vendor 5-step wizard, Open Souq, travel 2-step wizard, marketers ×4, seller info ×4, advertising ×6, Ads Knowledge Hub ×3, Seller Help Center ×4, blog ×2, static ×2, legal, application states ×3, panel auth ×3, WhatsApp invitation, join chooser v2 (8 countries).
- **Website states & extras** (page "Web · States & extras"): 45 frames — seller storefront, travel destination/payment/voucher/modify, classifieds map/wanted/contract gate/report/saved/favourites/compare, live player + mini-player, gift-card product, static pages, checkout gates, return wizard, dispute, warranty claim, invoice, reviews, auth recovery, mega menu, search dropdown, 8-country selector, cookie consent, affiliate PDP.
- **Panel layouts** (pages "Page templates" + "Panel layouts"): 39 page layouts across Admin, Seller Center, Creator Studio, Marketplace, Broker, Travel Partner, Shipping, Ops, Delivery.
- **Apps**: delivery app 17 screens (code-match flow per SRS), ops apps 18 screens (shared sign-in + role switch).
Node IDs: `figma-state.json` (keys appV2, portalPages, webExtras, panelLayouts, appScreens). Live status: `/STATUS.md`.

## WPD1 — decision D8 (2026-11-15): bookings without payment
Travel bookings and classified viewing / reserve requests take no payment on Nawy; the agency or seller confirms and is paid directly. Guests can book; seat holds still apply.
- **Component:** `Booking status` chip set `145:6927` (Web components page) — Held, Requested, Confirmed, Completed, Rejected, Cancelled, Hold expired (built from Badge).
- **Web travel:** request form `69:6370` (Oman, signed in), request form guest + phone error `96:4188` (UAE), request sent `96:4554`, booking status `98:10830`. Wizard steps, payment methods, card form, voucher/tax invoice, refund estimate and passport fields/uploads removed.
- **Web classifieds:** viewing / reserve request modal `151:10194` and request sent `151:10399` (page "Web · States & extras"); property viewing form `70:6945` now asks for mobile. The ad detail page had no payment UI.
- **Web account:** My bookings `49:2757` — list with a status chip per state plus a legend (trips + viewings / reserves). Old documents upload and "Paid via" block removed.
- **App v2:** 55 travel request form, 56 request sent, 57 My bookings, 58 booking status, 59 Souq viewing / reserve request, 60 Souq request sent (`152:2650`, `152:2811`, `152:2866`, `152:2974`, `153:2795`, `153:2881`). Account row renamed "My bookings".
- Arabic (wave 4c): AR (RTL) siblings drawn for every booking frame — web request form, request sent, booking status, My bookings, Souq request modal + sent, and app 55–60 (IDs in `figma-state.json` → `wpd1.rtlSiblings`).

## Wave 4c — QA/QC design fixes (2026-11-17)
- **D8 travel panels (QC-021):** Travel Partner dashboard `90:3098` shows request KPIs (requests, confirmed, rejected, seats held); no revenue, refunds, earnings or passports in any travel frame (`90:3414`, `105:16303` now "Booking requests", `105:15807`, shell `12:1392`, ops app `82:1023`).
- **Payments (QC-022, O4):** no PayPal anywhere. Web footer `17:137` has per-country badge booleans (Thawani: Oman only; Tabby / Tamara: UAE and Saudi Arabia), set on all 126 instances. Checkout `25:605` offers Tabby + Tamara instead; payment failed `68:797` offers Stripe; admin gateway template has no PayPal row.
- **Delivery (QC-055, ADR 15):** "match customer code" wording; screen `82:707` renamed "Match delivery code".
- **D9 sign-in:** web login `28:2` + AR `84:2` (email code first → password with "Forgot password?" → Google → Apple); account Security `52:7780` (set / change password, linked Google / Apple with unlink, login history with method); app 43 password link, new app 61 Sign in with password `165:14626`, 62 Reset password `165:14688`, app 41 Security with linked accounts and history. Web forgot / reset frames: `106:8646`, `106:8707`.
- **WPD1 leftovers:** AR (RTL) siblings for all booking frames; "My bookings" everywhere (nav, breadcrumbs, apps); broker profile wording fits D8; ad detail `55:1477` has a "Request a viewing or reserve" trigger for modal `151:10194` (QC-061).
- **Panel shell (QC-025):** checked all 10 shells and 52 layouts — each has the Topbar (search, bell) and the Brand/Logo mark.
- **Naming (QC-063, QC-078):** no "Noon" text, names or slugs left in Figma or PORTAL_PAGES help slugs; Home block `banner_stack` → `banner_grid`; floating mini-player marked as a global setting, not a block.
- **Kit:** new component `App / Ops tab bar` `163:16085` (replaces 13 detached copies); Contract signature box `77:650` is width-flexible; App2 search images fixed (no abaya on phone accessories) and sponsored-brand thumbnails filled from existing image hashes.

## D12 — Open Souq requests are records only (2026-11-18)
Viewing / reserve requests on classified and property ads store name, email, phone, preferred time and message. The customer sees "Request sent — the seller will contact you directly by phone or email" plus the reference; the seller sees a read-only list. No statuses, chips, confirm / reject / schedule, or status emails / SMS. Travel (D8) keeps its statuses.
- **Updated:** app 59 / 60 + AR (`153:2795`, `153:2881`, `166:3236`, `166:3312`), web request form + AR (`151:10194`, `169:11200`), web request-sent modal + AR (`151:10399`, `169:11387`), property viewing form (`70:6945`), web My bookings + AR (`49:2757`, `170:7214`) and app 57 + AR (`152:2866`, `166:3073`) now list trips only, app Account tab gets a "My requests" row.
- **New:** web Account › Inquiries › Requests with a Sent / On my ads toggle: EN `176:7544`, `176:7932` and AR `176:8295`, `176:8732`. App 63 My requests: EN `177:3047`, `177:3331` and AR `177:3189`, `177:3453`. Panel layouts: Seller Center › Marketplace `178:8542` and Creator Studio › Broker `178:8953`, both "Viewing & reserve requests" read-only tables with no actions.
- Node IDs: `figma-state.json` → `d12`.

## D14 — Travel: free listing, paid placement (2026-11-18)
Agencies list trips free and can pay by card to show a trip first, always labelled "Sponsored" / "مُموَّل". Customer bookings stay payment-free (D8), no commission (D11).
- **Components (Web components):** `Travel / Sponsored badge` `179:32006` (EN `179:32000`, AR `179:32003`); `Promotion status` chip set `179:32027` (Pending payment, Active, Expired, Cancelled, Refunded — built from Badge).
- **Web (updated in place):** travel home `69:5767` (promoted cards first with the badge), destination list `96:3770` (badges + "paid placements show first" note), trip detail `69:6093` (badge in the header chips, new "You may also like" row `180:7063`, promoted first).
- **App:** 48 Home — Travel `100:16273` (badge on promoted cards); new 64 Trip detail `181:3087` (header badge + related-trips rail).
- **Arabic (RTL):** travel home `182:7064`, trip detail `182:7545`, app 48 `182:11108`, app 64 `182:11244`.
- **Travel Partner panel (Panel layouts):** Promotions list `184:8855`, Promote a trip `185:9046` (trip → option with price incl. VAT → pay by card), promotion tax receipt `185:9580`.
- **Admin › Growth › Travel promotions** `186:9346`: slot cap + on/off, options table (EN/AR name, duration, price per country) with add and inline edit, promotions list with impressions, cancel without refund and refund.
- **travel_agency_app:** Promote tab `187:2072` (list + "Promote a trip" opens the panel in the browser) and empty state `187:2189`.
- **Drawn in Cleanup 1:** AR of the destination list `190:11125`, the sponsored page-builder `travel_packages_carousel` block `190:13438`, and the website `/checkout/payment?kind=travel_promotion` results `191:1114`, `191:1450`, `191:1793`.
- Node IDs: `figma-state.json` → `d14`.

## Cleanup 1 — D11 / D12 / D14 leftovers (2026-11-18)
- **D11, no travel fees:** Admin › Marketer fee settings `97:1720` has no Travel rule rows or Travel matrix row, plus a note "Travel has no marketer fees" (`188:9756`). Travel Partner package editor `105:15807` shows "Listing fee · Free · no commission". Creator Studio invitations `101:9581` no longer shows a travel-agency campaign. No other frame shows a travel commission or campaign fee.
- **Travel ops app:** `113:1224` (now Departures), `113:1340` (now Requests) and `82:1023` (Dashboard) use the Ops tab bar `163:16085` with Dashboard / Requests / Departures / Promote / More, like the Promote frames `187:2072`, `187:2189`. The App / Top bar `80:7` has a new `Show back` boolean, which is off on all five root-tab screens.
- **D14:** AR (RTL) destination list `190:11125`. Page builder Group H has `Spec / travel_packages_carousel — sponsored (D14)` `190:13438`, where promoted trips come first with the Sponsored badge. The website payment return for `kind=travel_promotion` has paid `191:1114`, waiting `191:1450` and failed `191:1793` frames, with copy from `messages/en.json`. None has a storefront or retry link.
- **D12:** The Marketplace shell `12:938` and Broker shell `12:1149` have a "Viewing requests" sidebar item. The label is shortened to "Viewing requests" / "طلبات المعاينة" in layouts `178:8542` and `178:8953` (sidebar, breadcrumb, title) and in the web Requests captions. Web Inquiries `50:2352` is now the Chats tab of **Chats / Requests**, with an All / Open / Closed filter.
- **Open:** The travel app code labels tab 1 "Summary" (`nav.dashboard`), but the design says "Dashboard". The body of `113:1340` still shows the older inquiry cards, not the code's Confirm / Reject booking requests.
- Node IDs: `figma-state.json` → `cleanup1`.
