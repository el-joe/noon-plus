# Design coverage — Figma file https://www.figma.com/design/d2MhBJmRddMrMSHfjJY8Y8
Status: Designed · Partial · Not started. Node IDs: `figma-state.json`.
Updated 14 Nov 2026 — Figma milestones M3 (panels), M4 (website + portal) and M5 (apps) are designed.

## Customer website (pages "Web · …")
| Area | Pages | Desktop | Mobile web | AR/RTL |
|---|---|---|---|---|
| Home | Home v2 (Noon structure, 21 page-builder blocks) | ✅ | ✅ | — (uses RTL rules) |
| Category | Category v2 (Noon template), category with sub-categories, store / campaign / brand-store templates | ✅ | ✅ search grid pattern | — |
| Discovery | Search results, Deals hub, Flash sale (Egypt, local time slots) | ✅ | ✅ search | — |
| Product & checkout | PDP, Cart, Checkout (per-country gateways), Order confirmation, Payment failed (Thawani retry), Empty cart | ✅ | ✅ PDP, cart, checkout | ✅ app checkout |
| Auth | Login, register, OTP — email-based | ✅ | — | ✅ Login AR |
| Account | All 22 pages (orders, tracking, returns, disputes, warranty, bookings, inquiries, special requests, wishlist, chats, credits, gift cards, vouchers, notifications, tickets, profile, addresses, saved cards, contracts, QR, security) | ✅ | ✅ account + tracking | ✅ app account |
| Classifieds (OpenSooq) | Home, results, ad detail, post-ad wizard, seller shop | ✅ | app post-ad | — |
| Modest fashion (Boksha) | Abaya category (fulfilment / ships-within / length filters, designer shops), made-to-order PDP (size & fit selector, measurement form, custom-item returns rule), size-guide drawer (find my size, height table, units) | ✅ | — | — |
| Creators (Boutiqaat) | Directory, creator boutique, broker profile, Nawy TV — all lives/videos admin-produced (ADR 11) | ✅ | app Nawy Live | — |
| Travel | Home (search widget), trip detail (departures with seats, itinerary, policies), booking — travellers step (seat hold timer, passports) | ✅ | — | — |
| Real estate | Results (list + map, premium/verified), property detail (facts, permit info, agent box, viewing request) | ✅ | — | — |
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
- **Customer app v2 — Noon structure** (page "App · Customer v2 (Noon structure)"): 54 screens + 9 components. See MASTER_PLAN §14c.
- **Portal parity** (page "Web · Sell with us"): every PORTAL_PAGES.md page — vendor 5-step wizard, Open Souq, travel 2-step wizard, marketers ×4, seller info ×4, advertising ×6, Ads Knowledge Hub ×3, Seller Help Center ×4, blog ×2, static ×2, legal, application states ×3, panel auth ×3, WhatsApp invitation, join chooser v2 (8 countries).
- **Website states & extras** (page "Web · States & extras"): 45 frames — seller storefront, travel destination/payment/voucher/modify, classifieds map/wanted/contract gate/report/saved/favourites/compare, live player + mini-player, gift-card product, static pages, checkout gates, return wizard, dispute, warranty claim, invoice, reviews, auth recovery, mega menu, search dropdown, 8-country selector, cookie consent, affiliate PDP.
- **Panel layouts** (pages "Page templates" + "Panel layouts"): 39 page layouts across Admin, Seller Center, Creator Studio, Marketplace, Broker, Travel Partner, Shipping, Ops, Delivery.
- **Apps**: delivery app 17 screens (code-match flow per SRS), ops apps 18 screens (shared sign-in + role switch).
Node IDs: `figma-state.json` (keys appV2, portalPages, webExtras, panelLayouts, appScreens). Live status: `/STATUS.md`.
