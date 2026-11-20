# Panel navigation (sidebar)

Source of truth for the IA: `backend/app/Domains/Panels/Navigation/PanelNavigation.php` (`PanelNavigation::structure()`
= the order below, `PanelNavigation::for()` = what one signed-in user sees). Visual and interaction spec:
`docs/design/panel-nav-design.md` (Figma file `d2MhBJmRddMrMSHfjJY8Y8`, page "Panel navigation" `231:2`); the IA below
includes the designer's recommendations R1–R8 of that spec.

Code: `resources/views/components/layouts/panel.blade.php` (sidebar container, header, workspace, footer, drawer
scrim) → `components/ui/sidebar-nav.blade.php` (search, groups) → `components/ui/sidebar-item.blade.php` (links);
`<x-icon name="…">` in `components/icon.blade.php`; behaviour in `resources/js/panel-sidebar.js`; styles = tokens in
`@theme` plus the "Panel sidebar" block at the end of `resources/css/app.css`.

## Model

- A panel sidebar is an ordered list of **entries**: **top-level links** (dashboard, a single high-traffic page,
  Support) and **groups** (accordion dropdowns with an icon, a label of at most 18 characters and 2–7 child links).
  A divider separates the top-level links from the groups and the groups from the bottom links.
- Groups are task-oriented: most-used work first, money and reports after, account / platform / system last. Panels
  with 8 links or fewer use no groups (Delivery is a flat list).
- Every top-level link and group has an outline icon (names in the tables); child links have none.
- **Permissions**: an item is shown only if the user passes every `can:` (admin permission, per country) and
  `tenant.can:` (team-role permission group, read) middleware of its route. A group whose items are all hidden is not
  rendered. Nothing is ever shown disabled.
- **Active**: the single most specific route matching the current page is active (on `admin.marketing.travel-promotions`
  only *Travel promotions*, not *Ads review*). Its group is marked `data-nav-active` and rendered open.
- **Counts** (work waiting): admin *Approvals queue* = listings pending review; Seller Center *Orders* = sub-orders in
  `placed`. Item badges use the attention tone (brand); a closed group shows the sum in the neutral tone (brand tone
  when it holds the current page); the rail shows a brand dot instead of a number.
- Labels are translated with `__()`; Arabic strings live in `backend/lang/ar.json`.

## Sidebar anatomy (all panels)

Header (brand mark, panel name, "Nawy", collapse-to-rail button on desktop / close button in the drawer) · workspace
row (Seller Center: store · country; other panels: country · currency; a country switcher when the user has several
countries) · search (panels with 12+ links: admin, Seller Center store, Creator Studio, Shipping) · scrolling nav ·
footer (initials avatar, name, role — admin: "Super admin" / "Admin staff", others: the panel name — and sign out).

## Behaviour

