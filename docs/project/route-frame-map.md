# Route → Figma Frame Map
> M2 discovery set · v1.0 · 2026-11-14 · Owner: BA / PM
> Figma file: https://www.figma.com/design/d2MhBJmRddMrMSHfjJY8Y8 · Node IDs from `docs/design/figma-state.json` (keys `webPages`, `webExtras`, `portalPages`, `panelLayouts`, `shells`, `templates`, `appScreens`, `appV2`). Routes from SRS §4.2–§4.8, PORTAL_PAGES, MASTER_PLAN §5a/§14/§14c, Spec §15.2.
> Purpose (Spec §15.3): every required UI route has a design reference, or is listed as a gap.

## Legend

| Mark | Meaning |
|---|---|
| `nn:nnnn` | Figma node ID of the frame (open as `…?node-id=nn-nnnn`). |
| **no frame** | No frame exists. These rows are collected in §6 (gap list). |
| no frame · *pattern* | No dedicated frame, but an existing frame/template covers the layout (acceptable under DV2 for panels; listed in §6 only when the page is complex). |
| ⚠ | Complex page (state machine, money, map, multi-panel editor). Needs its own layout before its build milestone. |
| N/A | Not a page (redirect, file stream, AJAX endpoint). |

Figma pages: Web · Home & discovery `15:4` · Web · Product & checkout `15:5` · Web · Account `15:6` · Web · Verticals `15:7` · Web · Sell with us `15:8` · Web · Mobile `15:9` · Web · States & extras `95:4089` · Panel shells `2:142` · Page templates `2:143` · Panel layouts `96:1691` · Apps · Customer & delivery `80:2` · App · Customer v2 (Noon structure) `94:11699`.

---

## 1. Storefront routes — Next.js `/{market}` (e.g. `/uae-en`)

### 1.1 Global layout & system

| Route / element | Frame | Node | Mobile / app frame |
|---|---|---|---|
| Header, category nav, footer (components) | Web header / Category nav / Web footer | 17:55 / 17:94 / 17:137 | — |
| Mega menu | Mega menu | 107:16631 | App Categories 96:564 |
| Search dropdown / suggestions | Search suggestions | 107:17020 | App 06 Search recent 96:668, 07 Suggestions 96:722 |
| Country & language selector (8 countries) | Country & language selector | 107:17386 | App 42 Country & language 99:6453 |
| Cookie consent | Cookie consent | 107:17754 | — |
| Floating homepage media / mini-player (expanded, collapsed, dismissed) | Mini player expanded / collapsed / dismissed | 100:13112 / 100:13436 / 100:13734 | — |
| `/404` | Error 404 | 71:10890 | App 54 System states 100:16592 |
| `/500` | Error 500 | 71:11072 | App 54 System states 100:16592 |
| Prefix-less `/` → market redirect | N/A | — | — |

### 1.2 Home & discovery

| Route | Frame | Node | Mobile web / app |
|---|---|---|---|
| `/{market}` home | Home v2 desktop (Noon structure, 21 blocks) | 30:1208 | Home mobile web 26:2 · App 01 Home Nawy 95:2 |
| `/{market}/[...categorySlug]` category | Category v2 (Noon category template) | 40:4438 | App 05 Categories 96:564 |
| Category with sub-categories | Template / Category with sub-categories | 47:4471 | — |
| Modest-fashion category (abayas) | Modest category (abayas) | 65:4734 | — |
| Store landing (`store_landing`) | Template / Store landing | 47:5448 | — |
| Campaign page `/campaign/[slug]` | Template / Campaign | 47:6618 | — |
| Brand store `/brand/[slug]` | Template / Brand store | 47:7764 | App 13 Brand store 96:1600 |
| `/search?q=` | Search results | 67:8168 | Mobile web search 85:384 · App 08 Results grid 96:763, 09 Results list 96:918, 10 Filter sheet 96:1046, 11 Sort sheet 96:1272 |
| `/deals` | Deals hub | 67:9288 | App 12 Deals 96:1449 |
| `/flash-sale/[slug]` | Flash sale (Egypt) | 67:9994 | — |
| `/seller/[seller_id]` | Seller storefront | 95:4090 | App 20 Seller store 97:4210 |
| Vertical landing (`vertical_landing` template: Super Mall, Food, Minutes on web) | **no frame** | — | App 02 Super Mall 95:480, 03 Minutes 95:630, 04 Food 95:755 |
| Search results — empty / no results state | **no frame** | — | — |
| Popular searches page / section | covered by home block + search overlay | 30:1208 | App 06 96:668 |

### 1.3 Product

