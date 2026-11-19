# Panel side menu — design spec & IA review

Owner request: grouped dropdown side menu with icons, links re-sorted into groups. Applies to all six panels
(Admin, Seller Center store + classified shop, Creator Studio (+ Broker), Travel Partner, Shipping, Delivery).
IA source of truth for code: `docs/project/panel-navigation.md` (code agent). This file = designer review of that IA
(section 1) + the visual/interaction handoff (sections 2–8) + Figma node ids (section 9).

Figma: file `d2MhBJmRddMrMSHfjJY8Y8`, page **Panel navigation** (`231:2`). Ledger key `panelNav` in
`docs/design/figma-state.json`.

---

## 1. IA review of `docs/project/panel-navigation.md` — recommendations for the code agent

Overall the task-oriented grouping, "Support last", most-specific-route active state, aggregated counts and the
accordion behaviour are right. Main problem: **Admin has 18 entries (16 groups)** — too many headers to scan, and
four groups have only 2 items. Rules I applied: groups of 3–7 links (2 only when the domain is truly distinct and
money/profile related), most-used first, group label ≤ 18 characters so it never truncates next to a count badge
and chevron in a 264 px sidebar (Inter Medium 14 ≈ 128 px label room with badge; same for Cairo).

### R1 — Admin: 16 groups → 13 groups (all 67 links kept, same routes/permissions)