| Area | Behaviour |
|---|---|
| Group header | `<button aria-expanded aria-controls>`: icon 20, label, aggregated count (closed only), `chevron-right` 16 that turns 90° to point down when open (mirrored glyph, −90° in RTL). Enter / Space toggles. |
| Accordion | Only the group containing the current page is open on load; every other group is collapsed. Opening a group closes the open one (one open group at a time); the open group can also be closed. Open state is **not** remembered between pages. Without JavaScript every group is open. |
| Motion | Group height `grid-template-rows` 0fr → 1fr 200 ms `cubic-bezier(.2,0,0,1)`, chevron 150 ms, row hover 120 ms, rail width 200 ms, flyout / tooltip 120 ms fade + 2 px slide, drawer 220 ms. None with `prefers-reduced-motion`. Closed lists are `visibility: hidden` (not focusable). |
| Active link | Row bg `sidebar-active`, white label, brand icon, 3 × 16 px brand bar on the inline-start edge (right in RTL), `aria-current="page"`. |
| Contains active | The collapsed group of the current page: white label, brand icon, brand count badge — no accent bar (reserved for the page itself). |
| Search | Filters links by label (case- and Arabic-diacritic-insensitive; a group-label match shows the whole group), temporarily opens every matching group (accordion suspended), hides the rest and the dividers, highlights the matched text (amber/300, semi-bold) and shows "N results in M groups · Enter opens the first · Esc clears" or "No pages match". The key chip shows `/` (empty) or `Esc` (filled). Enter opens the first match, Esc clears; clearing returns to "only the active group open". |
| Keyboard | ↑ / ↓ move across visible rows (↓ from the search box enters the list, ↑ from the first row returns to it), Home / End first / last, → opens a group (closing the other) or moves into it, ← closes it or returns to its header (mirrored in RTL), `/` focuses the search from outside inputs, Esc clears search / closes the flyout / closes the drawer. Focus ring: 2 px brand, inset. |
| Icon rail (desktop ≥ 1024 px) | Header button collapses the sidebar to 72 px (the only remembered state: `localStorage` `nawy.nav.rail`, try/catch, applied before first paint). Rail items 48 × 40; the active group's item uses the Active state; groups with counts show a brand dot. Top-level links: white tooltip after 300 ms hover or on keyboard focus. Groups: flyout (title + links) on click / Enter / → (← in RTL) or after 150 ms hover; Esc or an outside click closes it and returns focus. Search, labels, workspace and footer text are hidden. |
| Mobile drawer (< 1024 px) | Opened by the topbar menu button: 304 px (max `100vw − 56px`), slides from the inline start, 60 % navy scrim, focus moves into the drawer and is trapped, body scroll locked; close button, scrim click or Esc closes and returns focus to the menu button. Always the full sidebar. |
| RTL | Logical properties only; the sidebar, accent bar, counts, chevrons, flyout, tooltip and drawer mirror in Arabic (Cairo). Directional icons (`chevron-right`, `panel-left`, `log-out`) are mirrored. |
| Theme | The sidebar stays dark navy in both page themes (`--color-sidebar*`, `--color-fg-sidebar*` tokens in `app.css`). |
| Truncation | Labels are single-line with an ellipsis; a cut-off label gets its full text as `title`. |

## Structure per panel

Permission column: `can:x` = admin permission; `tenant:x` = team-role permission group (read) of that panel; — = any
member of the panel.

### Admin (`admin`) — 2 links + 13 groups, 67 links