| Route | Frame | Node | Mobile web / app |
|---|---|---|---|
| `/products/[slug]` PDP | Product detail desktop | 24:2 | Mobile web product 85:445 · App 14 Product 97:3692, 15 Variant sheet 97:3960, 16 Added to cart 97:4008 |
| PDP — made to order | Modest PDP made to order | 65:5397 | — |
| `/size-guide/[id]` | Modest PDP size guide drawer | 65:5965 | — |
| PDP via affiliate referral (marketer listing) | Affiliate referral PDP | 107:18117 | — |
| Reviews list / write review | Write review (modal) | 105:8417 | App 17 Reviews 97:4106, 18 Write review 97:4142 |
| Product Q&A (web) | **no frame** | — | App 19 Q&A 97:4183 |
| `/compare` product comparison (FR-CAT-6) | **no frame** | — | — |
| `/r/[code]` referral redirect | Referral landing | 71:11488 | — |

### 1.4 Cart & checkout

| Route | Frame | Node | Mobile web / app |
|---|---|---|---|
| `/cart` | Cart desktop | 25:275 | Mobile web cart 85:485 · App 21 Cart 98:1497 |
| `/cart` empty | Cart empty | 68:1014 | App 22 Cart empty 98:1628 |
| Coupon sheet / coupon error | Coupon error | 102:17493 | App 23 Coupons 98:1721 |
| `/checkout` | Checkout desktop | 25:605 | Mobile web checkout 85:549 · App 24 Checkout 98:1762 · AR 84:615 |
| Checkout — email verification gate | Checkout email gate | 102:16536 | — |
| Checkout — marketer contract gate (FR-CHK-4) | Checkout marketer contract gate | 102:16764 | — |
| Add address modal / map picker | Add address modal | 102:17008 | App 25 Address map 98:1841 |
| Warranty plan selection | Warranty plan selection | 102:17220 | — |
| BNPL + 3-D Secure waiting | BNPL + 3DS waiting | 102:17396 | App 26 3DS 98:1882 |
| `/checkout/failed` | Payment failed (Oman) | 68:797 | App 27 Payment failed 98:1896 |
| `/checkout/success` | Order confirmation | 68:591 | App 28 Order placed 98:1920 |

### 1.5 Auth

| Route | Frame | Node | App |
|---|---|---|---|
| `/login` (email) | Login | 28:2 | App 43 Welcome 100:15953 |
| `/login` AR (RTL) | Login AR (RTL) | 84:2 | — |
| `/register` | Register | 28:196 | App 45 Create account 100:16013 |
| `/verify-code` (6-digit email code) | OTP | 28:107 | App 44 Email code 100:15988 |
| `/forgot-password` | Forgot password | 106:8646 | — |
| `/reset-password` | Reset password | 106:8707 | — |
| `/verify-email` | Verify email | 106:8799 | — |
| Social sign-up completion (Google/Apple) | Complete social sign-up | 106:8882 | — |
| App permissions (push, location) | — | — | App 46 Permissions 100:16069 |

### 1.6 Account (`/account/*`)

| Route | Frame | Node | Mobile web / app |
|---|---|---|---|
| `/account` overview | Account overview | 33:134 | Mobile web account 85:596 · App 29 Account 99:5826 · AR 84:679 |
| `/account/orders` | Account orders | 33:658 | App 30 Orders 99:5926 |
| `/account/orders/[id]` detail + tracking (delivery code) | Order detail & tracking | 34:1283 | Mobile web tracking 85:680 · App 31 Order detail 99:5978, 32 Track 99:6021 |
| Order cancel | Cancel order modal | 105:7863 | — |
| Order invoice | Invoice view | 105:8184 | — |
| `/account/returns` | Account returns | 49:1155 | — |
| Return wizard steps 1–4 | Return step 1 / 2 / 3 / 4 | 104:5646 / 104:6111 / 104:6547 / 104:7004 | App 33 Return items 99:6051, 34 Return pickup 99:6097 |
| `/account/disputes` + open dispute | Account disputes / Open dispute | 49:1706 / 105:7009 | — |
| `/account/warranty` (claims + my warranties) + claim | Account warranty / Warranty claim | 49:2222 / 105:7433 | — |
| `/account/bookings` (trip bookings) | Account trip bookings | 49:2757 | — |
| `/account/inquiries` (classified inquiries) | Account classified inquiries | 50:2352 | — |
| `/account/special-requests` (list) | Account special requests | 50:2886 | — |
| Special request — create form | **no frame** | — | — |
| `/account/wishlist` (+ groups) | Account wishlist | 50:3374 | App 37 Wishlist 99:6203 |
| Public shared wishlist group `/wishlist/[share]` | **no frame** | — | — |
| `/account/chats` | Account chats | 50:4032 | App 51 Chats 100:16496 |
| `/account/credits` (Nawy Credits, promotional) | Account Nawy Credits | 51:3692 | App 35 Credits 99:6129 |
| `/account/gift-cards` | Account gift cards | 51:4188 | App 53 Gift cards 100:16549 |
| `/account/vouchers` | Account vouchers | 51:4690 | — |
| `/account/notifications` | Account notifications | 51:5222 | App 38 Notifications 99:6324 |
| `/account/tickets` (list) | Account support tickets | 51:5770 | — |
| Ticket detail / new ticket | **no frame** | — | — |
| `/account/profile` | Account profile | 52:5223 | — |
| `/account/addresses` | Account addresses | 52:5753 | App 39 Addresses 99:6362 |
| `/account/payments` (saved cards) | Account saved cards | 52:6302 | App 40 Cards 99:6393 |
| `/account/contracts` (My Contracts) | Account my contracts | 52:6780 | App 52 Contracts 100:16528 |
| `/account/qr` | Account QR | 52:7294 | — |
| `/account/security` (devices, login history, 2FA n/a) | Account security | 52:7780 | App 41 Security 99:6414 |
| `/account/reviews` (my reviews) | My reviews | 105:8879 | — |
| `/account/membership` (Nawy One) | **no frame** | — | App 36 Nawy One 99:6173 |
| `/account/favourites` (listings, properties) | Favourites | 100:11857 | — |