| # | Entry | Icon | Links (in order) | Change vs code |
|---|---|---|---|---|
| 1 | Dashboard (link) | `layout-dashboard` | — | = |
| 2 | Approvals queue (link) | `clipboard-check` | — | = |
| 3 | **Orders** | `shopping-bag` | Orders, Returns, Refunds, Disputes, Warranty claims | renamed from "Orders & fulfilment" (fulfilment moves to #8 Logistics) |
| 4 | **Catalog** | `tag` | Catalog, Product requests, Reviews & Q&A | + Reviews & Q&A (product-content moderation; was a 2-item group) |
| 5 | **Sellers & partners** | `store` | Users & vendors, Applications, Profile change requests, Contracts, Document review, Join options | = |
| 6 | **Customers** | `users` | Customers, Support tickets, Nawy One | renamed (was "Customers & support" — truncates); − Reviews |
| 7 | **Finance** | `banknote` | Finance, Ledger, Payments under review, Vendor subscriptions, Acquisition commissions, My acquisition commissions | moved up (daily payouts work) |
| 8 | **Logistics** | `truck` | Inventory & fulfilment, Packaging, Delivery & logistics, Dispatch, Agent withdrawals | merges "Inventory & FBN" (2) + "Delivery" (3); "Fulfilment & delivery" (21 chars) would truncate |
| 9 | **Marketing** | `megaphone` | Ads review, Coupons, Coupon participation, Flash sales, Gift cards & vouchers, Advertise requests | renamed (was "Marketing & promotions" — 22 chars) |
| 10 | **Creators** | `sparkles` | Marketers, Marketer campaigns, Celebrity monthly report, Marketer settings | = |
| 11 | **Verticals** | `layout-grid` | Classifieds review, Abuse reports, Classified categories, Real estate, Travel packages, Travel promotions | merges "Open Souq & classifieds" (4) + "Travel" (2) |
| 12 | **Content** | `file-text` | Pages & page builder, Blog & help centre, Live & videos, App contexts, Notification templates | renamed (was "Content & pages") |
| 13 | **Reports & logs** | `bar-chart` | Cross-country reports, Portal analytics, Activity log, Email log | "Reports & analytics" (2) + audit logs |
| 14 | **Platform** | `globe` | Countries & gateways, Exchange rates, Domains, Platform settings, Staff, Roles & permissions | merges "Countries & settings" (4) + "Staff & security" (Staff, Roles) |
| 15 | **System & migration** | `server` | System health, Migration runs, Quarantine queue, Legacy records, Legacy redirects, Reconciliation | − Email log (→ #13) |

Arabic group labels used in the Figma AR screen: الطلبات، الكتالوج، البائعون والشركاء، العملاء، المالية، الخدمات اللوجستية،
التسويق، صنّاع المحتوى، القطاعات، المحتوى، التقارير والسجلات، المنصة، النظام والترحيل.

### R2 — Seller Center (store): merge "Inventory & FBN" (2) into **Catalog**
Catalog (`tag`): Products, Product requests, Inventory & warehouses, Packaging supplies, Reviews (5). Rest unchanged:
Dashboard · Orders (3) · Catalog (5) · Marketing (4) · Finance (4) · Store & team (5) · Support.

### R3 — Seller Center (classified shop): keep as is
Buyers (2) is acceptable — distinct job (answer buyers). Search box not needed (11 links).

### R4 — Creator Studio: merge "My products" (2) into "Promotion tools" → **Products & promotion** (`sparkles`)
Links: My products, Product requests, Cross-promotion, Flash sales, Advertising (5). Keep Campaigns (4), Broker (4,
brokers only), Earnings (2, money), Profile & contract (2, account). Order: Overview · Campaigns · Products &
promotion · Broker · Earnings · Profile & contract · Support.

### R5 — Travel Partner: 4 groups for 9 links is too fragmented → 3 groups
- **Trips & bookings** (`plane`): Packages & trips, Departures & capacity, Bookings, Inquiries (4)
- **Promotions & reports** (`megaphone`): Promotions, Promote a trip, Booking reports (3)
- **Agency & team** (`building`): Team & permissions, Change requests (2)
`ticket` icon no longer needed for Travel.

### R6 — Shipping: keep as is (Live dispatch top-level is right — most-used screen). Search: it has 13 links, so by
the ≥ 12 rule it should get the search box too (doc lists only admin/Seller/Creator).

### R7 — Delivery: **no groups** (6 links). Flat top-level list with icons: My day `sun`, Assignments
`clipboard-list`, COD cash & settlements `banknote`, Earnings `wallet`, Profile & vehicle `car`, Documents
`file-text`. Rule: panels with ≤ 8 links never use groups (a 2-link dropdown costs a click for nothing). No search.

### R8 — Interaction details (differences from the code doc)
- Chevron: `chevron-right` when closed, rotate **90°** to point down when open (150 ms); in RTL use the mirrored
  glyph (`rtl:-scale-x-100`) and rotate −90°. (Tree-nav convention; 180° flip reads like a select.)
- "Contains active" (active group collapsed by the user): **no accent bar on the group header** — the bar is reserved
  for the current page. Instead: group icon `#f9bb25`, label white, count badge switches to the brand tone.
- No "collapse all" control (accordion makes it redundant — owner rule).
- Group label match in search shows the whole group (as coded); highlight the matched substring (see §5).

---

## 2. Anatomy & dimensions

| Part | Spec |
|---|---|
| Sidebar (expanded) | width **264**, full height, bg `--color-sidebar` `#0d1526` (navy/900), 1 px inline-end border `#172238` (navy/800). Column: header (pinned) · search (pinned) · nav (scrolls, `overflow-y:auto`, thin scrollbar `#33425e`) · footer (pinned). |
| Icon rail | width **72**, padding-block 16/12, items centred, 4 px gap. Brand mark 32 · expand button 32 · 8 spacer · rail items · avatar 32 at bottom. |
| Mobile drawer (< 1024 px) | width **304** (max `calc(100vw - 56px)`), full height, shadow `8px 0 32px rgb(0 0 0 / .35)` (mirrored in RTL), scrim `#0d1526` at 60 %, header button = close `x`. Always full sidebar (no rail). |
| Header | padding 16 12 12, gap 12. Row 1 (padding-inline 4, gap 10): brand mark 32×32 r 8 bg `#f9bb25`, "N" Inter Bold 16/24 `#0d1526`; titles column = panel name `en/h4` Inter Semi Bold 16/24 `#fff` + subtitle `en/body-sm` 12/16 `#9aa8bf`; collapse-sidebar icon button 32×32 (`panel-left`). Row 2: workspace switcher 240×40, r 8, bg `#172238`, 1 px `#24314a` inside, padding-inline 10, gap 8: `globe` 16 `#9aa8bf` · label `en/body-medium` 14/20 Medium `#fff` (truncate) · `chevrons-up-down` 16 `#9aa8bf`. Admin = country ("All countries · Oman time"), Seller = store, Shipping = company. |
| Search | row padding 0 12 8. Field full width × 36, r 8, bg `#172238`, 1 px `#24314a`, padding-inline 10, gap 8: `search` 16 `#9aa8bf` · text `en/body` 14/20 (placeholder `#9aa8bf`, value `#fff`) · kbd chip h 20, min-w 20, padding-inline 6, r 4, 1 px `#33425e`, `en/caption` 11/14 `#9aa8bf` showing `/` (empty) or `Esc` (filled). Shown only for panels with ≥ 12 links. |
| Nav | padding 4 12 12 (8 top when no search), gap **2** between rows. Divider after top-level links and before bottom links: 1 px `#172238`, 8 px block margin, 10 px inline inset. |
| Top-level link (`Level=Top`) | 240 × **36**, r **8**, padding-inline-start 10 / end 8, gap 10: icon **20** · label `en/body-medium` Inter Medium 14/20 · count badge. |
| Group header | 240 × **36**, r 8, padding 10 / 8, gap 10: icon 20 · label Medium 14/20 (truncate, 1 line) · aggregated count badge (closed only) · chevron **16**. `<button aria-expanded aria-controls>`. |
| Child link (`Level=Child`) | 240 × **34**, r **6**, padding-inline-start **40** (label aligns with the group label: 10 + 20 + 10) / end 8; label `en/body` Inter Regular 14/20 (Medium when active) · count badge. No icon. Open group list: gap 2, padding-bottom 6. |
| Count badge | h 18, min-w 20, padding-inline 6, r 999, Inter **Semi Bold 11/14** (Cairo SemiBold 11/16), tabular nums. Attention (work waiting): bg `#f9bb25`, text `#0d1526`. Neutral (group sum while closed): bg `#24314a`, text `#e3e8f0`. Group that contains the active page → attention tone. Cap at "99+". |
| Footer | padding 12 16, gap 10, 1 px top border `#172238`: avatar 32 circle bg `#feedc0` (amber/100), initials Inter Semi Bold 13 `#0d1526` · name `en/body-medium` `#fff` + role `en/body-sm` `#9aa8bf` (truncate) · sign-out icon button 32 (`log-out`). |
| Icon button | 36 (32 in header/footer) square, r 8, 1 px `#24314a` inside, icon 18 `#c6cfdd`. Hover bg `#172238`, icon `#fff`. Focus ring (below). |
| Rail item | 48 × 40, r 8, icon 20. Dot (group has counts): 8 px `#f9bb25` circle, 2 px `#0d1526` outline, at top 7 / inline-end 8. |
| Flyout | 240 wide, r 12, bg `#172238`, 1 px `#24314a`, padding 6, gap 2, shadow `0 12px 32px rgb(2 10 26 / .45)`. Title row padding 8 10 6: group name `en/caption` 11/14 uppercase, letter-spacing .06em, `#9aa8bf` (Arabic: no uppercase/tracking). Links = child links with padding-inline-start 12; hover bg `#24314a` (one step lighter than the flyout bg). Placed 8 px from the rail edge, top aligned 6 px above the rail item, clamped to viewport. |
| Tooltip (rail, top-level links) | bg `#ffffff`, text `en/label` Inter Medium 13/16 `#0d1526`, padding 6 10, r 6, shadow `0 4px 12px rgb(13 20 38 / .24)`, 8 px from the rail, vertically centred on the item. Delay 300 ms in / 0 out. |

Spacing scale is the file's (`spacing/0_5`=2, 1=4, 2=8, 3=12, 4=16, 5=20, 6=24); radii `radius/sm`=6, `md`=8,
`lg`=12, `full`=999.

## 3. Colour & state tokens (sidebar stays dark navy in both light and dark page themes)

| Token / role | Hex | Figma variable | CSS (app.css) |
|---|---|---|---|
| Sidebar bg | `#0d1526` | `color/bg/sidebar` (navy/900) | `--color-sidebar` |
| Hover row / field bg / divider | `#172238` | `navy/800` | add `--color-sidebar-hover` |
| Active row bg, neutral badge bg, field border | `#24314a` | `color/bg/sidebar-active` (navy/700) | `--color-sidebar-active` |
| Kbd / scrollbar border | `#33425e` | `navy/600` | — |
| Child link text | `#c6cfdd` | `color/text/sidebar` (navy/200) | `--color-fg-sidebar` |
| Top-level / group label | `#e3e8f0` | `navy/100` | add `--color-fg-sidebar-strong` |
| Icons (default), placeholder, subtitles | `#9aa8bf` | `navy/300` | add `--color-fg-sidebar-muted` |
| Chevron (closed) | `#6b7c99` | `navy/400` | — |
| Active / hover / open label & icon on hover | `#ffffff` | `gray/0` | `white` |
| Active icon, accent bar, attention badge, ContainsActive icon | `#f9bb25` | `color/border/brand` (amber/400) | `--color-brand` |
| Search match highlight | `#fbcd58`, Semi Bold | `amber/300` | — |
| Focus ring | `#f9bb25` 2 px | `color/border/focus` (dark) | `--color-brand` |
| Disabled (only for a future "locked" use; no item is shown disabled today) | `#4a5a78` + `lock` 14 | `navy/500` | — |

State matrix:

| State | Row bg | Label | Icon | Extra |
|---|---|---|---|---|
| Default (top / group) | none | `#e3e8f0` Medium | `#9aa8bf` | chevron `#6b7c99` |
| Default (child) | none | `#c6cfdd` Regular | — | |
| Hover | `#172238` | `#fff` | `#fff` | chevron `#c6cfdd`, cursor pointer |
| Open (group) | none | `#fff` | `#fff` | chevron down `#c6cfdd`, count hidden |
| Active (link) | `#24314a` | `#fff` Medium | `#f9bb25` | 3 × 16 accent bar `#f9bb25` at inline-start edge, radius 0 2 2 0 (mirrored in RTL), vertically centred; `aria-current="page"` |
| ContainsActive (group closed) | none | `#fff` | `#f9bb25` | count badge brand tone; no accent bar |
| Focus-visible | unchanged | unchanged | unchanged | 2 px `#f9bb25` ring **inside** the row (`ring-2 ring-inset`), same radius |
| Pressed | `#24314a` | | | |

Contrast (WCAG 2.1 on `#0d1526`): `#c6cfdd` 11.3:1, `#e3e8f0` 14:1, `#9aa8bf` 7.4:1, `#fff` on `#24314a` 13:1,
`#0d1526` on `#f9bb25` 10.4:1, `#e3e8f0` on `#24314a` 10.6:1, chevron `#6b7c99` 4.1:1 (non-text ≥ 3), focus ring
10.4:1, placeholder `#9aa8bf` on `#172238` 6.5:1 — all AA.

## 4. Typography

| Use | EN (Inter) | AR (Cairo) |
|---|---|---|
| Panel name | `en/h4` Semi Bold 16/24 | `ar/h4` SemiBold 16/28 |
| Group / top-level label, workspace | `en/body-medium` Medium 14/20 | `ar/body-medium` Medium 14/23 |
| Child link | `en/body` Regular 14/20 (active: Medium) | `ar/body` Regular 14/23 (active: Medium) |
| Subtitle, role, result count | `en/body-sm` Regular 12/16 | `ar/body-sm` 12/18 |
| Badge | Semi Bold 11/14, tabular-nums | SemiBold 11/16 |
| Flyout title | `en/caption` Medium 11/14, uppercase, +6 % tracking | `ar/caption` 11/16, no case/tracking |
| Tooltip | `en/label` Medium 13/16 | `ar/label` 13/18 |

All labels single line, `text-overflow: ellipsis`, full label in `title`/tooltip when truncated.

## 5. Behaviour

- **Accordion (owner rule):** on load only the group containing the active page is open; all others closed.
  Opening a group closes the open one (one open at a time); the open group can be closed too. Open state is
  **not remembered** between pages. Without JS every group is open.
- **Motion:** group expand/collapse = height via `grid-template-rows: 0fr → 1fr` **200 ms** `cubic-bezier(.2,0,0,1)`;
  chevron rotate 150 ms; row hover bg 120 ms; rail ↔ expanded width 200 ms; flyout/tooltip fade+2 px slide 120 ms;
  drawer slide 220 ms + scrim fade 220 ms. All disabled under `prefers-reduced-motion: reduce`.
- **Search:** filters links by label (case- and Arabic-diacritic-insensitive), a group-label match shows the whole
  group; matching groups open temporarily (accordion suspended), others hidden; matched substring rendered
  `#fbcd58` Semi Bold; a result line "N results in M groups" (`en/body-sm` `#9aa8bf`) above the list; empty state
  "No pages match" in the same style. Enter opens the first match, Esc clears; clearing returns to "only the active
  group open". Debounce 0 (local filter).
- **Counts:** a group shows the sum of its links' counts only while closed (open → the links show their own).
- **Rail (desktop ≥ 1024 px):** toggle in the header (`panel-left`); remembered in `localStorage` `nawy.nav.rail`
  (the only remembered state). Top-level links → tooltip; groups → flyout on click / Enter / → (← in RTL) and on
  hover after 150 ms; Esc or outside click closes and returns focus to the rail item. The rail item of the active
  group uses the Active state (bg + accent bar + brand icon). Search hidden in rail mode.
- **Keyboard:** Tab enters the sidebar (search → first row); ↑/↓ move across visible rows; Home/End first/last;
  → opens a group (closing the other) or moves into it; ← closes it or returns to its header (mirrored in RTL);
  Enter/Space toggles a group; `/` focuses search from anywhere outside inputs; Esc clears search / closes flyout /
  closes drawer. Focus is always visible (ring above).
- **Mobile drawer:** opened by the topbar menu button; focus trapped; scrim click or Esc closes; focus returns to
  the menu button; body scroll locked.

## 6. RTL rules

Logical properties only (`ps`/`pe`, `start`/`end`, `border-e`, `inset-inline-start`). In Arabic: sidebar on the
right with its border on the inline-end (left) edge; child indent 40 on the right; accent bar on the right edge;
counts and chevrons on the left; closed chevron points left (mirrored glyph); flyout and tooltip open to the left of
the rail; drawer slides from the right; text right-aligned in Cairo. Icons are **not** mirrored except directional
ones (`chevron-*`, `log-out`, `panel-left`).

## 7. Icon set (24 × 24 grid, outline, round caps/joins; Lucide, ISC/MIT)

Figma components `Nav icon/<name>` are drawn with a 1.5 px stroke at 24 px and placed at **20 px** (nav rows,
rail), **16 px** (chevrons, field icons) and **18 px** (icon buttons) with the stroke kept at 1.5 px. In code that is
`stroke-width="1.75"` on the 24 viewBox at 20 px (as implemented) — keep it; at 16 px use `2`.

| Name | Meaning / used for |
|---|---|
| `layout-dashboard` | Dashboard / Overview (all panels) |
| `clipboard-check` | Approvals queue (admin) |
| `shopping-bag` | Orders group (admin, seller) |
| `tag` | Catalog (admin, seller) |
| `store` | Sellers & partners (admin), Store & team, Shop & account (seller) |
| `users` | Customers (admin), Drivers (shipping) |
| `banknote` | Finance (admin, seller), Money & claims (shipping), COD cash & settlements (delivery) |
| `truck` | Logistics (admin), Shipments (shipping) |
| `megaphone` | Marketing (admin, seller), Campaigns (creator), Promotions & reports (travel) |
| `sparkles` | Creators (admin), Products & promotion (creator) |
| `layout-grid` | Verticals (admin) |
| `file-text` | Content (admin), Documents (delivery) |
| `bar-chart` | Reports & logs (admin) |
| `globe` | Platform (admin); workspace/country switcher |
| `server` | System & migration (admin) |
| `layers` | Listings (classified shop) |
| `message-square` | Buyers (classified shop) |
| `briefcase` | Broker (creator) |
| `wallet` | Earnings (creator, delivery) |
| `user-circle` | Profile & contract (creator) |
| `plane` | Trips & bookings (travel) |
| `building` | Agency & team (travel), Company settings (shipping) |
| `route` | Live dispatch (shipping) |
| `sun` | My day (delivery) |
| `clipboard-list` | Assignments (delivery) |
| `car` | Profile & vehicle (delivery) |
| `life-buoy` | Support (all partner panels) |
| UI: `search`, `chevron-right`, `chevron-left`, `chevron-down`, `chevrons-up-down`, `panel-left`, `x`, `log-out`, `menu`, `lock` | controls |

Also in the Figma sheet (not used by the final IA): `inbox`, `shopping-cart`, `package`, `warehouse`, `ticket`,
`shield`, `shield-check`, `settings`, `database`, `link`, `user`, `calendar`, `chevrons-down-up`,
`panel-left-open`, `panel-left-close`.

## 8. Dark theme

The panel sidebar is already dark in both themes (variables `color/bg/sidebar` and `sidebar-active` resolve to the
same navy in Light and Dark modes), so the sidebar spec above is theme-independent. The page area follows the
existing light/dark tokens. Only difference: `color/border/focus` is amber/500 in Light and amber/400 in Dark — in
the sidebar always use `#f9bb25` (brand) for the focus ring.

## 9. Figma node ids (page `Panel navigation` = `231:2`)

Components (all on the "Nav components" board `232:2`):

| Component | Node | Variants / props |
|---|---|---|
| Nav icons sheet (40+14 `Nav icon/*` components) | `231:3` | e.g. `layout-dashboard` 231:11, `shopping-bag` 236:3249, `clipboard-check` 236:3256, `life-buoy` 236:3323 |
| Nav/Link | `232:152` | Level=Top\|Child × State=Default\|Hover\|Active\|Focus\|Disabled × Dir=LTR\|RTL; props Label, Count, Show count, Icon (swap) |
| Nav/Group header | `232:256` | State=Closed\|Open\|Hover\|Focus\|ContainsActive × Dir; props Label, Count, Show count, Icon |
| Nav/Search | `232:302` | State=Default\|Filled\|Focus × Dir; prop Value |
| Nav/Icon button | `232:315` | State=Default\|Hover\|Focus; prop Icon |
| Nav/Rail item | `232:344` | State=Default\|Hover\|Active\|Focus; props Icon, Show dot |
| Nav/Tooltip | `232:349` | Dir; prop Label |
| Nav/Header | `233:169` | Dir; props Panel, Subtitle, Workspace |
| Nav/Footer | `233:196` | Dir; props Initials, Name, Role |
| Nav/Flyout | `233:240` | Dir |
| Nav/Sidebar (container) | `239:3605` | Mode=Expanded, Dir=LTR\|RTL; Mode=Rail, Dir=LTR (drawer = Expanded at 304, see mobile screen) |

Screens (each = an existing "Panel layouts" frame cloned with the new sidebar):

| Screen | Frame | Sidebar |
|---|---|---|
| Admin — EN expanded | `238:3516` | `238:3245` |
| Admin — AR (RTL) expanded | `238:4073` | `238:3802` |
| Admin — search filtering ("report") | `238:4481` | `238:4367` |
| Admin — rail + flyout + tooltip | `238:4928` | `238:4767` |
| Admin — mobile drawer (375) | `238:5234` | `238:5247` |
| Seller Center (store) | `239:1750` | `239:1594` |
| Seller Center (classified shop) | `239:1980` | `239:1859` |
| Creator Studio (marketer + broker) | `239:2332` | `239:2176` |
| Travel Partner | `239:2546` | `239:2416` |
| Shipping (carrier / supervisor) | `239:2809` | `239:2640` |
| Delivery agent | `239:3110` | `239:2989` |

The screens show the IA **with the recommendations in §1 applied**.