| # | Entry | Icon | Item | Route | Permission |
|---|---|---|---|---|---|
| 1 | **Dashboard** (link) | `layout-dashboard` | — | `admin.dashboard` | — |
| 2 | **Approvals queue** (link) | `clipboard-check` | — | `admin.approvals` | `can:vendor_listings.view` |
| 3 | **Orders** (group) | `shopping-bag` | Orders | `admin.orders` | `can:orders.view` |
|  |  |  | Returns | `admin.returns` | `can:returns.view` |
|  |  |  | Refunds | `admin.refunds` | `can:orders.refund` |
|  |  |  | Disputes | `admin.disputes` | `can:disputes.view` |
|  |  |  | Warranty claims | `admin.warranty-claims` | `can:warranty_claims.view` |
| 4 | **Catalog** (group) | `tag` | Catalog | `admin.catalog` | `can:categories.view` |
|  |  |  | Product requests | `admin.product-requests` | `can:product_requests.view` |
|  |  |  | Reviews & Q&A | `admin.reviews` | `can:reviews.view` |
| 5 | **Sellers & partners** (group) | `store` | Users & vendors | `admin.users` | `can:vendors.view` |
|  |  |  | Applications | `admin.applications` | `can:applications.view` |
|  |  |  | Profile change requests | `admin.change-requests` | `can:change_requests.view` |
|  |  |  | Contracts | `admin.contracts` | `can:contract_templates.view` |
|  |  |  | Document review | `admin.documents` | `can:documents.view` |
|  |  |  | Join options | `admin.join-options` | `can:join_options.configure` |
| 6 | **Customers** (group) | `users` | Customers | `admin.customers` | `can:customers.view` |
|  |  |  | Support tickets | `admin.support` | `can:tickets.view` |
|  |  |  | Nawy One | `admin.membership` | `can:membership.view` |
| 7 | **Finance** (group) | `banknote` | Finance | `admin.finance` | `can:payouts.view` |
|  |  |  | Ledger | `admin.finance.ledger` | `can:ledger.view` |
|  |  |  | Payments under review | `admin.finance.payments-review` | `can:payments.review` |
|  |  |  | Vendor subscriptions | `admin.finance.subscriptions` | `can:vendor_subscriptions.view` |
|  |  |  | Acquisition commissions | `admin.finance.acquisition-commissions` | `can:acquisition_commissions.view` |
|  |  |  | My acquisition commissions | `admin.finance.my-acquisition-commissions` | — |
| 8 | **Logistics** (group) | `truck` | Inventory & fulfilment | `admin.inventory` | `can:fbn.view` |
|  |  |  | Packaging | `admin.packaging` | `can:packaging.view` |
|  |  |  | Delivery & logistics | `admin.delivery` | `can:shipping_zones.view` |
|  |  |  | Dispatch | `admin.delivery.assignments` | `can:delivery_assignments.view` |
|  |  |  | Agent withdrawals | `admin.delivery.withdrawals` | `can:payouts.view` |
| 9 | **Marketing** (group) | `megaphone` | Ads review | `admin.marketing` | `can:ads.review` |
|  |  |  | Coupons | `admin.coupons` | `can:coupons.view` |
|  |  |  | Coupon participation | `admin.marketing.coupon-participation` | `can:coupon_participation.manage` |
|  |  |  | Flash sales | `admin.flash-sales` | `can:flash_sales.view` |
|  |  |  | Gift cards & vouchers | `admin.gift-cards` | `can:gift_cards.view` |
|  |  |  | Advertise requests | `admin.advertise-requests` | `can:advertise_inquiries.view` |
| 10 | **Creators** (group) | `sparkles` | Marketers | `admin.marketers` | `can:marketers.view` |
|  |  |  | Marketer campaigns | `admin.marketers.campaigns` | `can:marketer_campaigns.view` |
|  |  |  | Celebrity monthly report | `admin.marketers.celebrity-reports` | `can:celebrity_reports.view` |
|  |  |  | Marketer settings | `admin.marketers.settings` | `can:marketer_settings.configure` |
| 11 | **Verticals** (group) | `layout-grid` | Classifieds review | `admin.classifieds` | `can:classifieds.review` |
|  |  |  | Abuse reports | `admin.classifieds.reports` | `can:classifieds.review` |
|  |  |  | Classified categories | `admin.classifieds.categories` | `can:categories.view` |
|  |  |  | Real estate | `admin.real-estate` | `can:marketers.view` |
|  |  |  | Travel packages | `admin.travel` | `can:travel_packages.review` |
|  |  |  | Travel promotions | `admin.marketing.travel-promotions` | `can:travel_promotions.view` |
| 12 | **Content** (group) | `file-text` | Pages & page builder | `admin.pages` | `can:pages.view` |
|  |  |  | Blog & help centre | `admin.cms` | `can:blog.manage` |
|  |  |  | Live & videos | `admin.media` | `can:live_media.view` |
|  |  |  | App contexts | `admin.app-contexts` | `can:app_contexts.manage` |
|  |  |  | Notification templates | `admin.notification-templates` | `can:notification_templates.manage` |
| 13 | **Reports & logs** (group) | `bar-chart` | Cross-country reports | `admin.reports.cross-country` | `can:reports.view` |
|  |  |  | Portal analytics | `admin.portal-analytics` | `can:applications.view` |
|  |  |  | Activity log | `admin.activity` | `can:activity_log.view` |
|  |  |  | Email log | `admin.email-log` | `can:system_health.view` |
| 14 | **Platform** (group) | `globe` | Countries & gateways | `admin.countries` | `can:payment_gateways.view` |
|  |  |  | Exchange rates | `admin.countries.exchange-rates` | `can:exchange_rates.view` |
|  |  |  | Domains | `admin.countries.domains` | `can:domains.view` |
|  |  |  | Platform settings | `admin.settings` | — |
|  |  |  | Staff | `admin.staff` | `can:admins.view` |
|  |  |  | Roles & permissions | `admin.roles` | `can:roles.view` |
| 15 | **System & migration** (group) | `server` | System health | `admin.health` | `can:system_health.view` |
|  |  |  | Migration runs | `admin.migration.runs` | `can:data_migration.view` |
|  |  |  | Quarantine queue | `admin.migration.quarantine` | `can:data_migration.view` |
|  |  |  | Legacy records | `admin.migration.records` | `can:data_migration.view` |
|  |  |  | Legacy redirects | `admin.migration.redirects` | `can:data_migration.view` |
|  |  |  | Reconciliation | `admin.migration.reconciliation` | `can:data_migration.view` |