### 1.7 Classifieds (Open Souq) & real estate

| Route | Frame | Node | App |
|---|---|---|---|
| `/classified` home | Classifieds home | 54:2 | App 47 Souq 100:16089 |
| `/classified/[categorySlug]` results | Classifieds results | 54:1139 | — |
| `/classified/find/[slug]` ad detail (+ inquiry form) | Classified ad detail | 55:1477 | — |
| Post an ad (customer-facing wizard) | Classified post-ad wizard | 55:2016 | App 17 Post ad 81:484 |
| Classified seller shop | Classified seller shop | 57:2192 | — |
| Map view | Classifieds map view | 98:11315 | — |
| Wanted ads | Wanted ads | 98:11480 | — |
| Inquiry contract gate (`customer_contract_required`) | Inquiry contract gate modal | 98:11859 | — |
| Report ad | Report ad modal | 98:12023 | — |
| Saved searches | Saved searches | 100:11530 | — |
| Compare ads | Compare ads | 100:12283 | — |
| `/property` results (list + map) | Real estate results | 70:6418 | App 49 Property 100:16388 |
| `/property/[slug]` detail (viewing request) | Property detail | 70:6770 | — |

### 1.8 Travel

| Route | Frame | Node | App |
|---|---|---|---|
| `/travel` home | Travel home | 69:5767 | App 48 Travel 100:16273 |
| `/travel/destination/[slug]` | Travel destination Georgia | 96:3770 | — |
| `/travel/[slug]` trip detail | Trip detail | 69:6093 | — |
| Booking — travellers (seat hold timer) | Trip booking travellers | 69:6370 | — |
| Booking — payment | Travel booking payment | 96:4188 | — |
| Booking — confirmation + voucher | Travel confirmation + voucher | 96:4554 | — |
| Manage / modify booking | Travel manage booking | 98:10830 | — |
| Travel inquiry form (FR-TRV-4) | **no frame** (assumed inside trip detail; not confirmed) | — | — |

### 1.9 Creators, live media, gift cards, content

| Route | Frame | Node | App |
|---|---|---|---|
| `/marketers` directory | Creators directory | 57:2752 | — |
| `/marketer/[slug]` (influencer/affiliate boutique) | Creator boutique | 59:3228 | — |
| `/marketer/[slug]` (broker profile) | Broker profile | 59:4007 | — |
| `/live-streams` (Nawy TV) | Nawy TV | 59:4518 | App 50 Nawy TV feed 100:16473 · v1 16 Nawy Live 81:452 |
| `/live-streams/[id]` player (live / recorded) | Live stream player | 100:12627 | — |
| `/gift-cards` | Gift cards | 71:11244 | App 53 Gift cards 100:16549 |
| `/gift-cards/[slug]` | Gift card product | 101:4121 | — |
| `/pages/about-us` | Static About | 101:4452 | — |
| `/pages/contact-us` | Static Contact | 101:4695 | — |
| `/pages/{terms, privacy, return, shipping, refund}` | Policy template | 101:4971 | — |
| Legal (versioned) page | Legal page | 71:10697 | — |
| `/help` customer help centre | Help centre | 71:10213 | — |
| `/help/[slug]` article | Help article | 71:10487 | — |
| "Nawy Options" page (SRS §4.8) | **no frame** | — | — |
| Newsletter subscribe | Web footer component | 17:137 | — |

---

## 2. Portal routes — `business.{base}` (PORTAL_PAGES)

| Route | Frame | Node |
|---|---|---|
| Portal header (component) | Portal header | 72:2 |
| `/` → `/join` | N/A (redirect) | — |
| `/join` chooser (4 sections, 8 countries, toggles) | Join chooser v2 | 101:16699 (v1: 72:31) |
| `/companies`, `/register`, `/register/success` (legacy) | N/A (301) | — |
| `/vendors` landing | Sell landing | 72:215 |
| `/vendors/register` step 1 Account | Seller reg — 1 Account | 93:997 |
| step 2 Store | Seller reg — business | 73:384 |
| step 3 Contact & address | Seller reg — 3 Address | 93:1128 |
| step 4 Documents | Seller reg — documents | 73:594 |
| step 5 Agreement & e-signature | Seller reg — agreement | 73:724 |
| `/vendors/register/success` | Seller reg — success | 93:1258 |
| Application status — in review | Application status | 73:1273 |
| Application status — approved / rejected / more documents | Status Approved / Rejected / More documents | 100:18238 / 100:18417 / 100:18594 |
| `/companies/open-souq` | Open Souq landing | 93:1299 |
| `/companies/travel-agency` | Travel landing | 93:1481 |
| `/companies/travel-agency/register` step 1 Owner | Travel reg — 1 Owner | 93:1654 |
| step 2 Agency details | Travel agency registration | 73:1017 |
| `/companies/travel-agency/register/success` | Travel reg — success | 93:1750 |
| `/marketers` overview | Marketers overview | 93:1791 |
| `/marketers/influencer` | Marketer — Influencer | 93:1939 |
| `/marketers/affiliate` | Marketer — Affiliate | 93:2100 |
| `/marketers/broker` | Marketer — Broker | 93:2261 |
| Marketer / broker registration (new journey) | Creator registration | 73:846 |
| Shipping-partner registration (new journey) | Shipping partner registration | 73:1145 |
| WhatsApp signed invitation page | Marketer WhatsApp invitation | 101:16484 |
| `/faq` | Seller FAQ | 95:3107 |
| `/how-it-works` | How it works | 95:3306 |
| `/fulfillment` | Fulfillment FBM vs FBN | 95:3538 |
| `/smart-tools` | Smart tools | 95:3847 |
| `/{code}/advertise/sellers` | Advertise Sellers | 96:7258 |
| `/{code}/advertise/brands` | Advertise Brands | 96:7491 |
| `/{code}/advertise/advertisers` | Advertise Advertisers | 96:7733 |
| `/{code}/advertise/product` | Advertise Product ads | 96:7964 |
| `/{code}/advertise/display` | Advertise Display ads | 96:8326 |
| `/{code}/advertise/request` | Advertise Request form | 96:8534 |
| Generic advertise landing (extra) | Advertise landing | 72:418 |
| `/{code}/adsupport` | Ads KH Home | 97:7671 |
| `/{code}/adsupport/collections/{slug}` | Ads KH Collection | 97:7920 |
| `/{code}/adsupport/articles/{slug}` | Ads KH Article | 97:8135 |
| Knowledge hub (generic, extra) | Knowledge hub | 73:1361 |
| `/{code}/helpcenter` | Help Center Home | 98:8666 |
| `/{code}/helpcenter/search?q=` | Help Center Search | 98:8896 |
| `/{code}/helpcenter/category/{slug}` | Help Center Category | 98:9129 |
| `/{code}/helpcenter/article/{slug}` | Help Center Article | 98:9364 |
| `/blog` (list, filters, 12/page) | Blog List | 100:4258 |
| `/blog/{slug}` | Blog Post | 100:4552 |
| `/pages/about-us` | Static template About | 100:4757 |
| `/pages/contact-us` | Static Contact | 100:4922 |
| `/pages/{terms-and-conditions, privacy-policy, return-policy, shipping-policy, refund-policy}` | no frame · *pattern* Static template About 100:4757 (storefront Policy template 101:4971 also fits) | — |
| `/legal/seller-agreement?country=` | Legal Seller Agreement | 100:5153 |
| `/legal/seller-agreement/{template}/{ar|en}` | N/A (file stream) | — |
| `/language/{ar|en}`, `/country/{code}` | N/A (session switch) | — |
| Panel sign-in (all panel hosts) | Panel sign in | 100:18796 |
| Panel forgot password | Panel forgot password | 100:18961 |
| Panel reset password | Panel reset password | 100:19120 |
| Panel 2FA challenge (optional 2FA, ADR 5) | **no frame** | — |

---

## 3. Panel page types

Shells: Admin `12:2` · **Admin v2 (SRS IA) `74:1394`** · Seller Center `12:410` · Creator Studio `12:687` · Marketplace `12:938` · Broker `12:1149` · Travel Partner `12:1392` · Shipping `12:1642` · Ops `12:1891` · Delivery agent `74:1909`.
Generic templates: **List page `13:2`** (Seller Center Products) · **Form page `13:648`** (Admin Oman payment gateways). Kit v2 components: State timeline 77:622, Contract signature box 77:650, Budget meter 77:664, Fee quote card 77:682, Referral card 77:700, Sample tracker 77:724, Map panel 77:746, Date-time input (Oman label) 45:324, Date-time display 45:325, Data table 11:147, Wizard step 11:436, Modal 11:375, Stat card 9:301.