### Seller Center — store layout (`partner`, product vendors) — 23 links

| # | Entry | Icon | Item | Route | Permission |
|---|---|---|---|---|---|
| 1 | **Dashboard** (link) | `layout-dashboard` | — | `partner.dashboard` | — |
| 2 | **Orders** (group) | `shopping-bag` | Orders | `partner.orders` | `tenant:orders` |
|  |  |  | Returns | `partner.returns` | `tenant:returns` |
|  |  |  | Disputes | `partner.disputes` | `tenant:returns` |
| 3 | **Catalog** (group) | `tag` | Products | `partner.products` | `tenant:listings` |
|  |  |  | Product requests | `partner.product-requests` | `tenant:listings` |
|  |  |  | Inventory & warehouses | `partner.inventory` | `tenant:inventory` |
|  |  |  | Packaging supplies | `partner.packaging` | `tenant:inventory` |
|  |  |  | Reviews | `partner.reviews` | `tenant:reviews` |
| 4 | **Marketing** (group) | `megaphone` | Coupons | `partner.coupons` | `tenant:marketing` |
|  |  |  | Flash sales | `partner.flash-sales` | `tenant:marketing` |
|  |  |  | Marketer campaigns | `partner.campaigns` | `tenant:campaigns` |
|  |  |  | Advertising | `partner.advertising` | `tenant:marketing` |
| 5 | **Finance** (group) | `banknote` | Earnings | `partner.earnings` | `tenant:finance` |
|  |  |  | Statements & invoices | `partner.statements` | `tenant:finance` |
|  |  |  | Payouts | `partner.payouts` | `tenant:finance` |
|  |  |  | Subscription | `partner.subscription` | `tenant:finance` |
| 6 | **Store & team** (group) | `store` | Store settings | `partner.store` | `tenant:profile` |
|  |  |  | Team & permissions | `partner.team` | `tenant:team` |
|  |  |  | Change requests | `partner.change-requests` | `tenant:profile` |
|  |  |  | Contracts | `partner.contracts` | `tenant:legal` |
|  |  |  | Documents | `partner.documents` | `tenant:documents` |
| 7 | **Support** (link) | `life-buoy` | — | `partner.support` | `tenant:support` |

### Seller Center — classified shop layout (`partner`, vendors of type `classified`) — 11 links, no search