### 3.1 Admin (`admin.`) — SRS §4.1

| Page type (SRS §) | Frame | Node |
|---|---|---|
| Dashboard (4.1.1) | Admin dashboard | 96:1693 |
| Analytics (4.1.1) | no frame · *pattern* dashboard 96:1693 | — |
| AI dashboard (4.1.1) | **no frame** | — |
| Approval queues (applications, listings, campaigns, packages, certifications, change requests, classifieds) | Admin approvals queue | 96:2483 |
| Order detail (4.1.4) | Admin order detail | 97:1164 |
| Orders list, vendors list, customers list, all CRUD lists | no frame · *pattern* List 13:2 | — |
| Product editor (variants, GTIN, per-country settings) ⚠ | **no frame** | — |
| Category editor (commission, samples, min stock, contract switch, Marketers tab) ⚠ | **no frame** | — |
| Flash-sale event (window, invites, review prices, live monitor, analytics) ⚠ | **no frame** | — |
| Warehouses & inventory adjustments, FBN inbound review, storage fees | no frame · *pattern* List 13:2 + State timeline | — |
| Dispute mediation thread ⚠ | **no frame** | — |
| Return inspection (per-item outcome, restock) ⚠ | **no frame** | — |
| Warranty claim thread | **no frame** | — |
| Vendor detail (strikes, hold, blacklist, lock sections, account manager, contracts) ⚠ | **no frame** | — |
| Application review detail (documents, approve / reject / more docs) ⚠ | **no frame** (queue only: 96:2483) | — |
| Admins, roles & permissions editor ⚠ | **no frame** | — |
| Marketer detail (rules, onboarding/collab/exclusive contracts) ⚠ | **no frame** | — |
| Marketer campaign detail (approve, invite more, resend, samples) | no frame · *pattern* Creator campaign detail 90:2785 + Sample tracker | — |
| Marketer settings grid (4.1.6) | Admin marketer settings grid | 97:1720 |
| Marketing fees grid + calculator (4.1.7) | no frame · *pattern* 97:1720 + Fee quote card 77:682 | — |
| Cross-promotion policy editor (N) ⚠ | **no frame** | — |
| Attribution settings (N) | no frame · *pattern* Form 13:648 | — |
| Banner editor (placement, schedule, audience) | no frame · *pattern* Form 13:648 + Date-time input | — |
| Coupon editor (eligibility rules) / coupon participation | no frame · *pattern* Form 13:648 | — |
| Ad campaigns review, ad slots, paid ad bookings (creative review + admin video upload) ⚠ | **no frame** | — |
| Gift cards, vouchers, newsletter, cart card offers | no frame · *pattern* List/Form | — |
| Classified categories (sections, contracts per country), classified review (attachments) | no frame · *pattern* approvals queue 96:2483 | — |
| Contract template editor (4.1.9) | Admin contract template editor | 98:3625 |
| Customer contracts, contract signatures | no frame · *pattern* List 13:2 | — |
| Travel agencies, packages review, bookings, destinations, inclusions, units | no frame · *pattern* List/Form + approvals 96:2483 | — |
| Delivery assignments live map / zones map ⚠ | **no frame** (Map panel component 77:746 only) | — |
| Carrier claims, scorecard, shipping companies | no frame · *pattern* Shipping claims & scorecard 107:19067 | — |
| Payout batch & COD settlements (4.1.12) | Admin payout batch & COD | 98:4242 |
| Ledger (transaction groups) ⚠ | **no frame** | — |
| Wallets (adjust, freeze, withdrawals) | no frame · *pattern* List 13:2 | — |
| Delivery payouts, transactions, financial reports, subscriptions/plans/invoices, warranty purchases, reconciliation | no frame · *pattern* List 13:2 / 98:4242 | — |
| Acquisition commissions (rules + My) | no frame · *pattern* List/Form | — |
| Page builder (4.1.13) | Admin Page builder | 32:875 |
| Content · Live & videos (admin-only) | Admin Content · Live & videos | 78:1551 |
| Live studio (Go Live, camera/mic, comments moderation) ⚠ | **no frame** (list/scheduling only in 78:1551) | — |
| App contexts editor (switcher, theme, tab bar) ⚠ | **no frame** | — |
| Blog, Knowledge Hub, Help Center, FAQs, static pages, portal content, footer | no frame · *pattern* List/Form | — |
| Portal analytics (visitors, funnel) ⚠ | **no frame** | — |
| Countries & locales (4.1.14 + calendars, tax) | Admin countries & locales | 99:2690 |
| Payment gateways (credentials, test, webhook logs) | Form page — Admin Oman payment gateways | 13:648 |
| Shipping zones / methods / rates / slabs / subsidies / vendor zone alerts | no frame · *pattern* List/Form | — |
| Document types matrix (country × business type × category) ⚠ | **no frame** | — |
| Onboarding-step builder (N) ⚠ | **no frame** | — |
| Join options toggles (N) | no frame · *pattern* Form 13:648 | — |
| Settings catalogue screens | no frame · *pattern* Form 13:648 | — |
| Notification templates (N) | **no frame** | — |
| Activity log | no frame · *pattern* List 13:2 | — |
| System health (N) | Admin system health | 99:3364 |
| Support tickets (reply thread) | **no frame** | — |
| In-app documentation (4.1.16) | **no frame** | — |
| Account-manager variant (masked finance) | no frame · *pattern* Admin v2 shell 74:1394 | — |

### 3.2 Partner — Seller Center (`partner.`, product vendor) — SRS §4.3

| Page type | Frame | Node |
|---|---|---|
| Dashboard | Vendor dashboard | 90:1821 |
| Orders list | no frame · *pattern* List 13:2 | — |
| Order detail (confirm, pack, ready, cancel, print) | Vendor order detail | 90:2170 |
| Products / listings list | List page — Seller Center Products | 13:2 |
| Create / edit listing | Seller create listing | 100:8566 |
| Campaign wizard | Seller campaign wizard | 100:8967 |
| My campaigns list / campaign detail (approve marketer request, samples) | no frame · *pattern* List 13:2 / Creator campaign detail 90:2785 | — |
| Payout statement | Seller payout statement | 100:17344 |
| FBN inbound | Seller FBN inbound | 100:17809 |
| Returns, warranty claims, carrier claims, disputes (respond) | no frame · *pattern* List 13:2 + State timeline | — |
| Product requests, size guides, change requests | no frame · *pattern* List/Form | — |
| Size-guide editor ⚠ | **no frame** | — |
| Inventory (adjustments, movements) | no frame · *pattern* List 13:2 | — |
| AI credits | **no frame** | — |
| Weight calculator | **no frame** | — |
| Warehouses / transfers, city surcharges | no frame · *pattern* List/Form | — |
| Exceptional zones opt-in (map) ⚠ | **no frame** | — |
| Packaging supplies (catalog + cart) ⚠ | **no frame** | — |
| Flash sales (accept invite, submit prices) ⚠ | **no frame** | — |
| Coupons, coupon participation | no frame · *pattern* List/Form | — |
| Ads (self-serve CPC/CPM campaign builder) ⚠ | **no frame** | — |
| Nawi ads browse + ad booking (calendar, creative, pay) ⚠ | **no frame** | — |
| Legal documents / click-wrap consent page | **no frame** (Contract signature box 77:650; app 82:957 shows click-wrap) | — |
| Pending contracts, contract history | no frame · *pattern* List + Contract signature box | — |
| Performance (score, SLA, strikes) ⚠ | **no frame** | — |
| Support tickets | no frame · *pattern* List 13:2 | — |
| Transactions, sales report, wallet, subscription | no frame · *pattern* List / Creator wallet 101:10009 | — |
| API & tokens | no frame · *pattern* List/Form | — |
| Profile (locked sections), shipping companies, payment methods, bank accounts, documents | no frame · *pattern* Form 13:648 | — |
| Team, roles editor ⚠ | **no frame** | — |
| Notifications | no frame · *pattern* List | — |

### 3.3 Partner — Marketplace (`partner.`, classified vendor)

| Page type | Frame | Node |
|---|---|---|
| My ads | Marketplace my ads | 102:10964 |
| Create ad (contract first) | Marketplace create ad | 102:11429 |
| Mark sold (final price, referring marketer) | Marketplace mark sold | 103:5462 |
| Inquiries | Marketplace inquiries | 103:5763 |
| Dashboard | **no frame** (shell 12:938 only) | — |
| Conversations | **no frame** | — |
| Paid boosting / featured / renewal | **no frame** | — |
| Listing statistics / reports | **no frame** | — |
| Subscription packages, payments, profile, support | no frame · *pattern* List/Form | — |

### 3.4 Marketer — Creator Studio (`marketer.`)