| # | Entry | Icon | Item | Route | Permission |
|---|---|---|---|---|---|
| 1 | **Dashboard** (link) | `layout-dashboard` | — | `partner.dashboard` | — |
| 2 | **Listings** (group) | `layers` | My listings | `partner.marketplace.ads` | `tenant:classifieds` |
|  |  |  | Post a listing | `partner.marketplace.ads.create` | `tenant:classifieds` |
|  |  |  | Ads & boosts | `partner.advertising` | `tenant:marketing` |
| 3 | **Buyers** (group) | `message-square` | Inquiries | `partner.marketplace.inquiries` | `tenant:classifieds` |
|  |  |  | Viewing & reserve requests | `partner.marketplace.requests` | `tenant:classifieds` |
| 4 | **Shop & account** (group) | `store` | Profile | `partner.store` | `tenant:profile` |
|  |  |  | Change requests | `partner.change-requests` | `tenant:profile` |
|  |  |  | Contracts | `partner.contracts` | `tenant:legal` |
|  |  |  | Documents | `partner.documents` | `tenant:documents` |
| 5 | **Support** (link) | `life-buoy` | — | `partner.support` | `tenant:support` |

### Creator Studio (`marketer`; the Broker group only for marketers with the `broker` job type)

| # | Entry | Icon | Item | Route | Permission |
|---|---|---|---|---|---|
| 1 | **Overview** (link) | `layout-dashboard` | — | `marketer.dashboard` | — |
| 2 | **Campaigns** (group) | `megaphone` | Campaign marketplace | `marketer.marketplace` | `tenant:campaigns` |
|  |  |  | Invitations | `marketer.invitations` | `tenant:campaigns` |
|  |  |  | Active campaigns | `marketer.campaigns` | `tenant:campaigns` |
|  |  |  | Links & coupons | `marketer.links` | `tenant:campaigns` |
| 3 | **Products & promotion** (group) | `sparkles` | My products | `marketer.products` | `tenant:studio` |
|  |  |  | Product requests | `marketer.product-requests` | `tenant:studio` |
|  |  |  | Cross-promotion | `marketer.cross-promotion` | `tenant:campaigns` |
|  |  |  | Flash sales | `marketer.flash-sales` | `tenant:campaigns` |
|  |  |  | Advertising | `marketer.advertising` | `tenant:campaigns` |
| 4 | **Broker** (group, brokers only) | `briefcase` | Leads pipeline | `marketer.broker.leads` | `tenant:broker` |
|  |  |  | Viewing & reserve requests | `marketer.broker.requests` | `tenant:broker` |
|  |  |  | Special requests | `marketer.broker.special-requests` | `tenant:broker` |
|  |  |  | Properties | `marketer.broker.properties` | `tenant:broker` |
| 5 | **Earnings** (group) | `wallet` | Wallet & withdrawals | `marketer.wallet` | `tenant:wallet` |
|  |  |  | Monthly reports | `marketer.reports` | `tenant:wallet` |
| 6 | **Profile & contract** (group) | `user-circle` | Profile & socials | `marketer.profile` | `tenant:profile` |
|  |  |  | Marketer contract | `marketer.contract` | `tenant:contract` |
| 7 | **Support** (link) | `life-buoy` | — | `marketer.support` | `tenant:support` |

### Travel Partner (`travel_agency`) — 11 links, no search

| # | Entry | Icon | Item | Route | Permission |
|---|---|---|---|---|---|
| 1 | **Dashboard** (link) | `layout-dashboard` | — | `travel_agency.dashboard` | — |
| 2 | **Trips & bookings** (group) | `plane` | Packages & trips | `travel_agency.packages` | `tenant:packages` |
|  |  |  | Departures & capacity | `travel_agency.departures` | `tenant:packages` |
|  |  |  | Bookings | `travel_agency.bookings` | `tenant:bookings` |
|  |  |  | Inquiries | `travel_agency.inquiries` | `tenant:inquiries` |
| 3 | **Promotions & reports** (group) | `megaphone` | Promotions | `travel_agency.promotions` | `tenant:promotions` |
|  |  |  | Promote a trip | `travel_agency.promotions.create` | `tenant:promotions` |
|  |  |  | Booking reports | `travel_agency.reports` | `tenant:reports` |
| 4 | **Agency & team** (group) | `building` | Team & permissions | `travel_agency.team` | `tenant:team` |
|  |  |  | Change requests | `travel_agency.change-requests` | `tenant:profile` |
| 5 | **Support** (link) | `life-buoy` | — | `travel_agency.support` | `tenant:support` |

### Shipping (carrier / supervisor) (`carrier`) — 13 links

| # | Entry | Icon | Item | Route | Permission |
|---|---|---|---|---|---|
| 1 | **Overview** (link) | `layout-dashboard` | — | `carrier.dashboard` | — |
| 2 | **Live dispatch** (link) | `route` | — | `carrier.dispatch` | `tenant:dispatch` |
| 3 | **Shipments** (group) | `truck` | Pickup requests | `carrier.pickups` | `tenant:dispatch` |
|  |  |  | Shipments | `carrier.shipments` | `tenant:dispatch` |
|  |  |  | Assignments | `carrier.assignments` | `tenant:dispatch` |
|  |  |  | Failed deliveries | `carrier.exceptions` | `tenant:dispatch` |
| 4 | **Drivers** (group) | `users` | Drivers & supervisors | `carrier.agents` | `tenant:agents` |
|  |  |  | Driver documents | `carrier.driver-documents` | `tenant:agents` |
| 5 | **Money & claims** (group) | `banknote` | COD & reconciliation | `carrier.cod` | `tenant:cod` |
|  |  |  | Agent withdrawals | `carrier.withdrawals` | `tenant:payouts` |
|  |  |  | Claims & scorecard | `carrier.claims` | `tenant:claims` |
| 6 | **Company settings** (link) | `building` | — | `carrier.company` | `tenant:company` |
| 7 | **Support** (link) | `life-buoy` | — | `carrier.support` | `tenant:support` |

### Delivery (driver) (`delivery`) — flat list of 6 links, no groups, no search

| # | Entry | Icon | Item | Route | Permission |
|---|---|---|---|---|---|
| 1 | **My day** (link) | `sun` | — | `delivery.dashboard` | — |
| 2 | **Assignments** (link) | `clipboard-list` | — | `delivery.assignments` | — |
| 3 | **COD cash & settlements** (link) | `banknote` | — | `delivery.cod` | — |
| 4 | **Earnings** (link) | `wallet` | — | `delivery.earnings` | — |
| 5 | **Profile & vehicle** (link) | `car` | — | `delivery.profile` | — |
| 6 | **Documents** (link) | `file-text` | — | `delivery.documents` | — |

## Icons

Outline, 24 × 24 viewBox, `stroke="currentColor"`, round caps/joins; 20 px rows and rail (stroke 1.75), 18 px icon
buttons, 16 px chevrons and field icons (stroke 2). Paths follow Lucide (ISC licence notice in
`backend/resources/views/components/icon.blade.php`). Names in use: `layout-dashboard`, `clipboard-check`,
`clipboard-list`, `shopping-bag`, `tag`, `store`, `users`, `user-circle`, `truck`, `route`, `megaphone`, `sparkles`,
`layout-grid`, `layers`, `message-square`, `plane`, `file-text`, `banknote`, `wallet`, `bar-chart`, `globe`,
`server`, `briefcase`, `building`, `life-buoy`, `sun`, `car`; UI: `chevron-right`, `chevron-down`, `chevrons-up-down`,
`search`, `x`, `panel-left`, `log-out`.

## Previous layout → new (for testers)

The set of links each user sees is unchanged from before the redesign (same 150 route entries, same permission
checks); only order, grouping and some group names changed. Old headings (*Overview, Commerce, Growth, Verticals,
Operations, Content, Platform, Data migration* in admin; *Main, Growth, Finance, Account* in partner panels …) no
longer exist as headings. Links in collapsed groups are in the DOM but not visible: browser specs click the group
header (`[data-nav-toggle]`, accessible name = group label) or type in the search box (`[data-nav-search]`, opens
every matching group) before clicking a link. The mobile drawer is `[data-panel-sidebar][data-drawer-open]`.