| Page type | Frame | Node |
|---|---|---|
| Overview / dashboard | Creator overview | 90:2452 |
| Campaign detail (link, code, QR, conversions) | Creator campaign detail | 90:2785 |
| Invitations | Creator invitations | 101:9581 |
| Wallet (pending / spendable, withdrawals) | Creator wallet | 101:10009 |
| Cross-promotion participation | Creator cross-promotion | 101:10450 |
| Register / onboarding (job types) / onboarding contract (`/contract`) ⚠ | **no frame** (portal Creator registration 73:846 covers sign-up only) | — |
| Active / finished campaigns lists | no frame · *pattern* List 13:2 | — |
| Request a campaign; peer products; promoted by others | **no frame** | — |
| Campaign marketplace + applications (N) | **no frame** | — |
| Samples | no frame · *pattern* Sample tracker 77:724 | — |
| My listings (own products), inventory, orders, product requests | no frame · *pattern* List 13:2 / Seller create listing 100:8566 | — |
| Classified listings, classified inquiries, wanted listings | no frame · *pattern* Marketplace 102:10964 / 103:5763 | — |
| Exclusive contracts, contracts | no frame · *pattern* List | — |
| Conversations (customer chat) ⚠ | **no frame** | — |
| Promote (ad slot booking), ad packages | **no frame** | — |
| Flash sales, coupon participation, marketer coupons | no frame · *pattern* List/Form | — |
| Content submission (N) | **no frame** | — |
| Media library (admin-curated, browse/share) | **no frame** | — |
| Commissions, reports | no frame · *pattern* List | — |
| Profile (social accounts, ad price), support, notifications | no frame · *pattern* Form | — |
| Agency switcher / agency dashboard (N) ⚠ | **no frame** | — |

### 3.5 Marketer — Broker

| Page type | Frame | Node |
|---|---|---|
| Leads pipeline (+ follow-ups) | Broker leads pipeline | 104:21867 |
| Special requests | Broker special requests | 104:22251 |
| Properties | Broker properties | 104:22573 |
| Property create / edit | no frame · *pattern* Marketplace create ad 102:11429 | — |
| Dashboard, commissions, wallet | no frame · *pattern* Creator overview 90:2452 / wallet 101:10009 | — |
| Lead analytics / reports | **no frame** | — |
| Documents, paid visibility, support | no frame · *pattern* List/Form | — |

### 3.6 Travel Partner (`travel-agency.`) — SRS §4.5

| Page type | Frame | Node |
|---|---|---|
| Dashboard | Travel dashboard | 90:3098 |
| Departures & capacity | Travel departures | 90:3414 |
| Package editor | Travel package editor | 105:15807 |
| Bookings & documents | Travel bookings & documents | 105:16303 |
| Packages list | no frame · *pattern* List 13:2 | — |
| Bookable units | no frame · *pattern* List/Form | — |
| Inquiries | no frame · *pattern* Marketplace inquiries 103:5763 (app: 113:1340) | — |
| Campaigns (create, quote, pause, cancel, export) | no frame · *pattern* Seller campaign wizard 100:8967 | — |
| Booking modifications, optional services | **no frame** | — |
| Finance (wallet, payouts, revenue, sales report, operator settlement) | no frame · *pattern* Creator wallet 101:10009 / Seller payout statement 100:17344 | — |
| Reports, performance | **no frame** | — |
| Team / roles, bank accounts, profile, support | no frame · *pattern* List/Form | — |

### 3.7 Shipping (`carrier.`) — SRS §4.7, Spec §12.7

| Page type | Frame | Node |
|---|---|---|
| Overview / dashboard | Shipping overview | 106:15992 |
| Shipments & pickup requests | Shipping shipments & pickups | 106:16424 |
| COD reconciliation | Shipping COD reconciliation | 107:18628 |
| Claims & scorecard | Shipping claims & scorecard | 107:19067 |
| Agents (create, suspend, activate) | no frame · *pattern* List 13:2 | — |
| Supervisors (owner-only create) | no frame · *pattern* List 13:2 | — |
| Company settings, service zones, pricing ⚠ | **no frame** | — |
| Invoices, settlements, reports | no frame · *pattern* List / payout 98:4242 | — |
| Users & permissions, support | no frame · *pattern* List/Form | — |

### 3.8 Ops / supervisor (`carrier.` Ops)

| Page type | Frame | Node |
|---|---|---|
| Live dispatch (assign, reassign, unassigned) | Supervisor dispatch | 90:3664 |
| Failed deliveries (reschedule, RTO) | Supervisor failed deliveries | 90:3946 |
| Driver availability | no frame · *pattern* 90:3664 | — |
| Daily operations / COD monitoring / performance reports | **no frame** | — |

### 3.9 Delivery agent web (`delivery.`) — SRS §4.6

| Page type | Frame | Node |
|---|---|---|
| My day (dashboard, availability) | Delivery my day | 90:4155 |
| Earnings | Delivery earnings | 108:8009 |
| COD settlements | Delivery COD settlements | 108:8465 |
| Assignment detail with delivery code ("Codes match") — web | **no frame** (app: 82:707) | — |
| Wallet & withdrawals — web | no frame · *pattern* app 10 Wallet 82:909 | — |
| Profile (documents, vehicle, GPS) — web | no frame · *pattern* app 16 Profile & GPS 110:862 | — |

---

## 4. Apps (reference)

| App | Frames | Gaps |
|---|---|---|
| Customer v2 (Noon structure) | 54 screens `95:2`…`100:16592` + 9 components (`appV2`) | No frame: Food restaurant menu/detail, Minutes order-again toggle state (in 03?), live player full-screen in app (Nawy TV feed exists), special request create, ticket detail, travel booking flow in app, classified ad detail in app, property detail in app, vouchers, invoice view |
| Customer v1 | 18 + 3 AR (`appScreens.customer`) | Kept as v1 reference |
| Delivery | 17 screens (`appScreens.delivery`) incl. 05 Delivery code 82:707 (agent sees code), 06 Proof of delivery 82:735, 07 Can't deliver 82:760 | Sign-in method vs ADR 5 (PRD OQ3) |
| Ops minimal | 18 screens (`appScreens.opsMinimal`) incl. 00 Sign in & role switch 111:693 | Vendor click-wrap is only in v1 frame 82:957 |

---

## 5. Coverage summary

| Area | Rows listed | With a frame | Pattern only | No frame | N/A |
|---|---|---|---|---|---|
| Storefront (web) | 118 | 107 | 0 | 10 | 1 |
| Portal | 56 | 50 | 1 (5 policy pages) | 1 | 4 |
| Admin | 56 | 11 | 22 | 23 | 0 |
| Seller Center | 31 | 7 | 13 | 11 | 0 |
| Marketplace | 9 | 4 | 1 | 4 | 0 |
| Creator Studio | 21 | 5 | 8 | 8 | 0 |
| Broker | 7 | 3 | 3 | 1 | 0 |
| Travel Partner | 12 | 4 | 6 | 2 | 0 |
| Shipping | 9 | 4 | 4 | 1 | 0 |
| Ops | 4 | 2 | 1 | 1 | 0 |
| Delivery web | 6 | 3 | 2 | 1 | 0 |

(Counts are of table rows in §1–§3; a row may group several routes.)

---

## 6. Gap list — "no frame"

### 6.1 Storefront
1. Vertical landing on web (Super Mall / Food / Minutes `vertical_landing` template)
2. Search results — empty / no-results state
3. Product Q&A on web (app has 97:4183)
4. Product comparison `/compare` (FR-CAT-6)
5. Special request — create form
6. Public shared wishlist group page
7. Support ticket detail / new ticket
8. Nawy One membership page on web (app has 99:6173)
9. Travel inquiry form (not confirmed inside trip detail)
10. "Nawy Options" page (SRS §4.8)

### 6.2 Portal
11. Panel 2FA challenge page (optional 2FA, ADR 5)
12. *(pattern only)* Terms, Privacy, Return, Shipping, Refund static pages — reuse Static template About 100:4757 / Policy template 101:4971

### 6.3 Panels — complex pages (⚠) and pages with no usable pattern
**Admin**
13. AI dashboard
14. Product editor (variants, GTIN, per-country settings)
15. Category editor (commission, samples, min stock, contract switch, Marketers tab)
16. Flash-sale event management + live monitor
17. Dispute mediation thread
18. Return inspection (per-item outcome, restock)
19. Warranty claim thread
20. Vendor detail (strikes, hold, blacklist, locks, account manager)
21. Application review detail (documents, more-docs request)
22. Admins / roles & permissions editor
23. Marketer detail (rules, contracts, exclusive)
24. Cross-promotion policy editor
25. Ad campaigns / ad slots / ad bookings review (incl. admin video creative upload)
26. Delivery assignments + zones live map
27. Ledger (transaction groups)
28. Live studio (Go Live, comments moderation)
29. App contexts editor
30. Portal analytics (funnel)
31. Document types matrix
32. Onboarding-step builder
33. Notification templates
34. Support ticket thread
35. In-app documentation module

**Seller Center**
36. Size-guide editor
37. AI credits
38. Weight calculator
39. Exceptional zones opt-in (map)
40. Packaging supplies catalog + cart
41. Flash-sale submission
42. Self-serve ads campaign builder
43. Nawi ads browse + booking calendar
44. Legal / click-wrap consent page (web)
45. Performance (score, SLA, strikes)
46. Team & roles editor

**Marketplace (classified vendor)**
47. Dashboard
48. Conversations
49. Paid boosting / featured / renewal
50. Listing statistics / reports

**Creator Studio**
51. Register / onboarding (job types) / onboarding contract acceptance
52. Request a campaign, peer products, promoted by others
53. Campaign marketplace + applications
54. Customer conversations (chat)
55. Promote (ad slot booking) + ad packages
56. Content submission
57. Media library (browse/share)
58. Agency switcher / agency dashboard

**Broker**
59. Lead analytics / reports

**Travel Partner**
60. Booking modifications + optional services
61. Reports / performance

**Shipping**
62. Company settings, service zones, pricing

**Ops**
63. Daily operations / COD monitoring / performance reports

**Delivery web**
64. Assignment detail with delivery code ("Codes match") on web (app frame 82:707 exists)

### 6.4 Apps
65. Customer app: Food restaurant detail/menu; special request create; ticket detail; in-app travel booking flow; in-app classified ad detail and property detail; vouchers; invoice view.
